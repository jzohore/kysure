"""Décodage de la bande MRZ (zone lisible par machine) avec contrôle des clés ICAO 9303.

Principe de sécurité : une MRZ n'est **retournée que si toutes ses clés de contrôle sont valides**.
Une MRZ aux clés fausses est une erreur de lecture, jamais une donnée ; elle n'est donc ni exposée
ni utilisée pour en dériver nom, dates ou numéro.

Formats pris en charge :
- TD1 (3 × 30)   : CNI européenne récente, titres de séjour ;
- TD2 (2 × 36)   : format ICAO « carte » à deux lignes ;
- TD3 (2 × 44)   : passeport ;
- CNI française ancienne (2 × 36) : variante propre à la France (`IDFRA…`, pas de date d'expiration) dont la clé
  finale couvre les DEUX lignes : le nom y est donc protégé, contrairement aux formats ICAO.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from datetime import date

_WEIGHTS = (7, 3, 1)
_ALLOWED = re.compile(r"[A-Z0-9<,(\[{]+")

# Confusions classiques de l'OCR sur une police OCR-B, selon que la position attend un chiffre ou une lettre.
_TO_DIGIT = str.maketrans(
    {"O": "0", "Q": "0", "D": "0", "(": "0", "I": "1", "L": "1", "Z": "2", "S": "5", "B": "8", "G": "6"}
)
# Crochets et parenthèses lus à la place d'un chevron : hors position numérique, c'est du bourrage.
_BRACKETS_TO_FILLER = str.maketrans({"(": "<", "[": "<", "{": "<"})
_TO_LETTER = str.maketrans({"0": "O", "1": "I", "2": "Z", "5": "S", "8": "B", "6": "G"})


def check_digit(field: str) -> int:
    """Clé ICAO : somme pondérée 7-3-1 modulo 10 (`<` vaut 0, une lettre vaut A=10 … Z=35)."""
    total = 0
    for index, char in enumerate(field):
        if char == "<":
            value = 0
        elif char.isdigit():
            value = int(char)
        elif "A" <= char <= "Z":
            value = ord(char) - ord("A") + 10
        else:
            raise ValueError("caractère MRZ invalide")
        total += value * _WEIGHTS[index % 3]
    return total % 10


def _key_ok(field: str, key: str) -> bool:
    """La clé lue doit être un chiffre égal à la clé recalculée (`<` toléré si le champ est vide)."""
    try:
        expected = check_digit(field)
    except ValueError:
        return False
    if key == "<":
        return set(field) <= {"<"}
    return key.isdigit() and int(key) == expected


def _date_ok(field: str, key: str) -> bool:
    """Champ date (AAMMJJ) : chiffres uniquement. La clé seule ne suffit pas — « < » et « 0 » pèsent tous deux 0
    dans le calcul ICAO, si bien qu'un chiffre lu « < » passerait le contrôle."""
    return (field.isdigit() or set(field) <= {"<"}) and _key_ok(field, key)


@dataclass(frozen=True)
class MrzResult:
    lines: tuple[str, ...]
    format: str
    document_number: str
    last_name: str
    first_name: str
    birth_date: date | None
    expiry_date: date | None

    def as_data(self) -> dict[str, object]:
        data: dict[str, object] = {
            "last_name": self.last_name,
            "first_name": self.first_name,
            "id_number": self.document_number,
            "mrz": {f"line_{index + 1}": line for index, line in enumerate(self.lines)},
        }
        if self.birth_date:
            data["birth_date"] = self.birth_date.isoformat()
        if self.expiry_date:
            data["date_of_expiry"] = self.expiry_date.isoformat()
        return data


# ---------------------------------------------------------------------------
# Champs
# ---------------------------------------------------------------------------


def _expand_birth_year(yy: int, today: date) -> int:
    """Une date de naissance n'est jamais dans le futur : 2 chiffres au-delà de l'année courante → 19yy."""
    century = 2000 if yy <= today.year % 100 else 1900
    return century + yy


