from datetime import date

import pytest

from app.extractors.common import find_dates, iban_valid, luhn_valid, parse_date, pick_date

TODAY = date(2026, 3, 1)


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("03/01/2026", date(2026, 1, 3)),
        ("3.1.2026", date(2026, 1, 3)),
        ("03-01-2026", date(2026, 1, 3)),
        ("2026-01-03", date(2026, 1, 3)),
        ("3 janvier 2026", date(2026, 1, 3)),
        ("1er février 2026", date(2026, 2, 1)),
        ("15 AOÛT 2025", date(2025, 8, 15)),
        ("12 décembre 2025", date(2025, 12, 12)),
    ],
)
def test_parse_date_formats(raw: str, expected: date) -> None:
    assert parse_date(raw) == expected


@pytest.mark.parametrize("raw", ["31/02/2026", "03/01/26", "pas de date", "13/13/2026", "1/1/1800"])
def test_parse_date_rejects_impossible_or_ambiguous(raw: str) -> None:
    assert parse_date(raw) is None


def test_find_dates_returns_all_in_order() -> None:
    values = [match.value for match in find_dates("du 01/12/2025 au 31/12/2025, émis le 5 janvier 2026")]
    assert values == [date(2025, 12, 1), date(2025, 12, 31), date(2026, 1, 5)]


def test_pick_date_prefers_issue_date_over_due_date() -> None:
    text = "Facture n° 123 du 12/01/2026\nDate d'échéance : 11/02/2026"
    assert pick_date(text, positive=("facture du",), negative=("echeance",), today=TODAY) == date(2026, 1, 12)


def test_pick_date_drops_negative_context_even_if_alone() -> None:
    assert pick_date("Né le 12/01/1980", positive=(), negative=("ne le",), today=TODAY) is None


def test_pick_date_ignores_future_dates() -> None:
    assert pick_date("Facture du 12/01/2030", positive=("facture du",), negative=(), today=TODAY) is None


def test_pick_date_month_year_needs_a_hint() -> None:
    text = "Quittance de loyer\nmois de janvier 2026"
    with_hint = pick_date(text, positive=(), negative=(), today=TODAY, allow_month_year=("mois de",))
    assert with_hint == date(2026, 1, 31)
    assert pick_date(text, positive=(), negative=(), today=TODAY) is None


def test_luhn() -> None:
    assert luhn_valid("123456782")
    assert not luhn_valid("123456789")
    assert not luhn_valid("12345678A")


def test_iban() -> None:
    assert iban_valid("FR14 2004 1010 0505 0001 3M02 606")
    assert iban_valid("GB82WEST12345698765432")
    assert not iban_valid("FR14 2004 1010 0505 0001 3M02 607")  # clé fausse
    assert not iban_valid("FR14 2004 1010 0505 0001 3M02 60")  # trop court pour la France
