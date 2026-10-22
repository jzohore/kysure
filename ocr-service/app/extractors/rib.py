"""RIB : IBAN validé par sa clé de contrôle (mod 97), BIC et titulaire."""

from __future__ import annotations

import re
from typing import Protocol

from .common import IBAN_LENGTHS, fold, iban_valid, lines_of, normalize_text
from .holder import find_holder

# Amorce d'IBAN : 2 lettres + 2 chiffres, puis des groupes alphanumériques (espaces tolérés).
_IBAN_START = re.compile(r"(?<![A-Z0-9])[A-Z]{2}\d{2}(?:[ \t]?[A-Z0-9]){11,30}")
_BIC = re.compile(r"(?<![A-Z0-9])[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?(?![A-Z0-9])")
_BIC_LABEL = re.compile(r"(?:bic|swift)\W{0,10}$")


class TextContent(Protocol):
    def text(self) -> str: ...


def extract(content: TextContent) -> dict[str, object]:
    text = normalize_text(content.text())
    data: dict[str, object] = {}

    iban = find_iban(text)
    if iban:
        data["iban"] = iban

    bic = _find_bic(text)
    if bic:
        data["bic"] = bic

    holder = find_holder(lines_of(text))
    if holder:
        data["holder_name"] = holder[0]
    return data


def find_iban(text: str) -> str | None:
    """Premier IBAN à clé valide. Un candidat à la clé fausse est une erreur de lecture : jamais retourné."""
    for match in _IBAN_START.finditer(text.upper()):
        compact = re.sub(r"[ \t]", "", match.group(0))
        known = IBAN_LENGTHS.get(compact[:2])
        lengths = [known] if known else range(min(34, len(compact)), 14, -1)
        for length in lengths:
            if length <= len(compact) and iban_valid(compact[:length]):
                return compact[:length]
    return None


def _find_bic(text: str) -> str | None:
    """Un BIC n'est retenu que derrière son libellé : un mot de 8 majuscules n'est pas forcément un BIC."""
    upper = text.upper()
    for match in _BIC.finditer(upper):
        before = fold(text[max(0, match.start() - 14) : match.start()])
        if _BIC_LABEL.search(before):
            return match.group(0)
    return None