def _parse_date(yymmdd: str, *, birth: bool, today: date) -> date | None:
    if not yymmdd.isdigit():
        return None
    yy, mm, dd = int(yymmdd[:2]), int(yymmdd[2:4]), int(yymmdd[4:6])
    year = _expand_birth_year(yy, today) if birth else 2000 + yy
    try:
        return date(year, mm, dd)
    except ValueError:
        return None


def _name_part(raw: str) -> str:
    """Un nom ne contient que des lettres : les chiffres lus par l'OCR sont des lettres mal reconnues."""
    return " ".join(part.translate(_TO_LETTER) for part in re.split(r"[<,]+", raw) if part)


def _split_names(field: str) -> tuple[str, str]:
    """`DUPONT<DURAND<<JEAN<PAUL<<<` → (« DUPONT DURAND », « JEAN PAUL »)."""
    surname, _, given = field.partition("<<")
    return _name_part(surname), _name_part(given)


def _document_number(number_field: str, number_key: str, overflow: str = "") -> tuple[str, bool]:
    """Numéro de document, avec gestion du débordement ICAO (numéro > 9 caractères).

    Quand la clé du champ vaut `<`, les 9 premiers caractères sont dans le champ numéro et le reste
    (suivi de la vraie clé) est en tête de la zone facultative.
    """
    if number_key != "<":
        core = number_field.rstrip("<")
        return core, "<" not in core and _key_ok(number_field, number_key)
    tail = overflow.rstrip("<")
    if len(tail) < 2:
        return "", False
    rest, key = tail[:-1], tail[-1]
    full = number_field + rest
    return full.rstrip("<"), "<" not in full and _key_ok(full, key)


# ---------------------------------------------------------------------------
# Décodeurs par format — chacun renvoie None si une clé est fausse
# ---------------------------------------------------------------------------


def _repair(line: str, digit_slices: tuple[tuple[int, int], ...], sex_index: int) -> str:
    """Corrige les confusions lettre/chiffre de l'OCR aux positions où le format impose un chiffre.

    Cette réparation ne peut pas introduire de fausse donnée : le résultat n'est accepté que si toutes
    les clés de contrôle sont ensuite valides.
    """
    chars = list(line)
    for begin, stop in digit_slices:
        for index in range(begin, stop):
            chars[index] = chars[index].translate(_TO_DIGIT)
    chars[sex_index] = _fix_sex(chars[sex_index])
    return "".join(chars)


def _decode_td1(lines: list[str], today: date) -> MrzResult | None:
    l1, l2, l3 = lines
    l1 = l1[:14] + l1[14].translate(_TO_DIGIT) + l1[15:]
    l2 = _repair(l2, ((0, 7), (8, 15), (29, 30)), 7)
    number, number_ok = _document_number(l1[5:14], l1[14], l1[15:30])
    composite = l1[5:30] + l2[0:7] + l2[8:15] + l2[18:29]
    checks = (
        number_ok,
        _date_ok(l2[0:6], l2[6]),
        _date_ok(l2[8:14], l2[14]),
        _key_ok(composite, l2[29]),
    )
    if not all(checks) or l1[0] not in "IAC":
        return None
    last, first = _split_names(l3)
    return MrzResult(
        (l1, l2, l3), "TD1", number, last, first,
        _parse_date(l2[0:6], birth=True, today=today), _parse_date(l2[8:14], birth=False, today=today),
    )  # fmt: skip


def _decode_td2(lines: list[str], today: date) -> MrzResult | None:
    l1, l2 = lines
    l2 = _repair(l2, ((9, 10), (13, 20), (21, 28), (35, 36)), 20)
    number, number_ok = _document_number(l2[0:9], l2[9], l2[28:35])
    composite = l2[0:10] + l2[13:20] + l2[21:35]
    checks = (
        number_ok,
        _date_ok(l2[13:19], l2[19]),
        _date_ok(l2[21:27], l2[27]),
        _key_ok(composite, l2[35]),
    )
    if not all(checks) or l1[0] not in "IAC":
        return None
    last, first = _split_names(l1[5:])
    return MrzResult(
        (l1, l2), "TD2", number, last, first,
        _parse_date(l2[13:19], birth=True, today=today), _parse_date(l2[21:27], birth=False, today=today),
    )  # fmt: skip


