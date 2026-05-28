FROM composer:2.7 AS composer

FROM php:7.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zip libzip-dev libxml2-dev \
    && docker-php-ext-install zip soap \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app
