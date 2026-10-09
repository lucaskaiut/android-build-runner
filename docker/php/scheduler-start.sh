#!/usr/bin/env bash
set -Eeuo pipefail

cd /var/www/html

log() { echo "[scheduler-start] $*"; }

log "Iniciando o agendador do Laravel (schedule:work)..."
exec php artisan schedule:work
