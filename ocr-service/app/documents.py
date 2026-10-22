"""Chargement d'un document en mémoire : PDF à couche texte (sans OCR) ou image (OCR Tesseract).

Rien n'est écrit sur disque : le fichier reste en mémoire pendant la requête.
"""

from __future__ import annotations

import io
import math
import re
import time
from functools import cached_property

import pypdfium2 as pdfium
from PIL import Image, ImageOps

from .config import Settings
from .errors import ExtractionTimeout, UnreadableDocument
from .ocr import OcrEngine, mrz_band

# En dessous, la « couche texte » d'un PDF n'est qu'un filigrane / une en-tête : on lit l'image.
_MIN_NATIVE_CHARS = 40
_PDF_POINTS_PER_INCH = 72


def sniff_kind(data: bytes) -> str | None:
    if data.startswith(b"%PDF-"):
        return "pdf"
    if data.startswith(b"\xff\xd8\xff"):
        return "image"
    if data.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image"
    return None


class LoadedDocument:
    """Contenu d'un document, lu à la demande : texte natif d'abord, OCR seulement si nécessaire."""

    def __init__(self, data: bytes, settings: Settings, engine: OcrEngine) -> None:
        kind = sniff_kind(data)
        if kind is None:
            raise UnreadableDocument()
        self._data = data
        self._kind = kind
        self._settings = settings
        self._engine = engine
        self._deadline = time.monotonic() + settings.request_timeout_s
        self._pdf: pdfium.PdfDocument | None = None

        if kind == "pdf":
            try:
                self._pdf = pdfium.PdfDocument(data)
                self._page_count = min(len(self._pdf), settings.max_pages)
            except pdfium.PdfiumError:
                raise UnreadableDocument() from None
            if self._page_count == 0:
                raise UnreadableDocument()

    # -- Texte ---------------------------------------------------------------------------------

    @cached_property
    def native_text(self) -> str:
        """Texte de la couche texte du PDF (vide pour un scan ou une image)."""
        if self._pdf is None:
            return ""
        parts: list[str] = []
        for index in range(self._page_count):
            page = self._pdf[index]
            textpage = page.get_textpage()
            parts.append(textpage.get_text_range())
            textpage.close()
            page.close()
        return "\n".join(parts)

    @property
    def is_native(self) -> bool:
        return len(re.sub(r"\s+", "", self.native_text)) >= _MIN_NATIVE_CHARS

    @cached_property
    def _ocr_text(self) -> str:
        return "\n".join(self._engine.read(image, timeout=self._remaining()) for image in self._rendered_images)

    def text(self) -> str:
        return self.native_text if self.is_native else self._ocr_text

    def mrz_band_text(self) -> str:
        """Relecture de la seule bande MRZ (liste blanche de caractères) — utile pour une photo de pièce."""
        if self.is_native:
            return ""
        return "\n".join(
            self._engine.read(mrz_band(image), timeout=self._remaining(), mrz=True) for image in self._rendered_images
        )

    def mrz_page_text(self) -> str:
        """Relecture de la page ENTIÈRE en liste blanche MRZ : recto et verso empilés sur une même photo placent la
        bande au milieu, pas en bas. Plus lent : n'est tenté qu'en dernier recours."""
        if self.is_native:
            return ""
        return "\n".join(self._engine.read(image, timeout=self._remaining(), mrz=True) for image in self._rendered_images)

    # -- Images --------------------------------------------------------------------------------

    def _remaining(self) -> float:
        left = self._deadline - time.monotonic()
        if left <= 0:
            raise ExtractionTimeout()
        return min(self._settings.ocr_timeout_s, left)

    @cached_property
    def _rendered_images(self) -> list[Image.Image]:
        if self._pdf is not None:
            return [self._render_page(index) for index in range(self._page_count)]
        return [self._open_image()]

    def _open_image(self) -> Image.Image:
        try:
            image = Image.open(io.BytesIO(self._data))
            if image.width * image.height > self._settings.max_pixels:
                raise UnreadableDocument()
            image.load()
        except (OSError, Image.DecompressionBombError, Image.DecompressionBombWarning, ValueError):
            raise UnreadableDocument() from None
        # Niveaux de gris tout de suite : on ne garde pas en mémoire une photo couleur de 12 Mpx.
        return ImageOps.grayscale(ImageOps.exif_transpose(image) or image)

    def _render_page(self, index: int) -> Image.Image:
        assert self._pdf is not None
        # Niveaux de gris dès le rendu : 3× moins de mémoire qu'en RGB, et Tesseract n'a que faire de la couleur.
        return _render(self._pdf, index, self._settings, grayscale=True)


def _render(pdf: pdfium.PdfDocument, index: int, settings: Settings, *, grayscale: bool) -> Image.Image:
    page = pdf[index]
    try:
        width, height = page.get_size()
        scale = settings.render_dpi / _PDF_POINTS_PER_INCH
        # Page géante (affiche) : on borne les pixels plutôt que de saturer la mémoire.
        max_scale = math.sqrt(settings.max_pixels / max(width * height, 1))
        return page.render(scale=min(scale, max_scale), grayscale=grayscale).to_pil()
    finally:
        page.close()


_JPEG_QUALITY = 85


def render_pdf_as_jpeg(data: bytes, settings: Settings) -> list[bytes]:
    """Pages d'un PDF en JPEG couleur, pour un moteur OCR tiers (Textract) qui n'accepte que des images.

    Fait ici, dans un conteneur isolé (pdfium, sans réseau ni secrets), plutôt que par Ghostscript dans le
    worker PHP : un PDF déposé par un client n'est jamais interprété là où vivent les identifiants.
    """
    if sniff_kind(data) != "pdf":
        raise UnreadableDocument()
    try:
        pdf = pdfium.PdfDocument(data)
    except pdfium.PdfiumError:
        raise UnreadableDocument() from None
    try:
        count = min(len(pdf), settings.max_pages)
        if count == 0:
            raise UnreadableDocument()
        pages: list[bytes] = []
        for index in range(count):
            buffer = io.BytesIO()
            # Couleur conservée : la photo d'une pièce d'identité en a besoin.
            _render(pdf, index, settings, grayscale=False).convert("RGB").save(buffer, format="JPEG", quality=_JPEG_QUALITY)
            pages.append(buffer.getvalue())
        return pages
    finally:
        pdf.close()
