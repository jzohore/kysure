"""Moteur OCR (Tesseract) : prétraitement d'image, lecture bornée dans le temps."""

from __future__ import annotations

from typing import Protocol

import pytesseract
from PIL import Image, ImageOps

from .errors import ExtractionTimeout

_MIN_SIDE = 1800  # en dessous, Tesseract lit mal les petits caractères
_MAX_SIDE = 2800  # au-dessus, on ne gagne rien et on consomme de la mémoire
_MRZ_WHITELIST = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<"


class OcrEngine(Protocol):
    def read(self, image: Image.Image, *, timeout: float, mrz: bool = False) -> str: ...

    def check(self) -> None: ...


def prepare(image: Image.Image) -> Image.Image:
    """Niveaux de gris, contraste étiré, taille ramenée dans une plage où Tesseract est fiable."""
    gray = ImageOps.autocontrast(ImageOps.grayscale(image), cutoff=1)
    longest = max(gray.size)
    if longest < _MIN_SIDE or longest > _MAX_SIDE:
        ratio = (_MIN_SIDE if longest < _MIN_SIDE else _MAX_SIDE) / longest
        gray = gray.resize((round(gray.width * ratio), round(gray.height * ratio)), Image.Resampling.LANCZOS)
    return gray


def mrz_band(image: Image.Image) -> Image.Image:
    """Tiers inférieur de la page : c'est là que se trouve la MRZ d'une carte ou d'un passeport."""
    return image.crop((0, int(image.height * 0.62), image.width, image.height))


class TesseractEngine:
    def read(self, image: Image.Image, *, timeout: float, mrz: bool = False) -> str:
        if timeout <= 0:
            raise ExtractionTimeout()
        # --oem 1 : LSTM seul. psm 6 (bloc uniforme) pour la MRZ, psm 3 (automatique) pour une page.
        config = "--oem 1 --psm 6" if mrz else "--oem 1 --psm 3"
        if mrz:
            config += f" -c tessedit_char_whitelist={_MRZ_WHITELIST}"
        try:
            return pytesseract.image_to_string(prepare(image), lang="eng" if mrz else "fra", config=config, timeout=timeout)
        except RuntimeError as error:  # pytesseract lève RuntimeError('Tesseract process timeout')
            if "timeout" in str(error).lower():
                raise ExtractionTimeout() from None
            raise

    def check(self) -> None:
        """Lève si le binaire ou la langue française manquent (sert au /health)."""
        languages = pytesseract.get_languages()
        if "fra" not in languages:
            raise RuntimeError("langue tesseract « fra » absente")
