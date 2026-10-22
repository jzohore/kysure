import logging
import threading

import pytest
from fastapi.testclient import TestClient

from app.config import Settings
from app.errors import ExtractionTimeout
from app.main import create_app

from .conftest import (
    ICAO_TD3,
    TOKEN,
    FakeEngine,
    image_pdf_bytes,
    make_text_image,
    make_text_pdf,
)

AUTH = {"Authorization": f"Bearer {TOKEN}"}

INVOICE_LINES = [
    "Facture n° 2026-0042 du 12/01/2026",
    "Mme Camille MARTIN",
    "12 rue des Lilas",
    "75011 PARIS",
]


def client_for(settings: Settings, engine: FakeEngine | None = None) -> TestClient:
    return TestClient(create_app(settings, engine or FakeEngine()))


def post(client: TestClient, content: bytes, doc_type: str = "proof_of_address", headers: dict | None = None):  # noqa: ANN201
    return client.post(
        "/extract",
        data={"type": doc_type},
        files={"file": ("document", content, "application/octet-stream")},
        headers=AUTH if headers is None else headers,
    )


def test_health_ok_and_unauthenticated(settings: Settings) -> None:
    response = client_for(settings).get("/health")
    assert response.status_code == 200
    assert response.json() == {"status": "ok"}


def test_health_unavailable_when_tesseract_is_missing(settings: Settings) -> None:
    client = client_for(settings, FakeEngine(error=RuntimeError("tesseract absent")))
    assert client.get("/health").status_code == 503


@pytest.mark.parametrize("headers", [{}, {"Authorization": "Bearer wrong-token-0123456789"}, {"Authorization": TOKEN}])
def test_extract_requires_the_bearer_token(settings: Settings, headers: dict) -> None:
    response = post(client_for(settings), make_text_pdf(INVOICE_LINES), headers=headers)
    assert response.status_code == 401


def test_native_pdf_is_read_without_ocr(settings: Settings) -> None:
    engine = FakeEngine(text="ne doit jamais servir")
    response = post(client_for(settings, engine), make_text_pdf(INVOICE_LINES))

    assert response.status_code == 200
    body = response.json()
    assert body["holder_name"] == "Mme Camille MARTIN"
    assert body["address"] == "12 rue des Lilas 75011 PARIS"
    assert body["document_date"] == "2026-01-12"
    assert engine.calls == 0  # PDF natif : zéro OCR


def test_scanned_pdf_goes_through_the_ocr_engine(settings: Settings) -> None:
    engine = FakeEngine(text="\n".join(INVOICE_LINES))
    response = post(client_for(settings, engine), image_pdf_bytes(make_text_image(INVOICE_LINES)))

    assert response.status_code == 200
    assert response.json()["holder_name"] == "Mme Camille MARTIN"
    assert engine.calls >= 1


def test_identity_photo_reads_the_mrz_band(settings: Settings) -> None:
    engine = FakeEngine(text=ICAO_TD3)
    response = post(client_for(settings, engine), image_pdf_bytes(make_text_image(["CARTE"])), "passport")

    assert response.status_code == 200
    assert response.json()["last_name"] == "ERIKSSON"
    assert response.json()["mrz"]["line_2"].startswith("L898902C36")


def test_unreadable_content_with_no_field_is_an_empty_200(settings: Settings) -> None:
    response = post(client_for(settings, FakeEngine(text="rien d'exploitable")), image_pdf_bytes(make_text_image(["x"])))
    assert response.status_code == 200
    assert response.json() == {}


def test_unsupported_type_is_422(settings: Settings) -> None:
    response = post(client_for(settings), make_text_pdf(INVOICE_LINES), "source_of_funds")
    assert response.status_code == 422
    assert response.json() == {"error": "unsupported_type"}


@pytest.mark.parametrize("content", [b"ceci n'est pas un document", b"%PDF-1.4 tronque", b""])
def test_not_a_document_is_422(settings: Settings, content: bytes) -> None:
    response = post(client_for(settings), content)
    assert response.status_code == 422
    assert response.json() == {"error": "unreadable_document"}


def test_file_over_the_limit_is_413(settings: Settings) -> None:
    small = Settings(token=TOKEN, max_file_bytes=1024)
    response = post(client_for(small), b"%PDF-" + b"0" * 2048)
    assert response.status_code == 413


def test_declared_oversized_body_is_refused_before_parsing(settings: Settings) -> None:
    small = Settings(token=TOKEN, max_file_bytes=1024)
    response = post(client_for(small), b"%PDF-" + b"0" * 40_000)
    assert response.status_code == 413
    assert response.json() == {"error": "file_too_large"}


