#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="/var/www/html"
cd "$APP_DIR"

log() { echo "[api-start] $*"; }

env_get() {
    local key="$1" default="${2:-}" value
    value="$(grep -E "^${key}=" .env 2>/dev/null | tail -n1 | cut -d '=' -f2- | tr -d '"' | tr -d "'" || true)"
    echo "${value:-$default}"
}

if [ ! -f composer.json ]; then
    log "ERRO: composer.json não encontrado. O projeto Laravel deve estar em ./api."
    exit 1
fi

# ---------------------------------------------------------------------------
# .env (only when missing)
# ---------------------------------------------------------------------------
if [ ! -f .env ]; then
    if [ -f .env.docker ]; then
        cp .env.docker .env
        log ".env criado a partir de .env.docker"
    elif [ -f .env.example ]; then
        cp .env.example .env
        log ".env criado a partir de .env.example"
    else
        log "ERRO: nenhum .env, .env.docker ou .env.example encontrado"
        exit 1
    fi
else
    log ".env já existe - mantendo"
fi

export DB_HOST="$(env_get DB_HOST mysql)"
export DB_PORT="$(env_get DB_PORT 3306)"
export DB_DATABASE="$(env_get DB_DATABASE build_platform)"
export DB_USERNAME="$(env_get DB_USERNAME platform)"
export DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-root}"

# ---------------------------------------------------------------------------
# Dependencies (only when vendor/ is missing)
# ---------------------------------------------------------------------------
if [ ! -f vendor/autoload.php ]; then
    log "Instalando dependências do Composer..."
    composer install --no-interaction --prefer-dist --no-progress
else
    log "vendor/ já existe - pulando composer install"
fi

# ---------------------------------------------------------------------------
# Wait for MySQL
# ---------------------------------------------------------------------------
log "Aguardando MySQL em ${DB_HOST}:${DB_PORT}..."
tries=0
until php -r '
    try {
        new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT"), "root", getenv("DB_ROOT_PASSWORD"), [PDO::ATTR_TIMEOUT => 3]);
        exit(0);
    } catch (Throwable $e) {
        exit(1);
    }
' 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -ge 90 ]; then
        log "ERRO: MySQL indisponível após ${tries} tentativas"
        exit 1
    fi
    sleep 2
done
log "MySQL disponível"

# ---------------------------------------------------------------------------
# Ensure application database and test database (idempotent, never drops)
# ---------------------------------------------------------------------------
php -r '
    $pdo = new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT"), "root", getenv("DB_ROOT_PASSWORD"));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = str_replace("`", "", getenv("DB_DATABASE"));
    $user = getenv("DB_USERNAME");
    foreach (["{$db}", "{$db}_test"] as $name) {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    if ($user !== false && $user !== "" && $user !== "root") {
        try {
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$db}`.* TO \"{$user}\"@\"%\"");
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$db}_test`.* TO \"{$user}\"@\"%\"");
            $pdo->exec("FLUSH PRIVILEGES");
        } catch (Throwable $e) {
            fwrite(STDERR, "[api-start] AVISO: não foi possível conceder privilégios: " . $e->getMessage() . PHP_EOL);
        }
    }
'
log "Bancos '${DB_DATABASE}' e '${DB_DATABASE}_test' garantidos"

# ---------------------------------------------------------------------------
# APP_KEY (only when empty)
# ---------------------------------------------------------------------------
if [ -z "$(env_get APP_KEY)" ]; then
    log "Gerando APP_KEY..."
    php artisan key:generate --force --no-interaction
else
    log "APP_KEY já definida - pulando"
fi

# ---------------------------------------------------------------------------
# Migrations
# ---------------------------------------------------------------------------
log "Executando migrations..."
php artisan migrate --force --no-interaction

php artisan optimize:clear --no-interaction >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# Permissions
# ---------------------------------------------------------------------------
HOST_UID=$(stat -c '%u' "$APP_DIR")
if [ "$HOST_UID" != "0" ] && [ "$HOST_UID" != "$(id -u www-data)" ]; then
    usermod -u "$HOST_UID" www-data 2>/dev/null || true
    log "UID do www-data ajustado para $HOST_UID (match com host)"
fi

mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/logs storage/app/artifacts bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
log "Permissões ajustadas (storage, bootstrap/cache)"

log "Inicialização concluída. Iniciando PHP-FPM..."
exec php-fpm
