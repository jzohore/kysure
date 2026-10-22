"""Justificatif de domicile (facture, quittance, avis) : titulaire, adresse du client, date du document."""

from __future__ import annotations

import re
from datetime import date
from typing import Protocol

from .common import clean_line, lines_of, normalize_text, pick_date
from .holder import find_holder

_STREET_TYPES = (
    "rue|avenue|av\\.?|boulevard|bd\\.?|chemin|impasse|allée|allee|place|route|quai|cours|square|résidence|residence"
    "|villa|passage|cité|cite|sentier|ruelle|lotissement|hameau|esplanade|mail|promenade|faubourg|parvis|voie"
    "|lieu-dit|domaine|zone"
)
_STREET = rf"\d{{1,4}}(?:\s?(?:bis|ter|quater))?[,\s]+(?:{_STREET_TYPES})\b[^\n]*"
_POSTAL = r"(?P<cp>\d{5})\s+(?P<city>[A-ZÀ-Ý][A-Za-zÀ-ÿ' \-]{1,50})"

_SAME_LINE = re.compile(rf"^(?P<street>{_STREET}?)\s+{_POSTAL}$", re.IGNORECASE)
_STREET_LINE = re.compile(rf"^{_STREET}$", re.IGNORECASE)
_POSTAL_LINE = re.compile(rf"^{_POSTAL}$")

_DATE_POSITIVE = (
    "date de facture", "facture du", "facture n", "date d'emission", "emis le", "emise le", "edite le", "etabli le",
    "fait le", "date du document", "quittance du", "date :", "date:", "avis du",
)  # fmt: skip
_DATE_NEGATIVE = (
    "echeance", "limite", "avant le", "au plus tard", "prelevement", "naissance", " ne le", " nee le", "periode",
    "consommation", "releve", "index", "valable", "jusqu", "souscri", "depuis", "paiement", "reglement",
)  # fmt: skip
_MONTH_YEAR_HINTS = ("mois de", "quittance de loyer", "loyer de")


class TextContent(Protocol):
    def text(self) -> str: ...


def extract(content: TextContent, *, today: date | None = None) -> dict[str, object]:
    text = normalize_text(content.text())
    lines = lines_of(text)
    data: dict[str, object] = {}

    holder = find_holder(lines)
    if holder:
        data["holder_name"] = holder[0]

    address = _find_address(lines, after=holder[1] if holder else -1)
    if address:
        data["address"] = address

    document_date = pick_date(
        text,
        positive=_DATE_POSITIVE,
        negative=_DATE_NEGATIVE,
        today=today,
        allow_month_year=_MONTH_YEAR_HINTS,
    )
    if document_date:
        data["document_date"] = document_date.isoformat()
    return data


def _find_address(lines: list[str], *, after: int) -> str | None:
    """Adresse du **client** : la première adresse postale qui suit le titulaire (celle de l'émetteur est au-dessus)."""
    found: list[tuple[int, str]] = []
    for index, line in enumerate(lines):
        same = _SAME_LINE.match(line)
        if same:
            found.append((index, clean_line(f"{same.group('street')} {same.group('cp')} {same.group('city')}")))
            continue
        if _STREET_LINE.match(line):
            for offset in (1, 2):  # un complément (« Bât. B ») peut s'intercaler
                follower = lines[index + offset] if index + offset < len(lines) else ""
                postal = _POSTAL_LINE.match(follower)
                if postal:
                    complement = lines[index + 1] if offset == 2 else ""
                    parts = [line, complement, f"{postal.group('cp')} {postal.group('city')}"]
                    found.append((index, clean_line(" ".join(part for part in parts if part))))
                    break

    for index, address in found:
        if index > after:
            return address
    return found[0][1] if found else None
