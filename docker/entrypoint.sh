#!/bin/sh
set -eu

cd /var/www/html

truthy() {
    value=$(printf '%s' "${1:-}" | tr '[:upper:]' '[:lower:]')
    [ "$value" = "1" ] || [ "$value" = "true" ] || [ "$value" = "yes" ] || [ "$value" = "on" ]
}

ensure_writable_paths() {
    mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        /run/php
    chown -R www-data:www-data bootstrap/cache storage /run/php
}

ensure_storage_link() {
    if truthy "${APP_CREATE_STORAGE_LINK:-true}"; then
        rm -rf public/storage
        php artisan storage:link
    fi
}

render_nginx_config() {
    port="${APP_PORT:-8080}"
    sed "s/__APP_PORT__/${port}/g" /etc/nginx/conf.d/default.conf >/tmp/default.conf
    mv /tmp/default.conf /etc/nginx/conf.d/default.conf
}

ensure_package_manifest() {
    if [ ! -f bootstrap/cache/packages.php ] || [ ! -f bootstrap/cache/services.php ]; then
        php artisan package:discover --ansi
    fi
}

run_migrations_if_enabled() {
    if truthy "${APP_RUN_MIGRATIONS:-false}"; then
        php artisan migrate --force
    fi
}

run_seeder_if_enabled() {
    if truthy "${APP_RUN_DB_SEED:-false}"; then
        php artisan db:seed --force
    fi
}

require_app_key() {
    if [ -z "${APP_KEY:-}" ]; then
        echo "APP_KEY is required for container startup." >&2
        exit 1
    fi
}

role="${APP_RUNTIME_ROLE:-web}"

ensure_writable_paths
require_app_key
ensure_package_manifest

case "$role" in
    web)
        render_nginx_config
        ensure_storage_link
        run_migrations_if_enabled
        run_seeder_if_enabled
        php-fpm -D
        exec nginx -g 'daemon off;'
        ;;
    queue)
        run_migrations_if_enabled
        exec php artisan queue:work \
            --queue="${FFMPEG_WORKER_QUEUE:-media-processing}" \
            --timeout="${QUEUE_WORKER_TIMEOUT:-14400}" \
            --tries="${QUEUE_WORKER_TRIES:-1}" \
            --sleep="${QUEUE_WORKER_SLEEP:-3}" \
            --no-interaction
        ;;
    scheduler)
        run_migrations_if_enabled
        exec php artisan schedule:work --no-interaction
        ;;
    shell)
        exec /bin/sh
        ;;
    *)
        exec "$@"
        ;;
esac
