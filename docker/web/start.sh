#!/bin/sh
set -eu

APP_DIR="/app"
cd "$APP_DIR"

log() { echo "[web-start] $*"; }

DEV_PORT="${DEV_PORT:-5174}"
HOST="${HOST:-0.0.0.0}"
export HOST

if [ ! -f package.json ]; then
    log "ERRO: package.json não encontrado. O projeto React deve estar em ./web."
    exit 1
fi

# ---------------------------------------------------------------------------
# .env (only when missing). The Vite config falls back to the compose service
# name for the API proxy, so an .env file is optional.
# ---------------------------------------------------------------------------
if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
    log ".env criado a partir de .env.example"
fi

# ---------------------------------------------------------------------------
# Install dependencies when node_modules is missing or the lockfile changed.
# ---------------------------------------------------------------------------
current_hash() {
    if [ -f pnpm-lock.yaml ]; then
        md5sum pnpm-lock.yaml
    elif [ -f yarn.lock ]; then
        md5sum yarn.lock
    elif [ -f package-lock.json ]; then
        md5sum package-lock.json
    else
        md5sum package.json
    fi | awk '{print $1}'
}

STAMP="node_modules/.install-stamp"
HASH="$(current_hash)"

if [ ! -f "$STAMP" ] || [ "$(cat "$STAMP" 2>/dev/null)" != "$HASH" ]; then
    log "Instalando dependências..."
    if [ -f package-lock.json ]; then
        npm ci || npm install
    else
        npm install
    fi
    mkdir -p node_modules
    current_hash > "$STAMP"
    log "Dependências instaladas"
else
    log "Dependências atualizadas - pulando instalação"
fi

MODE="${WEB_MODE:-dev}"

if [ "$MODE" = "preview" ]; then
    log "Modo preview: build de produção + servidor de preview (porta ${DEV_PORT})"
    npm run build
    exec npm run preview -- --host "$HOST" --port "$DEV_PORT" --strictPort
fi

log "Modo dev: Vite dev server (porta ${DEV_PORT})"
exec npm run dev -- --host "$HOST" --port "$DEV_PORT" --strictPort
