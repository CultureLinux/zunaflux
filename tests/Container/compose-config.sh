#!/usr/bin/env sh

set -eu

test -f compose.yaml
test -f docker/env.test

config="$(docker compose --env-file docker/env.test -f compose.yaml config)"

for service in database migrations app assets web worker collector; do
    printf '%s\n' "$config" | grep -Fq "  $service:"
done

printf '%s\n' "$config" | grep -Fq 'image: mariadb:10.11'
printf '%s\n' "$config" | grep -Fq 'published: "8080"'
printf '%s\n' "$config" | grep -Fq 'doctrine:migrations:migrate'
printf '%s\n' "$config" | awk '/^  app:/{in_app=1; next} /^  [a-z]/{in_app=0} in_app' | grep -Fq 'service_completed_successfully'
printf '%s\n' "$config" | awk '/^  worker:/{in_worker=1; next} /^  [a-z]/{in_worker=0} in_worker' | grep -Fq 'service_completed_successfully'
printf '%s\n' "$config" | awk '/^  collector:/{in_collector=1; next} /^  [a-z]/{in_collector=0} in_collector' | grep -Fq 'service_completed_successfully'

if printf '%s\n' "$config" | awk '/^  app:/{in_app=1; next} /^  [a-z]/{in_app=0} in_app' | grep -Fq 'published:'; then
    printf '%s\n' 'The PHP-FPM app service must not publish a host port.' >&2
    exit 1
fi
