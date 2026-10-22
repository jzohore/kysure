"""API HTTP du service d'extraction : GET /health, POST /extract.

Contrat (voir docs/specs/ocr-des-pieces-deposees.md § 2 bis) :
- 200 : JSON normalisé (mêmes clés que les providers PHP actuels), clés vides omises ;
- 401 : jeton absent / invalide ; 413 : fichier trop gros ; 422 : type non pris en charge ou document illisible ;
- 503 / 504 / 5xx : échec technique, réessayable.

Confidentialité : le contenu d'une pièce n'est jamais journalisé ni renvoyé dans une erreur. Seuls le type,
la taille, le statut et la durée le sont.

Démarrage : `uvicorn app.main:create_app --factory`.
"""

from __future__ import annotations

import base64
import hmac
import logging
import threading
import time
from typing import Any

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse
from starlette.types import ASGIApp, Message, Receive, Scope, Send

from .config import Settings
from .documents import LoadedDocument, render_pdf_as_jpeg
from .errors import ExtractionError, FileTooLarge, ServiceBusy, UnsupportedType
from .extractors import EXTRACTORS, run
from .ocr import OcrEngine, TesseractEngine

logger = logging.getLogger("ocr")

# Tout exige le jeton, sauf le healthcheck (fail-closed : une nouvelle route est protégée d'office).
_PUBLIC_PATHS = frozenset({"/health"})

# Marge pour l'enveloppe multipart (frontières, en-têtes, champ `type`) au-dessus de la taille du fichier.
_MULTIPART_OVERHEAD = 16 * 1024


class _GuardMiddleware:
    """Refuse AVANT de lire le corps : jeton invalide (401) et corps trop volumineux (413).

    Fait au niveau ASGI pour ne jamais laisser un appelant non authentifié faire parser un gros upload.
    """

    def __init__(self, app: ASGIApp, settings: Settings) -> None:
        self.app = app
        self._token = settings.token.encode()
        self._max_body = settings.max_file_bytes + _MULTIPART_OVERHEAD

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http" or scope["path"] in _PUBLIC_PATHS:
            await self.app(scope, receive, send)
            return

        headers = {key.decode().lower(): value.decode() for key, value in scope["headers"]}
        if not self._authorized(headers.get("authorization", "")):
            await _respond(send, 401, "unauthorized")
            return

        declared = headers.get("content-length")
        if declared is not None and declared.isdigit() and int(declared) > self._max_body:
            await _respond(send, 413, FileTooLarge.code)
            return

        received = 0
        too_large = False
        response_started = False

        async def limited_receive() -> Message:
            nonlocal received, too_large
            message = await receive()
            if message["type"] == "http.request":
                received += len(message.get("body", b""))
                if received > self._max_body:
                    # Corps tronqué : le parseur multipart échouera, et on répond 413 ci-dessous.
                    too_large = True
                    return {"type": "http.request", "body": b"", "more_body": False}
            return message

        async def guarded_send(message: Message) -> None:
            nonlocal response_started
            if too_large:
                if not response_started:
                    response_started = True
                    await _respond(send, 413, FileTooLarge.code)
                return
            await send(message)

        try:
            await self.app(scope, limited_receive, guarded_send)
        except Exception:
            # Corps tronqué = parseur multipart en échec : c'est un 413, pas un 500.
            if too_large and not response_started:
                await _respond(send, 413, FileTooLarge.code)
                return
            raise

    def _authorized(self, header: str) -> bool:
        scheme, _, value = header.partition(" ")
        if scheme.lower() != "bearer" or not value:
            return False
        return hmac.compare_digest(value.strip().encode(), self._token)


async def _respond(send: Send, status: int, code: str) -> None:
    body = ('{"error":"%s"}' % code).encode()
    await send(
        {
            "type": "http.response.start",
            "status": status,
            "headers": [(b"content-type", b"application/json"), (b"content-length", str(len(body)).encode())],
        }
    )
    await send({"type": "http.response.body", "body": body})


def create_app(settings: Settings | None = None, engine: OcrEngine | None = None) -> FastAPI:
    if not logging.getLogger().handlers:
        logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")
    settings = settings or Settings.from_env()
    engine = engine or TesseractEngine()
    slots = threading.BoundedSemaphore(settings.max_concurrency)

    app = FastAPI(title="KYSURE OCR service", docs_url=None, redoc_url=None, openapi_url=None)
    app.add_middleware(_GuardMiddleware, settings=settings)

    @app.get("/health")
    def health() -> JSONResponse:
        try:
            engine.check()
        except Exception:  # noqa: BLE001 — l'état de santé ne doit jamais lever
            return JSONResponse({"status": "unavailable"}, status_code=503)
        return JSONResponse({"status": "ok"})

    @app.post("/extract")
    def extract(document_type: str = Form(..., alias="type"), file: UploadFile = File(...)) -> JSONResponse:
        started = time.monotonic()
        # Valeur reçue du réseau : on ne journalise qu'un type connu.
        safe_type = document_type if document_type in EXTRACTORS else "unknown"
        size = 0
        status = 500
        native: bool | None = None
        try:
            if document_type not in EXTRACTORS:
                raise UnsupportedType()

            data = file.file.read(settings.max_file_bytes + 1)
            size = len(data)
            if size > settings.max_file_bytes:
                raise FileTooLarge()

            if not slots.acquire(timeout=settings.busy_wait_s):
                raise ServiceBusy()
            try:
                document = LoadedDocument(data, settings, engine)
                result: dict[str, Any] = run(document_type, document)
                native = document.is_native
            finally:
                slots.release()

            status = 200
            return JSONResponse(result)
        except ExtractionError as error:
            status = error.status_code
            return JSONResponse({"error": error.code}, status_code=status)
        except Exception as error:  # noqa: BLE001
            # Jamais le message ni la trace : ils pourraient contenir un fragment de la pièce.
            logger.error("extraction failed type=%s error=%s", safe_type, error.__class__.__name__)
            return JSONResponse({"error": "internal_error"}, status_code=500)
        finally:
            logger.info(
                "extract type=%s status=%d size=%d native=%s duration_ms=%d",
                safe_type,
                status,
                size,
                native,
                int((time.monotonic() - started) * 1000),
            )

    @app.post("/render")
    def render(file: UploadFile = File(...)) -> JSONResponse:
        """PDF → pages JPEG (base64), pour Textract. Aucune lecture du contenu : pas d'OCR, rien n'est extrait."""
        started = time.monotonic()
        size = 0
        status = 500
        pages = 0
        try:
            data = file.file.read(settings.max_file_bytes + 1)
            size = len(data)
            if size > settings.max_file_bytes:
                raise FileTooLarge()

            if not slots.acquire(timeout=settings.busy_wait_s):
                raise ServiceBusy()
            try:
                images = render_pdf_as_jpeg(data, settings)
            finally:
                slots.release()

            pages = len(images)
            status = 200
            return JSONResponse({"pages": [base64.b64encode(image).decode("ascii") for image in images]})
        except ExtractionError as error:
            status = error.status_code
            return JSONResponse({"error": error.code}, status_code=status)
        except Exception as error:  # noqa: BLE001
            logger.error("render failed error=%s", error.__class__.__name__)
            return JSONResponse({"error": "internal_error"}, status_code=500)
        finally:
            logger.info(
                "render status=%d size=%d pages=%d duration_ms=%d",
                status,
                size,
                pages,
                int((time.monotonic() - started) * 1000),
            )

    return app
