"""Pièces d'identité (CNI, passeport, titre de séjour) : MRZ validée en priorité, zone visuelle en repli."""

from __future__ import annotations

import re
from datetime import date
from typing import Protocol

from .common import clean_line, find_dates, fold, lines_of, normalize_text
from .mrz import find_mrz

_NAME_VALUE = re.compile(r"^[A-ZÀ-Ý][A-ZÀ-Ý' \-]{1,60}$")
_BILINGUAL_NOISE = re.compile(r"\b(?:surname|given names?|names?|nom|prenoms?)\b", re.IGNORECASE)


class IdentityContent(Protocol):
    def text(self) -> str: ...
    def mrz_band_text(self) -> str: ...
    def mrz_page_text(self) -> str: ...


def extract(content: IdentityContent, *, today: date | None = None) -> dict[str, object]:
    text = normalize_text(content.text())

    # Du moins coûteux au plus coûteux ; on s'arrête à la première MRZ dont toutes les clés sont valides.
    # 1) OCR pleine page déjà fait ; 2) bande du bas relue en liste blanche (photo d'une seule face) ;
    # 3) page entière en liste blanche (recto et verso empilés : la bande est au milieu de l'image).
    mrz = find_mrz(text, today=today)
    for rescan in (content.mrz_band_text, content.mrz_page_text):
        if mrz is not None:
            break
        mrz = find_mrz(normalize_text(rescan()), today=today)

    data: dict[str, object] = mrz.as_data() if mrz else {}

    # Repli sur la zone visuelle pour ce que la MRZ ne donne pas (ex. expiration d'une ancienne CNI).
    for key, value in _visual_fields(text).items():
        data.setdefault(key, value)
    return data


def _visual_fields(text: str) -> dict[str, object]:
    lines = lines_of(text)
    fields: dict[str, object] = {}

    last_name = _labeled_value(lines, ("nom", "surname"))
    if last_name:
        fields["last_name"] = last_name
    first_name = _labeled_value(lines, ("prenom", "prenoms", "given names", "given name"))
    if first_name:
        fields["first_name"] = first_name

    birth = _date_after(text, ("ne(e) le", "nee le", "ne le", "date de naissance", "date of birth"))
    if birth:
        fields["birth_date"] = birth
    expiry = _date_after(
        text,
        ("valable jusqu'au", "date d'expiration", "date of expiry", "expiry date", "expire le", "valid until"),
    )
    if expiry:
        fields["date_of_expiry"] = expiry
    return fields


def _labeled_value(lines: list[str], labels: tuple[str, ...]) -> str | None:
    """Valeur d'un champ nominatif : après le libellé sur la même ligne, ou sur la ligne suivante."""
    for index, line in enumerate(lines):
        folded = fold(line)
        for label in labels:
            match = re.match(rf"^{re.escape(label)}(?:\(s\))?(?:\s*/\s*[a-z ]+)?\s*[:\-]?\s*(.*)$", folded)
            if not match:
                continue
            same_line = clean_line(line[len(line) - len(match.group(1)) :]) if match.group(1) else ""
            candidates = [same_line] + ([lines[index + 1]] if index + 1 < len(lines) else [])
            for candidate in candidates:
                value = clean_line(_BILINGUAL_NOISE.sub("", candidate))
                if value and _NAME_VALUE.match(value):
                    return value
    return None


def _date_after(text: str, labels: tuple[str, ...]) -> str | None:
    folded = fold(text)
    for label in labels:
        for match in re.finditer(re.escape(label), folded):
            window = text[match.end() : match.end() + 40]
            dates = find_dates(window)
            if dates and dates[0].start <= 15:
                return dates[0].value.isoformat()
    return None
