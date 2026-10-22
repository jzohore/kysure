from datetime import date

from app.extractors import kbis, rib

from .conftest import FakeContent

TODAY = date(2026, 3, 1)

RIB_TEXT = """RELEVÉ D'IDENTITÉ BANCAIRE
Titulaire du compte : MARTIN Camille
IBAN : FR14 2004 1010 0505 0001 3M02 606
BIC : PSSTFRPPSCE
"""


def test_rib_valid_iban_bic_and_holder() -> None:
    data = rib.extract(FakeContent(RIB_TEXT))

    assert data == {
        "iban": "FR1420041010050500013M02606",
        "bic": "PSSTFRPPSCE",
        "holder_name": "MARTIN Camille",
    }


def test_rib_iban_with_bad_check_key_is_not_a_data() -> None:
    data = rib.extract(FakeContent(RIB_TEXT.replace("606", "607")))

    assert "iban" not in data
    assert data["holder_name"] == "MARTIN Camille"


def test_rib_iban_followed_by_text_on_the_same_line() -> None:
    data = rib.extract(FakeContent("FR14 2004 1010 0505 0001 3M02 606 BIC PSSTFRPPSCE"))
    assert data["iban"] == "FR1420041010050500013M02606"


def test_rib_bic_needs_its_label() -> None:
    assert "bic" not in rib.extract(FakeContent("BIENVENUE\nPSSTFRPPSCE\nFR14 2004 1010 0505 0001 3M02 606"))


KBIS_TEXT = """Extrait Kbis
EXTRAIT D'IMMATRICULATION PRINCIPALE AU REGISTRE DU COMMERCE ET DES SOCIÉTÉS
à jour au 8 janvier 2026
IDENTIFICATION DE LA PERSONNE MORALE
Immatriculation au RCS, numéro 123 456 782 R.C.S. Lyon
Date d'immatriculation 27/01/2000
Dénomination ou raison sociale EXEMPLE CONSEIL Forme juridique Société par actions simplifiée
Capital social 10 000,00 Euros
"""


def test_kbis_company_siren_and_extract_date() -> None:
    data = kbis.extract(FakeContent(KBIS_TEXT), today=TODAY)

    assert data == {
        "company_name": "EXEMPLE CONSEIL",
        "registration_number": "123 456 782",
        "document_date": "2026-01-08",
    }


def test_kbis_value_on_next_line_and_issue_date() -> None:
    text = "Dénomination sociale\nEXEMPLE CONSEIL\nSIREN : 123456782\nDélivré le 15/02/2026"
    data = kbis.extract(FakeContent(text), today=TODAY)

    assert data["company_name"] == "EXEMPLE CONSEIL"
    assert data["registration_number"] == "123 456 782"
    assert data["document_date"] == "2026-02-15"


def test_kbis_siren_with_bad_luhn_key_is_dropped() -> None:
    data = kbis.extract(FakeContent(KBIS_TEXT.replace("123 456 782", "123 456 789")), today=TODAY)
    assert "registration_number" not in data


def test_kbis_registration_date_is_not_the_extract_date() -> None:
    text = "Dénomination sociale EXEMPLE CONSEIL\nDate d'immatriculation 27/01/2000"
    assert "document_date" not in kbis.extract(FakeContent(text), today=TODAY)
