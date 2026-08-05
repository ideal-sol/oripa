FROM php:8.4-cli-bookworm@sha256:138a210978c7767ef2a26f499c413fe6de1c13233c9a5068139565c81191b1ac AS build

WORKDIR /var/www/backend

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install pdo_pgsql pcntl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 /usr/bin/composer /usr/bin/composer
COPY apps/api/ ./

RUN composer install --no-interaction --prefer-dist --no-progress

FROM php:8.4-cli-bookworm@sha256:138a210978c7767ef2a26f499c413fe6de1c13233c9a5068139565c81191b1ac

WORKDIR /var/www/backend

ARG OCI_VERSION
ARG OCI_REVISION
ARG OCI_CREATED
ARG OCI_SOURCE=https://github.com/ideal-sol/oripa

LABEL org.opencontainers.image.version="${OCI_VERSION}" \
      org.opencontainers.image.revision="${OCI_REVISION}" \
      org.opencontainers.image.source="${OCI_SOURCE}" \
      org.opencontainers.image.created="${OCI_CREATED}" \
      org.opencontainers.image.title="Oripa V2 API"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        curl \
        linux-libc-dev=6.1.180-1 \
        libpq5 \
        libzip4 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY infra/docker/backend/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY --from=build /var/www/backend/ ./

RUN mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
