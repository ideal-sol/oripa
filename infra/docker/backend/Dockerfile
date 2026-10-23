FROM php:8.4-cli-bookworm@sha256:496b5e02fe9fe44fcfb715023b931048111dc4e0bc719789d83c906be9f04b05 AS build

RUN php -r 'if (preg_match("/^8[.]4[.][0-9]+$/D", PHP_VERSION) !== 1 || PHP_VERSION_ID < 80427) { throw new RuntimeException("Unsupported PHP ".PHP_VERSION); } echo "PHP ".PHP_VERSION.PHP_EOL;'

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

RUN php -r 'if (preg_match("/^8[.]4[.][0-9]+$/D", PHP_VERSION) !== 1 || PHP_VERSION_ID < 80427) { throw new RuntimeException("Unsupported PHP ".PHP_VERSION); } echo "PHP ".PHP_VERSION.PHP_EOL;' \
    && composer install --no-interaction --prefer-dist --no-progress \
    && composer check-platform-reqs \
    && php -r 'if (preg_match("/^8[.]4[.][0-9]+$/D", PHP_VERSION) !== 1 || PHP_VERSION_ID < 80427) { throw new RuntimeException("Unsupported PHP ".PHP_VERSION); } echo "PHP ".PHP_VERSION.PHP_EOL; require "vendor/autoload.php";'

FROM php:8.4-apache-bookworm@sha256:e653ff90d7f6e2e6f81eddb07c864f1eae224ccbf5143f82a44f25e2f110e320 AS production-build

RUN php -r 'if (preg_match("/^8[.]4[.][0-9]+$/D", PHP_VERSION) !== 1 || PHP_VERSION_ID < 80427) { throw new RuntimeException("Unsupported PHP ".PHP_VERSION); } echo "PHP ".PHP_VERSION.PHP_EOL;'

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

RUN php -r 'if (preg_match("/^8[.]4[.][0-9]+$/D", PHP_VERSION) !== 1 || PHP_VERSION_ID < 80427) { throw new RuntimeException("Unsupported PHP ".PHP_VERSION); } echo "PHP ".PHP_VERSION.PHP_EOL;' \
    && composer install \
        --classmap-authoritative \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
    && composer check-platform-reqs --no-dev \
    && php -r 'if (preg_match("/^8[.]4[.][0-9]+$/D", PHP_VERSION) !== 1 || PHP_VERSION_ID < 80427) { throw new RuntimeException("Unsupported PHP ".PHP_VERSION); } echo "PHP ".PHP_VERSION.PHP_EOL; require "vendor/autoload.php";'

FROM php:8.4-apache-bookworm@sha256:e653ff90d7f6e2e6f81eddb07c864f1eae224ccbf5143f82a44f25e2f110e320 AS production

RUN php -r 'if (preg_match("/^8[.]4[.][0-9]+$/D", PHP_VERSION) !== 1 || PHP_VERSION_ID < 80427) { throw new RuntimeException("Unsupported PHP ".PHP_VERSION); } echo "PHP ".PHP_VERSION.PHP_EOL;'

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

ENV APACHE_RUN_DIR=/tmp/apache2 \
    APACHE_LOCK_DIR=/tmp/apache2 \
    APACHE_LOG_DIR=/tmp/apache2

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libpq5 \
        libzip4 \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && sed -ri 's/^Listen 80$/Listen 8000/' /etc/apache2/ports.conf

COPY --from=production-build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=production-build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY infra/docker/backend/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY infra/docker/backend/apache-production.conf /etc/apache2/sites-available/000-default.conf
COPY --from=production-build --chown=www-data:www-data /var/www/backend/ ./

RUN mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /tmp/apache2 \
    && chown -R www-data:www-data storage bootstrap/cache /tmp/apache2

USER www-data

RUN set -eu; \
    php -r 'if (preg_match("/^8[.]4[.][0-9]+$/D", PHP_VERSION) !== 1 || PHP_VERSION_ID < 80427) { throw new RuntimeException("Unsupported PHP ".PHP_VERSION); } echo "PHP ".PHP_VERSION.PHP_EOL; require "vendor/autoload.php";'; \
    test ! -e public/.php-runtime-check.php; \
    printf '%s\n' '<?php if (PHP_SAPI !== "apache2handler" || preg_match("/^8[.]4[.][0-9]+$/D", PHP_VERSION) !== 1 || PHP_VERSION_ID < 80427) { http_response_code(500); exit; } require __DIR__."/../vendor/autoload.php"; echo PHP_VERSION." ".PHP_SAPI;' > public/.php-runtime-check.php; \
    apache2-foreground & apache_pid=$!; \
    trap 'kill "$apache_pid" 2>/dev/null || true; wait "$apache_pid" 2>/dev/null || true; rm -f public/.php-runtime-check.php' EXIT; \
    response="$(curl --fail --silent --show-error --retry 10 --retry-connrefused --retry-delay 1 --max-time 5 http://127.0.0.1:8000/.php-runtime-check.php)"; \
    test "$response" = "$(php -r 'echo PHP_VERSION." apache2handler";')"; \
    printf '%s\n' "$response"

EXPOSE 8000

CMD ["apache2-foreground"]

FROM php:8.4-cli-bookworm@sha256:496b5e02fe9fe44fcfb715023b931048111dc4e0bc719789d83c906be9f04b05 AS preview

RUN php -r 'if (preg_match("/^8[.]4[.][0-9]+$/D", PHP_VERSION) !== 1 || PHP_VERSION_ID < 80427) { throw new RuntimeException("Unsupported PHP ".PHP_VERSION); } echo "PHP ".PHP_VERSION.PHP_EOL;'

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
        linux-libc-dev=6.1.190-1 \
        libpq5 \
        libzip4 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY infra/docker/backend/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY --from=build /var/www/backend/ ./

RUN mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

RUN php -r 'if (preg_match("/^8[.]4[.][0-9]+$/D", PHP_VERSION) !== 1 || PHP_VERSION_ID < 80427) { throw new RuntimeException("Unsupported PHP ".PHP_VERSION); } echo "PHP ".PHP_VERSION.PHP_EOL; require "vendor/autoload.php";'

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
