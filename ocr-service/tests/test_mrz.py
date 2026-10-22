from datetime import date

from app.extractors.mrz import check_digit, find_mrz

from .conftest import FR_CNI, ICAO_TD1, ICAO_TD2, ICAO_TD3

TODAY = date(2026, 10, 19)


def test_check_digit_matches_icao_examples() -> None:
    assert check_digit("L898902C3") == 6
    assert check_digit("740812") == 2
    assert check_digit("120415") == 9


def test_td3_passport() -> None:
    result = find_mrz(ICAO_TD3, today=TODAY)
    assert result is not None
    assert result.format == "TD3"
    assert (result.last_name, result.first_name) == ("ERIKSSON", "ANNA MARIA")
    assert result.document_number == "L898902C3"
    assert result.birth_date == date(1974, 8, 12)
    assert result.expiry_date == date(2012, 4, 15)


def test_td1_card_returns_three_lines() -> None:
    result = find_mrz(ICAO_TD1, today=TODAY)
    assert result is not None
    assert result.format == "TD1"
    data = result.as_data()
    assert data["mrz"] == {
        "line_1": "I<UTOD231458907<<<<<<<<<<<<<<<",
        "line_2": "7408122F1204159UTO<<<<<<<<<<<6",
        "line_3": "ERIKSSON<<ANNA<MARIA<<<<<<<<<<",
    }
    assert data["id_number"] == "D23145890"


def test_td2_card() -> None:
    result = find_mrz(ICAO_TD2, today=TODAY)
    assert result is not None
    assert result.format == "TD2"
    assert result.last_name == "ERIKSSON"


def test_french_legacy_id_card_has_no_expiry() -> None:
    result = find_mrz(FR_CNI, today=TODAY)
    assert result is not None
    assert result.format == "FR_CNI"
    assert (result.last_name, result.first_name, result.document_number) == ("ALOIS", "PAUL", "130375300819")
    assert result.birth_date == date(1980, 1, 21)
    assert result.expiry_date is None


def test_invalid_check_digit_is_never_returned() -> None:
    # Date de naissance altérée (740812 → 740813) : la clé ne correspond plus.
    tampered = ICAO_TD3.replace("L898902C36UTO740812", "L898902C36UTO740813")
    assert find_mrz(tampered, today=TODAY) is None


def test_invalid_composite_key_is_never_returned() -> None:
    tampered = ICAO_TD3[:-1] + "1"
    assert find_mrz(tampered, today=TODAY) is None


def test_names_have_no_check_digit_of_their_own() -> None:
    # Limite connue de l'ICAO : seules les zones numériques sont protégées. Le validateur PHP recoupe les
    # noms avec la zone visuelle et avec le dossier ; ici on documente simplement le comportement.
    result = find_mrz(ICAO_TD3.replace("ERIKSSON", "ERIKSSAN"), today=TODAY)
    assert result is not None
    assert result.last_name == "ERIKSSAN"


def test_ocr_letter_digit_confusions_are_repaired_when_keys_confirm() -> None:
    # « 7408122 » lu « 74O8l22 » ; « 1204159 » lu « 12O4I59 » : positions imposées numériques.
    noisy = ICAO_TD3.replace("7408122F1204159", "74O8I22F12O4I59")
    result = find_mrz(noisy, today=TODAY)
    assert result is not None
    assert result.birth_date == date(1974, 8, 12)


def test_digits_misread_inside_a_name_become_letters() -> None:
    result = find_mrz(ICAO_TD3.replace("ERIKSSON", "ERIKSS0N"), today=TODAY)
    assert result is not None
    assert result.last_name == "ERIKSSON"


def test_tolerates_noise_spaces_and_guillemets() -> None:
    noisy = ICAO_TD1.replace("<", "«").replace("D231", "D 231")
    result = find_mrz("REPUBLIQUE FRANCAISE\nCARTE NATIONALE D'IDENTITE\n" + noisy + "\nfin", today=TODAY)
    assert result is not None
    assert result.format == "TD1"


def test_perdre_la_cle_finale_est_fatal_mais_pas_le_bourrage_des_noms() -> None:
    lines = ICAO_TD3.splitlines()
    # Perdre la clé finale d'une ligne contrôlée : MRZ invalide.
    assert find_mrz(f"{lines[0]}\n{lines[1][:-1]}", today=TODAY) is None
    # Perdre des « < » de bourrage sur la ligne des noms : sans incidence (aucune clé ne les protège).
    assert find_mrz(f"{lines[0][:-9]}\n{lines[1]}", today=TODAY) is not None


def test_french_card_survives_lost_filler_on_the_name_line() -> None:
    # Cas réel Tesseract : « ALOIS<<<<…075001 » perd la moitié de ses « < ».
    lost = "IDFRAALOIS<<<<<<<<<<<<<<075001"
    result = find_mrz(f"{lost}\n{FR_CNI.splitlines()[1]}", today=TODAY)
    assert result is not None
    assert result.last_name == "ALOIS"


