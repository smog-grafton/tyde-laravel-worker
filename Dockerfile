FROM node:22-bookworm-slim AS frontend

WORKDIR /app

COPY package.json ./
RUN npm install --no-fund --no-audit

COPY resources ./resources
COPY vite.config.js ./

RUN npm run build

FROM php:8.4-fpm-bookworm AS app

ARG DEBIAN_FRONTEND=noninteractive

ENV APP_RUNTIME_ROLE=web \
    APP_PORT=8080 \
    APP_CREATE_STORAGE_LINK=true \
    APP_RUN_MIGRATIONS=false \
    APP_RUN_DB_SEED=false \
    QUEUE_WORKER_SLEEP=3 \
    QUEUE_WORKER_TRIES=1 \
    QUEUE_WORKER_TIMEOUT=14400

WORKDIR /var/www/html

COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    ffmpeg \
    git \
    nginx \
    tini \
    unzip \
    && install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_sqlite \
        xml \
        zip \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist \
    --no-scripts

COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY artisan ./
COPY composer.json composer.lock ./
COPY package.json vite.config.js ./
COPY .env.example ./.env.example
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/zz-worker.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/healthcheck.sh /usr/local/bin/healthcheck.sh
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        /run/php \
    && rm -rf public/storage \
    && chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/healthcheck.sh \
    && chown -R www-data:www-data bootstrap/cache storage /run/php

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 CMD ["/usr/local/bin/healthcheck.sh"]

ENTRYPOINT ["tini", "--", "/usr/local/bin/entrypoint.sh"]
