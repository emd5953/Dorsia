FROM dunglas/frankenphp:1.13.0-php8.4-bookworm

RUN install-php-extensions pdo_pgsql pgsql redis pcntl sockets intl bcmath zip opcache

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && apt-get clean \
    && find /var/lib/apt/lists -type f -delete

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
