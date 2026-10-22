"""Fabriques de documents SYNTHÉTIQUES pour les tests.

Aucune vraie pièce, aucun vrai nom : identités fictives, MRZ de démonstration ICAO 9303 (ERIKSSON / UTO),
SIREN et IBAN d'exemple publics. Ne jamais déposer ici un document réel.
"""

from __future__ import annotations

import io
from dataclasses import dataclass

import pytest
from PIL import Image, ImageDraw, ImageFont

from app.config import Settings

TOKEN = "test-token-0123456789abcdef"

# MRZ de démonstration ICAO 9303 (parties 3 et 4) — clés valides.
ICAO_TD3 = (
    "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\n"
    "L898902C36UTO7408122F1204159ZE184226B<<<<<10"
)
ICAO_TD1 = (
    "I<UTOD231458907<<<<<<<<<<<<<<<\n"
    "7408122F1204159UTO<<<<<<<<<<<6\n"
    "ERIKSSON<<ANNA<MARIA<<<<<<<<<<"
)
ICAO_TD2 = (
    "I<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<\n"
    "D231458907UTO7408122F1204159<<<<<<<6"
)
# Ancienne CNI française, entièrement fictive (nom ALOIS / prénom PAUL). Clé finale calculée sur LIGNE 1 + LIGNE 2.
FR_CNI = (
    "IDFRAALOIS<<<<<<<<<<<<<<<<<<<<075001\n"
    "1303753008192PAUL<<<<<<<<<<8001210M9"
)


@dataclass
class FakeContent:
    """Contenu déjà textualisé : les extracteurs se testent hors HTTP, hors PDF, hors Tesseract."""

    body: str = ""
    band: str = ""
    page: str = ""

    def text(self) -> str:
        return self.body

    def mrz_band_text(self) -> str:
        return self.band

    def mrz_page_text(self) -> str:
        return self.page


class FakeEngine:
    """Moteur OCR factice : renvoie un texte fixe, ou lève / bloque à la demande."""

    def __init__(self, text: str = "", error: Exception | None = None, gate=None) -> None:  # noqa: ANN001
        self.text = text
        self.error = error
        self.gate = gate
        self.calls = 0

    def read(self, image: Image.Image, *, timeout: float, mrz: bool = False) -> str:
        self.calls += 1
        if self.gate is not None:
            self.gate.wait(5)
        if self.error is not None:
            raise self.error
        return self.text

    def check(self) -> None:
        if self.error is not None:
            raise self.error


def make_text_pdf(lines: list[str]) -> bytes:
    """PDF minimal à couche texte (Helvetica, WinAnsi) — équivalent d'une facture « native »."""

    def escape(value: str) -> bytes:
        raw = value.encode("cp1252", "replace")
        return raw.replace(b"\\", b"\\\\").replace(b"(", b"\\(").replace(b")", b"\\)")

    stream = b"BT /F1 11 Tf 14 TL 50 780 Td\n"
    for line in lines:
        stream += b"(" + escape(line) + b") Tj T*\n"
    stream += b"ET"

    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R "
        b"/Resources << /Font << /F1 5 0 R >> >> >>",
        b"<< /Length " + str(len(stream)).encode() + b" >>\nstream\n" + stream + b"\nendstream",
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
    ]
    out = io.BytesIO()
    out.write(b"%PDF-1.4\n")
    offsets = []
    for number, body in enumerate(objects, start=1):
        offsets.append(out.tell())
        out.write(f"{number} 0 obj\n".encode() + body + b"\nendobj\n")
    xref = out.tell()
    out.write(f"xref\n0 {len(objects) + 1}\n".encode() + b"0000000000 65535 f \n")
    for offset in offsets:
        out.write(f"{offset:010d} 00000 n \n".encode())
    out.write(f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF".encode())
    return out.getvalue()


def make_text_image(lines: list[str], size: tuple[int, int] = (1600, 1000), font_size: int = 44) -> Image.Image:
    image = Image.new("RGB", size, "white")
    draw = ImageDraw.Draw(image)
    font = ImageFont.load_default(size=font_size)
    y = 40
    for line in lines:
        draw.text((50, y), line, fill="black", font=font)
        y += font_size + 18
    return image


def png_bytes(image: Image.Image) -> bytes:
    buffer = io.BytesIO()
    image.save(buffer, format="PNG")
    return buffer.getvalue()


def image_pdf_bytes(image: Image.Image) -> bytes:
    """PDF « scan » : une image, aucune couche texte."""
    buffer = io.BytesIO()
    image.save(buffer, format="PDF")
    return buffer.getvalue()


@pytest.fixture
def settings() -> Settings:
    return Settings(token=TOKEN, busy_wait_s=0.2)