def _decode_td3(lines: list[str], today: date) -> MrzResult | None:
    l1, l2 = lines
    l2 = _repair(l2, ((9, 10), (13, 20), (21, 28), (43, 44)), 20)
    number, number_ok = _document_number(l2[0:9], l2[9])
    composite = l2[0:10] + l2[13:20] + l2[21:43]
    personal_empty = set(l2[28:42]) <= {"<"} and l2[42] in "<0"
    checks = (
        number_ok,
        _date_ok(l2[13:19], l2[19]),
        _date_ok(l2[21:27], l2[27]),
        personal_empty or _key_ok(l2[28:42], l2[42].translate(_TO_DIGIT)),
        _key_ok(composite, l2[43]),
    )
    if not all(checks) or l1[0] not in "PV":
        return None
    last, first = _split_names(l1[5:])
    return MrzResult(
        (l1, l2), "TD3", number, last, first,
        _parse_date(l2[13:19], birth=True, today=today), _parse_date(l2[21:27], birth=False, today=today),
    )  # fmt: skip


# Ancienne CNI française. Ligne 1 : IDFRA + nom (25) + service émetteur (6 chiffres).
# Ligne 2 : n° (12) + clé, prénoms (14), naissance (6) + clé, sexe, clé finale calculée sur LIGNE 1 + LIGNE 2.
# L'OCR abîme surtout le bourrage de la ligne 1 (chevrons perdus, ou lus « K », « C »…) : on reconstruit chaque
# ligne à partir de ses champs. Rien n'est deviné sans contrôle : la clé finale couvre les deux lignes, nom compris.
_FR_LINE1 = re.compile(r"^IDFRA(?P<body>[A-Z0-9<]+?)(?P<office>[A-Z0-9<(\[{]{6})$")
_FR_LINE2 = re.compile(r"^(?P<number>[A-Z0-9<(\[{]{13})(?P<names>[A-Z0-9<,]*?)<*(?P<tail>[A-Z0-9<(\[{]{9})$")
_MAX_SURNAME_PARTS = 4


def _fr_surname_candidates(body: str) -> list[str]:
    """Noms possibles en tête de ligne 1 : « DE<GAULLE<<<KK<< » → « DE », « DE<GAULLE ». Le bourrage qui suit le
    nom est forcément fait de « < » : toute lettre lue au-delà du premier « << » est une erreur d'OCR."""
    parts = body.split("<")
    candidates: list[str] = []
    for count in range(1, min(len(parts), _MAX_SURNAME_PARTS) + 1):
        if parts[count - 1] == "":
            break
        candidates.append("<".join(parts[:count]))
    return candidates


def _decode_fr_cni(lines: list[str], today: date) -> MrzResult | None:
    line1 = _FR_LINE1.match(lines[0])
    line2 = _FR_LINE2.match(lines[1])
    if line1 is None or line2 is None:
        return None

    names = line2["names"].rstrip("<").translate(_TO_LETTER)
    if len(names) > 14:
        return None

    number = line2["number"].translate(_BRACKETS_TO_FILLER)
    tail = line2["tail"]
    # Clé du n°, date de naissance et clé, clé finale : chiffres imposés. Le n° lui-même peut contenir une lettre.
    l2 = number[:12] + number[12].translate(_TO_DIGIT) + names.ljust(14, "<") + tail[:7].translate(_TO_DIGIT) \
        + _fix_sex(tail[7]) + tail[8].translate(_TO_DIGIT)  # fmt: skip
    if not ("<" not in l2[0:12] and _key_ok(l2[0:12], l2[12]) and _date_ok(l2[27:33], l2[33])):
        return None

    office = line1["office"].translate(_TO_DIGIT)
    if not office.isdigit():
        return None

    for raw_surname in _fr_surname_candidates(line1["body"]):
        surname = raw_surname.translate(_TO_LETTER)
        if len(surname) > 25:
            break
        l1 = "IDFRA" + surname.ljust(25, "<") + office
        if _key_ok(l1 + l2[0:35], l2[35]):
            return MrzResult(
                (l1, l2), "FR_CNI", l2[0:12], _name_part(surname), _name_part(names),
                _parse_date(l2[27:33], birth=True, today=today), None,
            )  # fmt: skip
    return None


