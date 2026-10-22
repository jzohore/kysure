import shutil

import pytest

from app.config import Settings
from app.documents import LoadedDocument, sniff_kind
from app.errors import ExtractionTimeout, UnreadableDocument
from app.ocr import TesseractEngine

from .conftest import FakeEngine, image_pdf_bytes, make_text_image, make_text_pdf, png_bytes

LINES = ["Facture du 12/01/2026", "Mme Camille MARTIN", "12 rue des Lilas", "75011 PARIS"]


def test_sniff_kind() -> None:
    assert sniff_kind(make_text_pdf(["x"])) == "pdf"
    assert sniff_kind(png_bytes(make_text_image(["x"], (50, 50), 10))) == "image"
    assert sniff_kind(b"\xff\xd8\xff\xe0abc") == "image"
    assert sniff_kind(b"MZ\x90\x00") is None


def test_native_pdf_text_is_used_and_accents_survive(settings: Settings) -> None:
    engine = FakeEngine()
    document = LoadedDocument(make_text_pdf(["Établi le 12 février 2026", *LINES]), settings, engine)

    assert document.is_native
    assert "Établi le 12 février 2026" in document.text()
    assert engine.calls == 0


def test_pdf_without_text_layer_falls_back_to_ocr(settings: Settings) -> None:
    engine = FakeEngine(text="texte océrisé")
    document = LoadedDocument(image_pdf_bytes(make_text_image(LINES)), settings, engine)

    assert not document.is_native
    assert document.text() == "texte océrisé"


def test_corrupted_pdf_is_unreadable(settings: Settings) -> None:
    with pytest.raises(UnreadableDocument):
        LoadedDocument(b"%PDF-1.4\nnot really", settings, FakeEngine())


def test_image_bomb_is_refused() -> None:
    settings = Settings(token="x" * 20, max_pixels=1000)
    with pytest.raises(UnreadableDocument):
        LoadedDocument(png_bytes(make_text_image(["x"], (200, 200), 10)), settings, FakeEngine()).text()


def test_exhausted_budget_stops_ocr() -> None:
    settings = Settings(token="x" * 20, request_timeout_s=0.0)
    document = LoadedDocument(png_bytes(make_text_image(["x"], (200, 200), 10)), settings, FakeEngine())
    with pytest.raises(ExtractionTimeout):
        document.text()


@pytest.mark.skipif(shutil.which("tesseract") is None, reason="tesseract absent (exécuté dans l'image Docker)")
def test_real_tesseract_reads_a_synthetic_scan(settings: Settings) -> None:
    image = make_text_image(["Facture du 12/01/2026", "Madame Camille MARTIN", "12 rue des Lilas", "75011 PARIS"])
    document = LoadedDocument(png_bytes(image), settings, TesseractEngine())

    text = document.text()
    assert "MARTIN" in text
    assert "75011" in text
    assert "12/01/2026" in text
    assert TesseractEngine().check() is None


@pytest.mark.skipif(shutil.which("tesseract") is None, reason="tesseract absent (exécuté dans l'image Docker)")
def test_real_tesseract_finds_the_mrz_of_a_stacked_front_and_back_photo(settings: Settings) -> None:
    from datetime import date

    from app.extractors import identity

    from .conftest import FR_CNI

    # Synthétique : MRZ au milieu (fin du recto), verso en dessous sans MRZ.
    lines = ["REPUBLIQUE FRANCAISE", "", "", *FR_CNI.splitlines(), "", "", "", "", "", "", "Adresse : 12 RUE DES LILAS", "75011 PARIS", "", "", ""]
    document = LoadedDocument(png_bytes(make_text_image(lines, (1600, 1400), 40)), settings, TesseractEngine())

    data = identity.extract(document, today=date(2026, 10, 22))

    assert data.get("last_name") == "ALOIS"
    assert data.get("birth_date") == "1980-01-21"
