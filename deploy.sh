#!/bin/bash
set -euo pipefail

APP_NAME="all-notifications"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR" || exit 1

if [ -f "$SCRIPT_DIR/.env" ]; then
  set -o allexport
  # shellcheck source=/dev/null
  source "$SCRIPT_DIR/.env"
  set +o allexport
fi

: "${REMOTE_SERVER:?REMOTE_SERVER не задан в .env}"
: "${REMOTE_USERNAME:?REMOTE_USERNAME не задан в .env}"
: "${REMOTE_DIR:?REMOTE_DIR не задан в .env}"
: "${ADMIN_LOGIN:?ADMIN_LOGIN не задан в .env}"
: "${ADMIN_PASSWORD:?ADMIN_PASSWORD не задан в .env}"
: "${WEB_PUSH_PUBLIC_KEY:?WEB_PUSH_PUBLIC_KEY не задан в .env}"
: "${WEB_PUSH_PRIVATE_KEY:?WEB_PUSH_PRIVATE_KEY не задан в .env}"

REMOTE="${REMOTE_USERNAME}@${REMOTE_SERVER}"
COMPOSER_IMAGE="${COMPOSER_IMAGE:-composer:2}"

if [ -n "${REMOTE_PORT:-}" ]; then
  RSYNC_SSH="ssh -p ${REMOTE_PORT}"
else
  RSYNC_SSH="ssh"
fi

echo "🚀 Деплой ${APP_NAME} → ${REMOTE}:${REMOTE_DIR}"

echo "📦 npm install..."
npm install

if command -v composer >/dev/null 2>&1; then
  echo "📦 composer install (локально)..."
  composer install --no-dev --optimize-autoloader --no-interaction
elif command -v docker >/dev/null 2>&1; then
  echo "📦 composer install (docker: ${COMPOSER_IMAGE})..."
  docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$SCRIPT_DIR:/app" \
    -w /app \
    "$COMPOSER_IMAGE" \
    install --no-dev --optimize-autoloader --no-interaction
else
  echo "❌ Нужен composer или docker для установки PHP-зависимостей"
  exit 1
fi

echo "🔨 npm run build..."
export NODE_ENV=production
npm run build

if [ ! -d "$SCRIPT_DIR/dist" ]; then
  echo "❌ Сборка не удалась: dist не создан"
  exit 1
fi

echo "✅ Сборка завершена"

echo "📤 rsync dist/ → сервер..."
rsync -avz --delete \
  -e "$RSYNC_SSH" \
  "$SCRIPT_DIR/dist/" "${REMOTE}:${REMOTE_DIR}/"

REMOTE_WEB_USER="${REMOTE_WEB_USER:-www-data}"

run_ssh() {
  if [ -n "${REMOTE_PORT:-}" ]; then
    ssh -p "$REMOTE_PORT" "$REMOTE" "$@"
  else
    ssh "$REMOTE" "$@"
  fi
}

echo "🔧 Права на .env и cron.sh..."
run_ssh "if [ -f '${REMOTE_DIR}/.env' ]; then chown '${REMOTE_WEB_USER}:${REMOTE_WEB_USER}' '${REMOTE_DIR}/.env' && chmod 640 '${REMOTE_DIR}/.env'; fi && chmod +x '${REMOTE_DIR}/cron.sh'"

echo "🎉 Деплой завершён: ${REMOTE}:${REMOTE_DIR}"
