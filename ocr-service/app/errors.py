"""Erreurs métier du service, avec leur statut HTTP.

Sémantique alignée sur `OcrProviderInterface` côté PHP :
- 4xx (413 / 422) : document illisible ou non pris en charge → définitif, pas de retry ;
- 5xx : échec technique → réessayable par Messenger.
"""

from __future__ import annotations


class ExtractionError(Exception):
    status_code = 500
    code = "internal_error"

    def __init__(self) -> None:
        # Jamais de message libre : aucune donnée de pièce ne doit pouvoir remonter dans une erreur.
        super().__init__(self.code)


class UnsupportedType(ExtractionError):
    status_code = 422
    code = "unsupported_type"


class UnreadableDocument(ExtractionError):
    status_code = 422
    code = "unreadable_document"


class FileTooLarge(ExtractionError):
    status_code = 413
    code = "file_too_large"


class ServiceBusy(ExtractionError):
    status_code = 503
    code = "busy"


class ExtractionTimeout(ExtractionError):
    status_code = 504
    code = "timeout"
