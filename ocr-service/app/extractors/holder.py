"""Titulaire d'une pièce (justificatif de domicile, RIB) : libellé explicite, sinon ligne de civilité."""

from __future__ import annotations

import re

from .common import clean_line, fold

# Libellés explicites (« Titulaire du compte : MARTIN Camille »). Le premier trouvé l'emporte.
_LABELED = re.compile(
    r"^(?:titulaire(?:\s+du\s+(?:compte|contrat))?|intitule\s+du\s+compte|nom\s+du\s+(?:titulaire|client)"
    r"|client|destinataire|abonne)(?=[\s:\-])\s*[:\-]?\s*(?P<name>.+)$"
)
_CIVILITY = re.compile(
    r"^(?:m\.|mr\.?|mme\.?|mlle\.?|madame|monsieur|m\b)\s+(?:et\s+(?:m\.|mr\.?|mme\.?|madame|monsieur)\s+)?"
    r"(?P<name>[a-z][a-z' \-]{2,70})$"
)
_NAME_BODY = re.compile(r"^[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ' \-]{1,70}$")


def find_holder(lines: list[str]) -> tuple[str, int] | None:
    """(nom, index de ligne) du titulaire, ou None. Les lignes avec chiffres ne sont jamais un nom."""
    for index, line in enumerate(lines):
        match = _LABELED.match(fold(line))
        if match:
            value = clean_line(line[len(line) - len(match.group("name")) :])
            if _NAME_BODY.match(value):
                return value, index

    for index, line in enumerate(lines):
        if _CIVILITY.match(fold(line)):
            return clean_line(line), index
    return None
