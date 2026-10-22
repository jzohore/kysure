from datetime import date

from app.extractors import identity

from .conftest import FR_CNI, ICAO_TD1, ICAO_TD3, FakeContent

TODAY = date(2026, 10, 19)


def test_valid_mrz_in_text_layer_gives_the_normalized_contract() -> None:
    data = identity.extract(FakeContent(f"PASSEPORT\n{ICAO_TD3}"), today=TODAY)

    assert data["last_name"] == "ERIKSSON"
    assert data["first_name"] == "ANNA MARIA"
    assert data["birth_date"] == "1974-08-12"
    assert data["date_of_expiry"] == "2012-04-15"
    assert data["id_number"] == "L898902C3"
    assert data["mrz"] == {
        "line_1": "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<",
        "line_2": "L898902C36UTO7408122F1204159ZE184226B<<<<<10",
    }


def test_mrz_is_read_from_the_dedicated_band_when_full_page_ocr_misses_it() -> None:
    data = identity.extract(FakeContent("bruit sans MRZ lisible", band=ICAO_TD1), today=TODAY)

    assert data["mrz"]["line_3"] == "ERIKSSON<<ANNA<MARIA<<<<<<<<<<"  # type: ignore[index]
    assert data["last_name"] == "ERIKSSON"


def test_invalid_mrz_is_neither_exposed_nor_used() -> None:
    tampered = ICAO_TD3.replace("L898902C36", "L898902C37")  # clé du n° de document fausse
    data = identity.extract(FakeContent(tampered), today=TODAY)

    assert "mrz" not in data
    assert "id_number" not in data
    assert "last_name" not in data


def test_legacy_french_card_takes_expiry_from_the_visual_zone() -> None:
    text = f"CARTE NATIONALE D'IDENTITE\nValable jusqu'au : 14.03.2031\n{FR_CNI}"
    data = identity.extract(FakeContent(text), today=TODAY)

    assert data["last_name"] == "ALOIS"
    assert data["first_name"] == "PAUL"
    assert data["birth_date"] == "1980-01-21"
    assert data["date_of_expiry"] == "2031-03-14"


def test_visual_fallback_without_mrz_never_invents_an_mrz() -> None:
    text = "NOM : MARTIN\nPRÉNOM(S) : CAMILLE\nNé(e) le 21.01.1980\nDate d'expiration 14 mars 2031"
    data = identity.extract(FakeContent(text), today=TODAY)

    assert data == {
        "last_name": "MARTIN",
        "first_name": "CAMILLE",
        "birth_date": "1980-01-21",
        "date_of_expiry": "2031-03-14",
    }


def test_visual_label_value_on_next_line_and_bilingual_labels() -> None:
    text = "Nom / Surname\nMARTIN\nPrénoms / Given names\nCAMILLE JEANNE"
    data = identity.extract(FakeContent(text), today=TODAY)

    assert data["last_name"] == "MARTIN"
    assert data["first_name"] == "CAMILLE JEANNE"


def test_unreadable_document_returns_nothing() -> None:
    assert identity.extract(FakeContent("%%%% ### ///"), today=TODAY) == {}


def test_stacked_front_and_back_photo_finds_the_mrz_in_the_middle_of_the_page() -> None:
    # Recto + verso sur une même image : la bande du bas est celle du verso (pas de MRZ).
    data = identity.extract(FakeContent("bruit", band="ADRESSE 2 RUE", page=f"bruit\n{FR_CNI}\nverso"), today=TODAY)

    assert data["last_name"] == "ALOIS"
    assert "mrz" in data


def test_the_costly_full_page_rescan_is_skipped_once_the_band_gave_the_mrz() -> None:
    class Spy(FakeContent):
        page_reads = 0

        def mrz_page_text(self) -> str:
            Spy.page_reads += 1
            return ""

    identity.extract(Spy("bruit", band=FR_CNI), today=TODAY)
    assert Spy.page_reads == 0
