"""Registre des extracteurs, indexé par la valeur de `DocumentType` (côté PHP)."""

from __future__ import annotations

from collections.abc import Callable
from typing import Any

from ..errors import UnsupportedType
from . import identity, kbis, proof_of_address, rib

# Routage par type. Les types sans extracteur (relevés, statuts…) relèvent d'une vérification manuelle.
EXTRACTORS: dict[str, Callable[..., dict[str, object]]] = {
    "id_card": identity.extract,
    "passport": identity.extract,
    "residence_permit": identity.extract,
    "proof_of_address": proof_of_address.extract,
    "rib": rib.extract,
    "kbis": kbis.extract,
}


def normalize(data: dict[str, object]) -> dict[str, Any]:
    """Écarte les valeurs vides : le contrat PHP attend des clés absentes plutôt que des chaînes vides."""
    return {key: value for key, value in data.items() if value not in (None, "", {}, [])}


def run(document_type: str, content: Any) -> dict[str, Any]:
    extractor = EXTRACTORS.get(document_type)
    if extractor is None:
        raise UnsupportedType()
    return normalize(extractor(content))
