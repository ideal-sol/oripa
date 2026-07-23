FROM php:8.4-cli-bookworm

WORKDIR /var/www/backend

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        curl \
        git \
        libpq-dev \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-install pdo_pgsql pcntl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infra/docker/backend/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

COPY apps/api/ ./

RUN composer install --no-interaction --prefer-dist

RUN mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8000
