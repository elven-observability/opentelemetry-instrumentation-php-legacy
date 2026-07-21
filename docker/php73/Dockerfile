FROM composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS composer

FROM php:7.3-cli@sha256:bd6f2dc7a4e4537e927355c94a74c3022911aa504278f5f0fd94016c90dfaecc

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zip libzip-dev libxml2-dev \
    && docker-php-ext-install zip soap \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app
