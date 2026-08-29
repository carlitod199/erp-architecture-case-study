#!/bin/sh
# =============================================================================
# VERO — entrypoint de REFERÊNCIA para a imagem de produção (adapte ao seu init).
# Materializa os segredos de /run/secrets/ no formato que o app espera e garante
# as env vars que o VERO lê SÓ via getenv/$_ENV. Roda como root, dropa no FPM.
# Contexto: app em /var/www/vero, usuário vero-php (uid/gid 10001).
# =============================================================================
set -eu

ENV_FILE="/var/www/vero/.env"                                   # config/database.php + ia.php parseiam este arquivo
SESS_INI="/usr/local/etc/php/conf.d/zz-vero-session.ini"        # sessão no Redis (senha não pode ser baked na imagem)

# --- Segredos entregues como ARQUIVO (fail-fast se o obrigatório faltar) ------
DB_PASS_VAL="$(cat /run/secrets/mysql_app)"                     # senha do vero_app (runtime)
REDIS_PASSWORD_VAL="$(cat /run/secrets/redis_password 2>/dev/null || true)"
# IA são segredos também: se você entregar como arquivo, descomente:
# OPENAI_API_KEY="$(cat /run/secrets/openai_api_key 2>/dev/null || printf '%s' "${OPENAI_API_KEY:-}")"
# ANTHROPIC_API_KEY="$(cat /run/secrets/anthropic_api_key 2>/dev/null || printf '%s' "${ANTHROPIC_API_KEY:-}")"

# --- 1) Renderiza o .env que o parser do VERO lê (DB_*, UNI_DB_*, OPENAI_*) ----
# Tudo configurável por env var do container; a senha vem do secret. DSN é sem
# porta -> o MySQL TEM que estar na 3306. UNI_* usa o mesmo vero_app por padrão.
umask 027
cat > "$ENV_FILE" <<EOF
DB_HOST=${DB_HOST:-mysql}
DB_NAME=${DB_NAME:-vero_db}
DB_USER=${DB_USER:-vero_app}
DB_PASS=${DB_PASS_VAL}
UNI_DB_HOST=${UNI_DB_HOST:-mysql}
UNI_DB_NAME=${UNI_DB_NAME:-vero_universidade}
UNI_DB_USER=${UNI_DB_USER:-vero_app}
UNI_DB_PASS=${UNI_DB_PASS:-$DB_PASS_VAL}
OPENAI_API_KEY=${OPENAI_API_KEY:-}
IA_BASE_URL=${IA_BASE_URL:-https://api.groq.com/openai/v1}
OPENAI_MODELO_CHAT=${OPENAI_MODELO_CHAT:-openai/gpt-oss-120b}
OPENAI_MODELO_STT=${OPENAI_MODELO_STT:-whisper-large-v3}
EOF
chown 10001:10001 "$ENV_FILE" || true
chmod 640 "$ENV_FILE"

# --- 2) Sessão no Redis database 1 (VERO herda do php.ini; sem mudança de código)
if [ -n "${REDIS_PASSWORD_VAL}" ]; then
  cat > "$SESS_INI" <<EOF
session.save_handler = redis
session.save_path = "tcp://${REDIS_HOST:-redis}:${REDIS_PORT:-6379}?auth=${REDIS_PASSWORD_VAL}&database=${REDIS_SESSION_DB:-1}"
EOF
  chmod 640 "$SESS_INI"
fi
# (Se optar por sessão em arquivo: não gere o .ini e monte /var/www/vero/storage/sessions.)

# --- 3) Env vars REAIS que o app lê só via getenv/$_ENV -----------------------
export APP_ENV="${APP_ENV:-production}"
export BIOS_LOG_STDERR="${BIOS_LOG_STDERR:-1}"                  # log -> php://stderr (patch do bootstrap.php)
export BIOS_CURL_CAINFO="${BIOS_CURL_CAINFO:-/etc/ssl/certs/ca-certificates.crt}"
export IA_AGENTE_BASE_URL="${IA_AGENTE_BASE_URL:-${IA_BASE_URL:-https://api.groq.com/openai/v1}}"
# Opcionais (passe como env se usar): ANTHROPIC_API_KEY, UPLOAD_MAX_SIZE, BCRYPT_COST.

# --- 4) Entrega ao processo principal (php-fpm) -------------------------------
exec "$@"
