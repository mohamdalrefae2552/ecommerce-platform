# syntax=docker/dockerfile:1.7
# =============================================================================
# backend/api — Laravel 13 on PHP-FPM 8.4 (docs/01 section 2, ADR-0010).
#
# Targets used by compose.yaml:
#   runtime  php-fpm on :9000, production dependencies only, non-root.
#   test     development dependencies + composer (make verify-api / test-api).
#
# Stages:
#   base       PHP + extensions (grpc in its own cached layer: slow C build).
#   composer   base + pinned composer binary (build and test tooling only).
#   vendor     production `composer install` (no dev packages).
#   runtime    base + application + production vendor/.
#   test       composer + application + full vendor/.
# =============================================================================

ARG PHP_IMAGE=php:8.4.27-fpm-alpine3.24

# ---------------------------------------------------------------- base --
FROM ${PHP_IMAGE} AS base

# mlocati/docker-php-extension-installer 2.13.1 (ADR-0010), verified by checksum.
ADD --chmod=0755 \
    --checksum=sha256:3c3f3de26458405199f1f3a4c7130fdd2056daf596c05512badd769a77019b59 \
    https://github.com/mlocati/docker-php-extension-installer/releases/download/2.13.1/install-php-extensions \
    /usr/local/bin/install-php-extensions

# ext-grpc compiles the gRPC C core (several minutes): keep it in its own layer
# so changes to the other extensions do not rebuild it. PECL builds run make
# serially unless MAKEFLAGS asks for parallel jobs.
RUN MAKEFLAGS="-j$(nproc)" install-php-extensions grpc-1.85.0

# sodium (lcobucci/jwt EdDSA) is already compiled into the official image.
RUN install-php-extensions pdo_pgsql intl gd opcache

# fcgi provides cgi-fcgi for the php-fpm-healthcheck script.
RUN apk add --no-cache fcgi

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=0755 docker/bin/php-fpm-healthcheck /usr/local/bin/php-fpm-healthcheck

# Default worker count; compose overrides it with PHP_FPM_MAX_CHILDREN.
ENV PHP_FPM_MAX_CHILDREN=16

WORKDIR /app

# ------------------------------------------------------------ composer --
FROM base AS composer

# Composer 2.11.1 (latest stable on 2026-11-03), verified by checksum.
ADD --chmod=0755 \
    --checksum=sha256:6f1b891e1d4927d62740276c540fb490e47689e19e102c49e8da849794882f8c \
    https://getcomposer.org/download/2.11.1/composer.phar \
    /usr/local/bin/composer

# unzip lets composer extract dist archives without ext-zip.
RUN apk add --no-cache unzip

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_ALLOW_SUPERUSER=1

# -------------------------------------------------------------- vendor --
FROM composer AS vendor

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
 && php artisan package:discover --ansi

# ------------------------------------------------------------- runtime --
FROM base AS runtime

# Production opcache: never stat files, the image is immutable.
COPY docker/php/conf.d/opcache-production.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"

COPY --from=vendor --chown=root:root /app /app

# Only storage/ and bootstrap/cache are writable by the application user; the
# product-images and jwt-keys volumes inherit this ownership when created.
RUN mkdir -p storage/app/public/products storage/keys storage/framework/cache \
             storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod 0700 storage/keys

USER www-data

EXPOSE 9000
CMD ["php-fpm"]

# ---------------------------------------------------------------- test --
FROM composer AS test

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer/cache \
    composer install --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --no-scripts \
 && php artisan package:discover --ansi \
 && mkdir -p storage/app/public/products storage/keys storage/framework/cache \
             storage/framework/views storage/logs bootstrap/cache \
 && mkdir -p /tmp/composer \
 && chown -R www-data:www-data /app /tmp/composer

USER www-data

CMD ["composer", "test"]
