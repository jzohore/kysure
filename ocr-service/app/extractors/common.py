"""Outils partagés par les extracteurs : texte, dates françaises, clés de contrôle (Luhn, IBAN)."""

from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass
from datetime import date, timedelta

# ---------------------------------------------------------------------------
# Texte
# ---------------------------------------------------------------------------


def normalize_text(value: str) -> str:
    """NFC + espaces insécables en espaces : garantit que `fold()` conserve les longueurs (index stables)."""
    return unicodedata.normalize("NFC", value).replace("\u00a0", " ")


def strip_accents(value: str) -> str:
    decomposed = unicodedata.normalize("NFD", value)
    return "".join(char for char in decomposed if unicodedata.category(char) != "Mn")


def fold(value: str) -> str:
    """Minuscules sans accents, pour comparer des libellés sans se soucier de la casse ni de l'OCR.

    Conserve la longueur d'un texte normalisé NFC (voir `normalize_text`) : les positions trouvées dans
    la version « foldée » valent aussi dans l'original.
    """
    return strip_accents(value).lower()


def clean_line(value: str) -> str:
    return re.sub(r"[ \t ]+", " ", value).strip()


def lines_of(text: str) -> list[str]:
    return [line for line in (clean_line(raw) for raw in text.splitlines()) if line]


# ---------------------------------------------------------------------------
# Dates (toujours restituées en `Y-m-d`)
# ---------------------------------------------------------------------------

_MONTHS = {
    "janvier": 1,
    "fevrier": 2,
    "mars": 3,
    "avril": 4,
    "mai": 5,
    "juin": 6,
    "juillet": 7,
    "aout": 8,
    "septembre": 9,
    "octobre": 10,
    "novembre": 11,
    "decembre": 12,
}

_MONTH_NAMES = "|".join(_MONTHS)

# 03/01/2026, 03.01.2026, 03-01-2026 (année sur 4 chiffres : 2 chiffres = trop ambigu pour un contrôle légal)
_NUMERIC_DATE = r"(?P<d>\d{1,2})[/.\-](?P<m>\d{1,2})[/.\-](?P<y>\d{4})"
# 2026-01-03
_ISO_DATE = r"(?P<iy>\d{4})-(?P<im>\d{2})-(?P<id>\d{2})"
# 3 janvier 2026, 1er février 2026
_TEXT_DATE = rf"(?P<td>\d{{1,2}})(?:er)?\s+(?P<tm>(?:{_MONTH_NAMES})\w*)\s+(?P<ty>\d{{4}})"

_DATE_RE = re.compile(rf"(?<![\d/.\-])(?:{_NUMERIC_DATE}|{_ISO_DATE}|{_TEXT_DATE})(?![\d])")

_MIN_YEAR, _MAX_YEAR = 1900, 2100


def _build_date(year: int, month: int, day: int) -> date | None:
    if not _MIN_YEAR <= year <= _MAX_YEAR:
        return None
    try:
        return date(year, month, day)
    except ValueError:
        return None


@dataclass(frozen=True)
class DateMatch:
    value: date
    start: int
    end: int


def find_dates(text: str) -> list[DateMatch]:
    """Toutes les dates lisibles du texte, dans l'ordre. Une date impossible (31/02) est ignorée."""
    found: list[DateMatch] = []
    for match in _DATE_RE.finditer(fold(text)):
        parsed: date | None = None
        if match.group("d"):
            parsed = _build_date(int(match.group("y")), int(match.group("m")), int(match.group("d")))
        elif match.group("iy"):
            parsed = _build_date(int(match.group("iy")), int(match.group("im")), int(match.group("id")))
        else:
            month = _month_number(match.group("tm"))
            if month is not None:
                parsed = _build_date(int(match.group("ty")), month, int(match.group("td")))
        if parsed is not None:
            found.append(DateMatch(parsed, match.start(), match.end()))
    return found


def _month_number(raw: str) -> int | None:
    word = fold(raw)
    for name, number in _MONTHS.items():
        if word == name or word.startswith(name):
            return number
    return None


