#!/bin/sh
set -e

cd /var/www/html

# Only the `app` container prepares the project; queue and scheduler start after it is healthy (docker-compose.yml)
if [ "$1" = "php-fpm" ]; then
    mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/testing \
        storage/framework/views storage/logs bootstrap/cache

    [ -f .env ] || cp .env.example .env

    if [ ! -f vendor/autoload.php ] || [ composer.json -nt vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
        composer install --no-interaction --prefer-dist
    fi

    grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force --no-interaction

    if ! grep -Eq '^JWT_SECRET=.{32,}' .env; then
        secret=$(php -r 'echo bin2hex(random_bytes(32));')
        sed -i "s|^JWT_SECRET=.*|JWT_SECRET=$secret|" .env
    fi

    php artisan config:clear --no-interaction
    php artisan migrate --force --seed --no-interaction
    [ -e public/storage ] || php artisan storage:link --no-interaction
fi

exec "$@"
