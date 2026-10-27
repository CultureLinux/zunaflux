#!/usr/bin/env sh

set -eu

image="${1:?Usage: docker-image-smoke.sh IMAGE}"

docker run --rm --entrypoint php \
    -e APP_ENV=prod \
    -e APP_DEBUG=0 \
    -e APP_SECRET=container-smoke-secret \
    -e DATABASE_URL='mysql://zunaflux:change-me@database:3306/zunaflux?serverVersion=mariadb-10.11.0&charset=utf8mb4' \
    "$image" -v
docker run --rm --entrypoint test "$image" -f /app/public/assets/manifest.json
docker run --rm --entrypoint php \
    -e APP_ENV=prod \
    -e APP_DEBUG=0 \
    -e APP_SECRET=container-smoke-secret \
    -e DATABASE_URL='mysql://zunaflux:change-me@database:3306/zunaflux?serverVersion=mariadb-10.11.0&charset=utf8mb4' \
    "$image" bin/console about --env=prod --no-debug

docker run --rm --entrypoint frankenphp "$image" validate --config /etc/frankenphp/Caddyfile --adapter caddyfile