def _fix_sex(char: str) -> str:
    return char if char in "MF<X" else {"H": "M", "N": "M", "P": "F"}.get(char, char)


# (nombre de lignes, longueur) → décodeurs ICAO, qui lisent des positions fixes.
_DECODERS = {
    (3, 30): (_decode_td1,),
    (2, 36): (_decode_td2,),
    (2, 44): (_decode_td3,),
}
# Lignes qui ne portent aucune clé de contrôle (noms) : l'OCR y perd souvent des « < » de bourrage, ce qui
# n'a aucune incidence sur les positions contrôlées. Leur longueur est donc libre ; celle des autres ne l'est pas.
_UNCHECKED_LINE = {(3, 30): 2, (2, 36): 0, (2, 44): 0}
_SLACK = 3  # l'OCR perd ou ajoute parfois un caractère ; les clés tranchent de toute façon
_MIN_LENGTH = 25


def _fit(line: str, length: int, *, free_length: bool) -> str | None:
    if free_length:
        if len(line) < _MIN_LENGTH:
            return None
    elif abs(len(line) - length) > _SLACK:
        return None
    return line[:length].ljust(length, "<")


# Guillemets lus à la place du chevron « < ». Les parenthèses / crochets restent bruts : selon la position, ce sont
# un « 0 » (position numérique) ou un « < » (bourrage) ; chaque décodeur tranche, et les clés valident.
_FILLER_LOOKALIKES = str.maketrans({"«": "<", "»": "<", "‹": "<", "›": "<"})


def _candidate_lines(text: str) -> list[str]:
    candidates: list[str] = []
    for raw in text.splitlines():
        line = raw.upper().replace(" ", "").translate(_FILLER_LOOKALIKES)
        if len(line) < _MIN_LENGTH or not _ALLOWED.fullmatch(line):
            continue
        # Une ligne de MRZ porte des « < » de bourrage, ou à défaut beaucoup de chiffres (prénoms de 14 lettres).
        if line.count("<") >= 2 or sum(char.isdigit() for char in line) >= 6:
            candidates.append(line)
    return candidates


def find_mrz(text: str, *, today: date | None = None) -> MrzResult | None:
    """Cherche une MRZ valide dans un texte (couche texte de PDF ou sortie OCR de la bande)."""
    today = today or date.today()
    candidates = _candidate_lines(text)

    # Ancienne CNI française : lignes brutes, le décodeur reconstruit lui-même les positions.
    for start in range(len(candidates) - 1):
        result = _try(_decode_fr_cni, candidates[start : start + 2], today)
        if result is not None:
            return result

    for (count, length), decoders in _DECODERS.items():
        for start in range(len(candidates) - count + 1):
            free = _UNCHECKED_LINE[(count, length)]
            window = [
                _fit(line.translate(_BRACKETS_TO_FILLER), length, free_length=position == free)
                for position, line in enumerate(candidates[start : start + count])
            ]
            if any(line is None for line in window):
                continue
            fitted = [line for line in window if line is not None]
            for decoder in decoders:
                result = _try(decoder, fitted, today)
                if result is not None:
                    return result
    return None


def _try(decoder, lines: list[str], today: date) -> MrzResult | None:  # type: ignore[no-untyped-def]
    try:
        return decoder(lines, today)
    except (ValueError, IndexError):
        return None
