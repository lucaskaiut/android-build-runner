#!/usr/bin/env bash
set -Eeuo pipefail

cd /var/www/html

log() { echo "[scheduler-start] $*"; }

log "Iniciando o agendador do Laravel (schedule:work) e o worker de submit..."

# schedule:work cuida do reaper e de outras tarefas agendadas.
(while true; do php artisan schedule:work || true; sleep 5; done) &
schedule_pid=$!

cleanup() {
    kill "$schedule_pid" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

# O submit usa a API do painel e o storage compartilhado. Ele roda fora do
# schedule:work para um upload grande não atrasar o reaper.
while true; do
    php artisan builds:submit --limit=5 || true
    sleep 10
done