def parse_date(raw: str) -> date | None:
    matches = find_dates(raw)
    return matches[0].value if matches else None


def month_end(year: int, month: int) -> date | None:
    if not 1 <= month <= 12:
        return None
    first_of_next = date(year + (month == 12), month % 12 + 1, 1)
    return first_of_next - timedelta(days=1)


_MONTH_YEAR_RE = re.compile(rf"(?<![\d])(?P<m>(?:{_MONTH_NAMES}))\w*\s+(?P<y>\d{{4}})(?![\d])")


def find_month_years(text: str) -> list[DateMatch]:
    """« janvier 2026 » → fin de mois. N'est utilisé que derrière un mot-clé explicite (« mois de »)."""
    found: list[DateMatch] = []
    for match in _MONTH_YEAR_RE.finditer(fold(text)):
        month = _month_number(match.group("m"))
        end = month_end(int(match.group("y")), month) if month else None
        if end is not None and _MIN_YEAR <= end.year <= _MAX_YEAR:
            found.append(DateMatch(end, match.start(), match.end()))
    return found


def pick_date(
    text: str,
    *,
    positive: tuple[str, ...],
    negative: tuple[str, ...],
    today: date | None = None,
    window: int = 70,
    allow_month_year: tuple[str, ...] = (),
) -> date | None:
    """Choisit la date « du document » parmi toutes celles du texte, d'après le contexte qui la précède.

    Un mot-clé négatif (échéance, naissance, période…) écarte la date ; un mot-clé positif la favorise ;
    à score égal, la première du texte l'emporte. Une date dans le futur est une erreur de lecture, pas une
    donnée : elle est écartée (le validateur PHP signalera alors « date introuvable »).
    """
    today = today or date.today()
    limit = today + timedelta(days=2)
    candidates: list[DateMatch] = list(find_dates(text))

    folded = fold(text)  # fold() conserve les longueurs : les index restent valables
    for match in find_month_years(text):
        context = folded[max(0, match.start - window) : match.start]
        if any(keyword in context for keyword in allow_month_year):
            candidates.append(match)

    best: tuple[int, int, date] | None = None
    for match in sorted(candidates, key=lambda m: m.start):
        if match.value > limit:
            continue
        context = folded[max(0, match.start - window) : match.start]
        if any(keyword in context for keyword in negative):
            continue
        score = 10 if any(keyword in context for keyword in positive) else 0
        if best is None or score > best[0]:
            best = (score, -match.start, match.value)
    return best[2] if best else None


# ---------------------------------------------------------------------------
# Clés de contrôle
# ---------------------------------------------------------------------------


def luhn_valid(digits: str) -> bool:
    """Clé de Luhn (SIREN, SIRET). Une clé fausse est une erreur de lecture, pas une donnée."""
    if not digits.isdigit():
        return False
    total = 0
    for index, char in enumerate(reversed(digits)):
        number = int(char)
        if index % 2 == 1:
            number *= 2
            if number > 9:
                number -= 9
        total += number
    return total % 10 == 0


# Longueurs IBAN usuelles ; un pays inconnu reste accepté si la clé mod 97 est bonne.
IBAN_LENGTHS = {
    "FR": 27, "DE": 22, "BE": 16, "ES": 24, "IT": 27, "LU": 20, "NL": 18,
    "PT": 25, "CH": 21, "GB": 22, "MC": 27, "IE": 22, "AT": 20,
}  # fmt: skip


def iban_valid(iban: str) -> bool:
    compact = iban.replace(" ", "").upper()
    if not re.fullmatch(r"[A-Z]{2}\d{2}[A-Z0-9]{11,30}", compact):
        return False
    expected = IBAN_LENGTHS.get(compact[:2])
    if expected is not None and len(compact) != expected:
        return False
    rearranged = compact[4:] + compact[:4]
    numeric = "".join(str(int(char, 36)) for char in rearranged)
    return int(numeric) % 97 == 1
