"""Extrait K-bis : dénomination, SIREN (clé de Luhn) et date de l'extrait."""

from __future__ import annotations

import re
from datetime import date
from typing import Protocol

from .common import clean_line, fold, lines_of, luhn_valid, normalize_text, pick_date

# Libellés du K-bis : sert à borner une valeur quand l'extraction PDF aplatit deux colonnes sur une ligne.
_NEXT_LABELS = (
    "forme juridique|nom commercial|sigle|capital|adresse du siege|date d'immatriculation|code ape|duree"
    "|domiciliation|enseigne|siege|activite|date de debut|numero de gestion|date de naissance"
)
_NAME_LABEL = re.compile(
    r"(?:denomination\s+ou\s+raison\s+sociale|denomination\s+sociale|raison\s+sociale|denomination)\s*[:\-]?\s*"
)
_REGISTRATION_LABEL = re.compile(r"(?:siren|r\.?\s?c\.?\s?s\.?|numero\s+d'identification|immatriculation[^\n]{0,40}numero)")
_SIREN = re.compile(r"(?<!\d)(\d{3})[ .]?(\d{3})[ .]?(\d{3})(?!\d)")

_DATE_POSITIVE = (
    "delivre le", "delivre ce jour", "a jour au", "mis a jour", "edite le", "etabli le", "fait le", "date de l'extrait",
    "extrait du", "au registre du commerce",
)  # fmt: skip
_DATE_NEGATIVE = (
    "immatriculation", "naissance", "debut d'activite", "creation", "dissolution", "radiation", "cloture", "cession",
    "duree", "depuis le", "jusqu",
)  # fmt: skip


class TextContent(Protocol):
    def text(self) -> str: ...


def extract(content: TextContent, *, today: date | None = None) -> dict[str, object]:
    text = normalize_text(content.text())
    data: dict[str, object] = {}

    name = _find_company_name(text)
    if name:
        data["company_name"] = name

    siren = _find_siren(text)
    if siren:
        data["registration_number"] = f"{siren[:3]} {siren[3:6]} {siren[6:]}"

    document_date = pick_date(text, positive=_DATE_POSITIVE, negative=_DATE_NEGATIVE, today=today)
    if document_date:
        data["document_date"] = document_date.isoformat()
    return data


def _find_siren(text: str) -> str | None:
    """SIREN à clé de Luhn valide, cherché derrière un libellé (RCS, SIREN…) : jamais un nombre au hasard."""
    folded = fold(text)
    for label in _REGISTRATION_LABEL.finditer(folded):
        window = text[label.end() : label.end() + 70]
        for number in _SIREN.finditer(window):
            candidate = "".join(number.groups())
            if luhn_valid(candidate):
                return candidate
    return None


def _find_company_name(text: str) -> str | None:
    """Valeur après « Dénomination ou raison sociale », sur la même ligne ou la suivante."""
    lines = lines_of(text)
    for index, line in enumerate(lines):
        match = _NAME_LABEL.search(fold(line))
        if not match:
            continue
        same_line = line[match.end() :].strip()
        raw = same_line or (lines[index + 1] if index + 1 < len(lines) else "")
        candidate = clean_line(_cut_at_next_label(raw))
        if len(candidate) >= 2 and not re.fullmatch(r"[\d\W]+", candidate):
            return candidate
    return None


def _cut_at_next_label(value: str) -> str:
    """Coupe la valeur au libellé suivant (comparaison sans accents, texte d'origine conservé)."""
    match = re.search(rf"\s+(?:{_NEXT_LABELS})\b", fold(value))
    return value[: match.start()] if match else value
