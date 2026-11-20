#!/usr/bin/env sh

set -eu

test -f compose.yaml
test -f docker/env.test

config="$(docker compose --env-file docker/env.test -f compose.yaml --profile migrate config)"
default_config="$(docker compose --env-file docker/env.test -f compose.yaml config)"

for service in database migrations app worker collector notifications; do
    printf '%s\n' "$config" | grep -Fq "  $service:"
done

printf '%s\n' "$config" | grep -Fq 'image: mariadb:10.11'
printf '%s\n' "$config" | grep -Fq 'doctrine:migrations:migrate'

printf '%s\n' "$config" | awk '/^  app:/{in_app=1; next} /^  [a-z]/{in_app=0} in_app' | grep -Fq 'published: "8080"'

for removed in '  web:' '  assets:' 'public-assets' 'nginx'; do
    if printf '%s\n' "$config" | grep -Fq "$removed"; then
        printf 'Obsolete Nginx configuration: %s\n' "$removed" >&2
        exit 1
    fi
done

service_block() {
    printf '%s\n' "$config" | awk -v name="  $1:" '$0 == name {found=1; next} /^  [a-z]/ {found=0} found'
}

# Migrations are explicit: a plain "up" never starts them, and no service waits for them.
if printf '%s\n' "$default_config" | grep -Fq '  migrations:'; then
    printf '%s\n' 'The migrations service must sit behind the "migrate" profile.' >&2
    exit 1
fi
for service in app worker collector notifications; do
    if service_block "$service" | grep -Fq 'service_completed_successfully'; then
        printf 'Service %s must not wait for the migrations.\n' "$service" >&2
        exit 1
    fi
done

# The release tag is mandatory: there is no "latest" default.
without_tag="$(mktemp)"
trap 'rm -f "$without_tag"' EXIT
grep -v '^ZUNAFLUX_IMAGE_TAG=' docker/env.test > "$without_tag"
if docker compose --env-file "$without_tag" -f compose.yaml config >/dev/null 2>&1; then
    printf '%s\n' 'ZUNAFLUX_IMAGE_TAG must be required.' >&2
    exit 1
fi
if printf '%s\n' "$config" | grep -Fq 'zunaflux:latest'; then
    printf '%s\n' 'No image may default to the latest tag.' >&2
    exit 1
fi

# The HTTP port is published on the loopback interface by default.
service_block app | grep -Fq 'host_ip: 127.0.0.1'

# Hardening and resource limits of the application containers.
for service in migrations app worker collector notifications; do
    block="$(service_block "$service")"
    printf '%s\n' "$block" | grep -Fq 'read_only: true'
    printf '%s\n' "$block" | grep -Fq 'no-new-privileges:true'
    printf '%s\n' "$block" | awk '/cap_drop:/{found=1; next} found && /- ALL/{ok=1} /^    [a-z]/{found=0} END{exit ok ? 0 : 1}'
done
for service in app worker collector notifications; do
    service_block "$service" | grep -Eq 'mem_limit: "?[0-9]+'
    service_block "$service" | grep -Fq 'cpus:'
    service_block "$service" | grep -Fq 'healthcheck:'
done

# Database images are pinned by digest.
printf '%s\n' "$config" | grep -Eq 'image: mariadb:10\.11@sha256:[0-9a-f]{64}'
