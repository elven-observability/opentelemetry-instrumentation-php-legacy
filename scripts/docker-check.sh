#!/usr/bin/env sh
set -eu

PHP_SERVICE="${1:-php73}"
docker compose run --rm "$PHP_SERVICE" composer validate --strict
docker compose run --rm "$PHP_SERVICE" composer install --no-interaction --prefer-dist
docker compose run --rm "$PHP_SERVICE" composer check
