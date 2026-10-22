"""Configuration du service, lue une seule fois depuis l'environnement."""

from __future__ import annotations

import os
from collections.abc import Mapping
from dataclasses import dataclass

_MIN_TOKEN_LENGTH = 16


@dataclass(frozen=True)
class Settings:
    token: str
    # Taille max d'un fichier reçu (octets). Le worker PHP envoie des pièces déjà passées à ClamAV.
    max_file_bytes: int = 10 * 1024 * 1024
    # Seules les premières pages sont lues (recto/verso d'une pièce, 1re page d'un justificatif).
    max_pages: int = 2
    # Garde-fou « bombe de décompression » : pixels max d'une image / d'une page rastérisée.
    max_pixels: int = 40_000_000
    # Résolution de rastérisation des PDF scannés : 200 dpi suffit à Tesseract et tient dans ~512 Mo
    # (mesuré : un scan A4 dense à 250 dpi montait à 444 Mo de mémoire anonyme).
    render_dpi: int = 200
    # Budget total d'une requête, et plafond par appel Tesseract.
    request_timeout_s: float = 45.0
    ocr_timeout_s: float = 25.0
    # Une seule extraction à la fois : borne la mémoire du conteneur (≈ 512 Mo).
    max_concurrency: int = 1
    busy_wait_s: float = 5.0

    @classmethod
    def from_env(cls, environ: Mapping[str, str] | None = None) -> Settings:
        env = os.environ if environ is None else environ
        token = env.get("OCR_SERVICE_TOKEN", "")
        if len(token) < _MIN_TOKEN_LENGTH:
            # Fail-closed : un service sans jeton solide ne démarre pas.
            raise RuntimeError(
                f"OCR_SERVICE_TOKEN absent ou trop court (minimum {_MIN_TOKEN_LENGTH} caractères)."
            )
        return cls(
            token=token,
            max_file_bytes=int(env.get("OCR_MAX_FILE_BYTES", cls.max_file_bytes)),
            max_pages=int(env.get("OCR_MAX_PAGES", cls.max_pages)),
            request_timeout_s=float(env.get("OCR_REQUEST_TIMEOUT", cls.request_timeout_s)),
            ocr_timeout_s=float(env.get("OCR_PAGE_TIMEOUT", cls.ocr_timeout_s)),
        )
