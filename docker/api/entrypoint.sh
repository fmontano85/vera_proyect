#!/usr/bin/env bash
set -e

cd /var/www/html

if [ ! -f composer.json ]; then
  echo "No se encontro composer.json en /var/www/html - revisa el bind mount de backend/."
  exit 1
fi

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

if [ ! -d vendor ] && [ "$1" != "composer" ]; then
  composer install --no-interaction --prefer-dist
fi

if [ "$1" = "/usr/bin/supervisord" ] || [ "$1" = "supervisord" ]; then
  until php -r "new PDO('mysql:host=${DB_HOST:-mariadb};port=${DB_PORT:-3306}', '${DB_USERNAME:-vera}', '${DB_PASSWORD:-vera}');" 2>/dev/null; do
    echo "Esperando a MariaDB..."
    sleep 2
  done
  if ! grep -q "^APP_KEY=base64:" .env; then
    php artisan key:generate --ansi --force
  fi
  php artisan migrate --force
fi

exec "$@"
