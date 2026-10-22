from datetime import date

from app.extractors import proof_of_address

from .conftest import FakeContent

TODAY = date(2026, 3, 1)

INVOICE = """ÉNERGIE DU MIDI SA
10 avenue de la République
31000 TOULOUSE
Facture n° 2026-0042 du 12/01/2026
Période de consommation du 01/12/2025 au 31/12/2025
Date d'échéance : 11/02/2026
Mme Camille MARTIN
12 rue des Lilas
75011 PARIS
Montant à payer : 84,20 EUR
"""


def test_invoice_gives_customer_holder_address_and_issue_date() -> None:
    data = proof_of_address.extract(FakeContent(INVOICE), today=TODAY)

    assert data["holder_name"] == "Mme Camille MARTIN"
    # L'adresse de l'émetteur (au-dessus) n'est pas celle du client.
    assert data["address"] == "12 rue des Lilas 75011 PARIS"
    # Ni l'échéance, ni la période de consommation.
    assert data["document_date"] == "2026-01-12"


def test_address_on_a_single_line() -> None:
    text = "Titulaire : MARTIN Camille\n12 rue des Lilas 75011 Paris\nFacture du 05/02/2026"
    data = proof_of_address.extract(FakeContent(text), today=TODAY)

    assert data["holder_name"] == "MARTIN Camille"
    assert data["address"] == "12 rue des Lilas 75011 Paris"
    assert data["document_date"] == "2026-02-05"


def test_rent_receipt_with_only_a_month_uses_end_of_month() -> None:
    text = "QUITTANCE DE LOYER\nmois de janvier 2026\nM. Paul DURAND\n3 bis impasse des Roses\nBâtiment B\n69003 LYON"
    data = proof_of_address.extract(FakeContent(text), today=TODAY)

    assert data["document_date"] == "2026-01-31"
    assert data["address"] == "3 bis impasse des Roses Bâtiment B 69003 LYON"


def test_no_date_when_only_due_date_or_future_dates() -> None:
    text = "Facture\nDate d'échéance : 11/02/2026\nÀ régler avant le 30/12/2030"
    assert "document_date" not in proof_of_address.extract(FakeContent(text), today=TODAY)


def test_greetings_are_not_mistaken_for_a_holder() -> None:
    text = "Madame, Monsieur,\nNous vous informons de la hausse de votre abonnement.\nFacture du 05/02/2026"
    assert "holder_name" not in proof_of_address.extract(FakeContent(text), today=TODAY)


def test_empty_text_gives_nothing() -> None:
    assert proof_of_address.extract(FakeContent(""), today=TODAY) == {}