def test_td1_document_number_overflow() -> None:
    # Numéro de 12 caractères : 9 dans le champ, « < » à la place de la clé, suite + clé en zone facultative.
    number = "AB1234567890"
    key = str(check_digit(number))
    line1 = ("IDFRA" + number[:9] + "<" + number[9:] + key).ljust(30, "<")
    dob, expiry = "800121", "300101"
    body = dob + str(check_digit(dob)) + "M" + expiry + str(check_digit(expiry)) + "FRA" + "<" * 11
    composite = line1[5:30] + body[0:7] + body[8:15] + body[18:29]
    line2 = body + str(check_digit(composite))
    line3 = "MARTIN<<CAMILLE".ljust(30, "<")
    result = find_mrz(f"{line1}\n{line2}\n{line3}", today=TODAY)
    assert result is not None
    assert result.document_number == number
    assert result.expiry_date == date(2030, 1, 1)


def test_no_mrz_in_ordinary_text() -> None:
    assert find_mrz("Facture du 12/01/2026\nTotal 120,00 EUR", today=TODAY) is None


def test_glyphs_misread_for_a_chevron_are_normalized() -> None:
    # Cas réel Tesseract sur une ancienne CNI : le « 0 » du code émetteur (zone sans clé) lu « ( ».
    line1, line2 = FR_CNI.splitlines()
    result = find_mrz(f"{line1.replace('<075001', '<(75001')}\n{line2}", today=TODAY)
    assert result is not None
    assert result.last_name == "ALOIS"


def test_a_chevron_inside_a_date_is_never_accepted() -> None:
    # « < » et « 0 » pèsent tous deux 0 dans la clé ICAO : sans contrôle de format, « 8<0121 » passerait.
    line1, line2 = FR_CNI.splitlines()
    assert find_mrz(f"{line1}\n{line2.replace('800121', '8<0121')}", today=TODAY) is None
    td3_1, td3_2 = ICAO_TD3.splitlines()
    assert find_mrz(f"{td3_1}\n{td3_2.replace('740812', '74<812')}", today=TODAY) is None


def test_a_parenthesis_read_for_a_zero_is_repaired_when_the_keys_confirm() -> None:
    line1, line2 = FR_CNI.splitlines()
    result = find_mrz(f"{line1}\n{line2.replace('800121', '8(0121')}", today=TODAY)
    assert result is not None
    assert result.birth_date == date(1980, 1, 21)


def test_french_card_final_key_covers_the_name_line() -> None:
    # Particularité de l'ancienne CNI : un nom mal lu fait échouer la clé finale (il est protégé).
    line1, line2 = FR_CNI.splitlines()
    assert find_mrz(f"{line1.replace('ALOIS', 'ALOJS')}\n{line2}", today=TODAY) is None


def test_french_card_with_lost_fillers_on_both_lines_and_a_misread_office_code() -> None:
    # Cas réel Tesseract : « < » de bourrage perdus sur les deux lignes, « 0 » du code émetteur lu « ( ».
    line1, line2 = FR_CNI.splitlines()
    noisy1 = "IDFRAALOIS<<<<<<<<<<<<<<(75001"
    noisy2 = line2.replace("PAUL<<<<<<<<<<", "PAUL<<<<<<<")
    result = find_mrz(f"{noisy1}\n{noisy2}", today=TODAY)
    assert result is not None
    assert (result.last_name, result.first_name) == ("ALOIS", "PAUL")
    assert result.lines == (line1, line2)  # lignes restituées à l'identique de la pièce


def test_french_card_whose_name_line_filler_is_misread_as_letters() -> None:
    # Cas réel (structure relevée sur une vraie CNI, valeurs fictives) : 3 chevrons perdus, « < » lus « KK »,
    # et le « 0 » du code émetteur lu « O ».
    line1, line2 = FR_CNI.splitlines()
    noisy1 = "IDFRAALOIS<<<<<<<<<<KK<<<O75001"
    result = find_mrz(f"{noisy1}\n{line2}", today=TODAY)
    assert result is not None
    assert result.last_name == "ALOIS"
    assert result.lines == (line1, line2)


def test_compound_french_surname_is_rebuilt_and_checked() -> None:
    l1 = "IDFRA" + "DE<GAULLE".ljust(25, "<") + "075001"
    number = "130375300819"
    l2 = number + str(check_digit(number)) + "CHARLES".ljust(14, "<") + "800121" + str(check_digit("800121")) + "M"
    l2 += str(check_digit(l1 + l2))
    result = find_mrz(f"IDFRADE<GAULLE<<<<<<KC<<<<075001\n{l2}", today=TODAY)
    assert result is not None
    assert result.last_name == "DE GAULLE"


def test_a_wrong_surname_never_passes_even_with_rebuilt_filler() -> None:
    _, line2 = FR_CNI.splitlines()
    assert find_mrz(f"IDFRAALOJS<<<<<<<<<<KK<<<075001\n{line2}", today=TODAY) is None
