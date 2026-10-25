#!/bin/sh
set -eu
if [ ! -f .env ]; then cp .env.example .env; fi
mkdir -p database/data
touch "$DB_DATABASE"
if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi
php artisan migrate --force
php artisan db:seed --force
exec php artisan serve --host=0.0.0.0 --port=8000
