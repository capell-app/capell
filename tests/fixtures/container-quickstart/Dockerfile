# syntax=docker/dockerfile:1.7

FROM php:8.4-fpm-bookworm AS php

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        curl \
        git \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        dom \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        simplexml \
        xmlreader \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/ /usr/local/

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html

FROM php AS development

CMD ["php", "artisan", "serve", "--no-reload", "--host=0.0.0.0", "--port=8000"]

FROM php AS production

ENV APP_ENV=production
ENV APP_DEBUG=false

COPY --chown=www-data:www-data . .

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
    && npm ci --no-audit --no-fund \
    && npm run build \
    && php artisan capell:package-cache \
    && rm -rf node_modules \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

CMD ["php-fpm", "-F"]

FROM nginx:1.28-alpine AS web

COPY --from=production /var/www/html/public /var/www/html/public
COPY .docker/nginx.conf /etc/nginx/conf.d/default.conf
