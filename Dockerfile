#syntax=docker/dockerfile:1

# --------------------------------------------------------------------------
# Base : FrankenPHP + extensions communes dev/prod
# --------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
        pdo_pgsql \
        intl \
        opcache \
        zip \
        apcu

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

# --------------------------------------------------------------------------
# Dev : xdebug + php.ini permissif, le code arrive par bind mount
# --------------------------------------------------------------------------
FROM base AS dev

ENV APP_ENV=dev XDEBUG_MODE=off

RUN install-php-extensions xdebug

COPY docker/php/php.dev.ini /usr/local/etc/php/conf.d/zz-grrind.ini

# --------------------------------------------------------------------------
# Prod : code figé dans l'image, opcache preload, worker FrankenPHP
# --------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=prod \
    FRANKENPHP_CONFIG="worker ./public/index.php"

COPY docker/php/php.prod.ini /usr/local/etc/php/conf.d/zz-grrind.ini

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && composer run-script --no-dev post-install-cmd \
    && chmod -R o+rw var
