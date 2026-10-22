# Service d'extraction OCR local de KYSURE (FastAPI + Tesseract + pdfium).
# Joignable UNIQUEMENT par le réseau compose, jamais publié (voir compose*.yaml).
#
#   docker build -t kysure-ocr ocr-service                 # image d'exécution
#   docker build --target test ocr-service                 # pytest dans l'environnement réel (Tesseract inclus)

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    # Tesseract mono-thread : prévisible en mémoire et en CPU sur un petit serveur.
    OMP_THREAD_LIMIT=1

# Tesseract + données françaises ; rien d'autre (pas de poppler : la rastérisation PDF passe par pdfium).
# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-fra tesseract-ocr-eng \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv

COPY requirements.txt ./
RUN pip install -r requirements.txt


# --- Tests : stage jamais livré, il ne sert qu'à `docker build --target test` (CI) ---------------
FROM base AS test

COPY requirements-dev.txt pytest.ini ./
RUN pip install -r requirements-dev.txt
COPY app ./app
COPY tests ./tests
RUN python -m pytest


# --- Exécution ------------------------------------------------------------------------------------
FROM base AS runtime

# Utilisateur non-root, sans shell ni répertoire personnel.
RUN useradd --system --uid 10001 --no-create-home --shell /usr/sbin/nologin ocr

COPY app ./app

USER 10001:10001
EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=5 \
    CMD ["python", "-c", "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=4).status == 200 else 1)"]

# Un seul worker : la mémoire du conteneur est bornée (≈ 512 Mo) et les extractions sont sérialisées.
# --no-access-log : aucune trace par requête côté uvicorn (le service journalise lui-même type/statut/durée).
CMD ["uvicorn", "app.main:create_app", "--factory", "--host", "0.0.0.0", "--port", "8000", "--workers", "1", "--no-access-log", "--timeout-keep-alive", "5"]