def test_ocr_timeout_is_a_retryable_504(settings: Settings) -> None:
    engine = FakeEngine(error=ExtractionTimeout())
    response = post(client_for(settings, engine), image_pdf_bytes(make_text_image(["x"])))
    assert response.status_code == 504


def test_unexpected_engine_failure_is_a_generic_500(settings: Settings) -> None:
    engine = FakeEngine(error=RuntimeError("MARTIN Camille 12 rue des Lilas"))
    response = post(client_for(settings, engine), image_pdf_bytes(make_text_image(["x"])))

    assert response.status_code == 500
    assert response.json() == {"error": "internal_error"}  # le message de l'exception ne fuit pas


def test_second_request_gets_503_while_the_slot_is_busy(settings: Settings) -> None:
    gate = threading.Event()
    engine = FakeEngine(text="rien", gate=gate)
    client = client_for(settings, engine)
    scan = image_pdf_bytes(make_text_image(["x"]))

    first: dict = {}
    worker = threading.Thread(target=lambda: first.update(response=post(client, scan)))
    worker.start()
    try:
        while engine.calls == 0:
            threading.Event().wait(0.01)
        assert post(client, scan).status_code == 503
    finally:
        gate.set()
        worker.join(5)
    assert first["response"].status_code == 200


def test_no_document_content_is_ever_logged(settings: Settings, caplog: pytest.LogCaptureFixture) -> None:
    caplog.set_level(logging.DEBUG)
    post(client_for(settings), make_text_pdf(INVOICE_LINES))
    post(client_for(settings, FakeEngine(error=RuntimeError("MARTIN Camille"))), image_pdf_bytes(make_text_image(["x"])))

    logged = "\n".join(record.getMessage() for record in caplog.records)
    assert "extract type=proof_of_address status=200" in logged
    for fragment in ("MARTIN", "Lilas", "75011", "2026-01-12", "12/01/2026"):
        assert fragment not in logged


def test_service_refuses_to_start_without_a_strong_token() -> None:
    with pytest.raises(RuntimeError):
        Settings.from_env({})
    with pytest.raises(RuntimeError):
        Settings.from_env({"OCR_SERVICE_TOKEN": "court"})
    assert Settings.from_env({"OCR_SERVICE_TOKEN": TOKEN}).token == TOKEN


# --- /render : PDF → JPEG pour Textract -----------------------------------------------------------


def render(client: TestClient, content: bytes, headers: dict | None = None):  # noqa: ANN201
    return client.post(
        "/render",
        files={"file": ("document", content, "application/octet-stream")},
        headers=AUTH if headers is None else headers,
    )


def test_render_requires_the_bearer_token(settings: Settings) -> None:
    assert render(client_for(settings), make_text_pdf(INVOICE_LINES), headers={}).status_code == 401


def test_render_turns_a_pdf_into_colour_jpeg_pages(settings: Settings) -> None:
    import base64
    import io

    from PIL import Image

    engine = FakeEngine()
    response = render(client_for(settings, engine), image_pdf_bytes(make_text_image(["CARTE"])))

    assert response.status_code == 200
    pages = response.json()["pages"]
    assert len(pages) == 1
    image = Image.open(io.BytesIO(base64.b64decode(pages[0])))
    assert image.format == "JPEG"
    assert image.mode == "RGB"  # la photo d'une pièce d'identité garde sa couleur
    assert engine.calls == 0  # rendu seulement : aucune lecture du contenu


def test_render_reads_at_most_the_first_pages(settings: Settings) -> None:
    import io

    pages = [make_text_image([f"page {n}"], (400, 560), 20) for n in range(4)]
    buffer = io.BytesIO()
    pages[0].save(buffer, format="PDF", save_all=True, append_images=pages[1:])

    response = render(client_for(settings), buffer.getvalue())

    assert response.status_code == 200
    assert len(response.json()["pages"]) == settings.max_pages


@pytest.mark.parametrize("content", [b"%PDF-1.4 tronque", b"\x89PNG\r\n\x1a\nxxxx", b""])
def test_render_refuses_anything_but_a_readable_pdf(settings: Settings, content: bytes) -> None:
    response = render(client_for(settings), content)
    assert response.status_code == 422
    assert response.json() == {"error": "unreadable_document"}


def test_unknown_routes_are_protected_too(settings: Settings) -> None:
    assert client_for(settings).post("/autre", headers={}).status_code == 401
