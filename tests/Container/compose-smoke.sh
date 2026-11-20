#!/usr/bin/env sh

set -eu

export ZUNAFLUX_IMAGE_TAG="${1:?Usage: compose-smoke.sh IMAGE_TAG}"
# Let Docker allocate a free loopback port; never compete with existing apps.
export ZUNAFLUX_HTTP_BIND=127.0.0.1
export ZUNAFLUX_HTTP_PORT=0
project="zunaflux-smoke-$$"

compose() {
    docker compose -p "$project" --env-file docker/env.test -f compose.yaml -f tests/Container/compose.smoke.yaml "$@"
}

cleanup() {
    result=$?
    trap - EXIT
    if [ "$result" -ne 0 ]; then
        compose logs --tail=100 || true
    fi
    compose down --volumes --remove-orphans
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Migrations are explicit: apply them on purpose, then start the stack.
compose up -d --pull missing --wait database
compose --profile migrate run --rm migrations
compose up -d --pull missing
compose exec -T app php <<'PHP'
<?php
function request(string $path): array
{
    $handle = curl_init('http://127.0.0.1:8080'.$path);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $body = curl_exec($handle);
    return [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body];
}

for ($attempt = 0; $attempt < 60; ++$attempt) {
    [$status, $html] = request('/setup');
    if ($status === 200) {
        break;
    }
    sleep(1);
}
if ($status !== 200 || !str_contains($html, 'Initialiser Zunaflux')) {
    throw new RuntimeException('The Symfony setup page must render successfully.');
}

if (!preg_match('~(?:href|src)="(/assets/[^"?]+)~', $html, $asset)) {
    throw new RuntimeException('The setup page must reference compiled assets.');
}
foreach ([$asset[1], '/images/icone-pwa-192.png', '/manifest.webmanifest'] as $path) {
    [$status, $body] = request($path);
    if ($status !== 200 || $body === '' || str_contains($body, '<!DOCTYPE html>')) {
        throw new RuntimeException('Static asset not served: '.$path);
    }
}

// A real hidden file is mounted at /app/public/.smoke-secret (tests/Container/compose.smoke.yaml):
// it must exist and still never be served.
if (!is_file('/app/public/.smoke-secret')) {
    throw new RuntimeException('The hidden fixture file is missing.');
}
foreach (['/.smoke-secret', '/.env', '/other.php', '/other.php/path'] as $path) {
    [$status] = request($path);
    if ($status !== 404) {
        throw new RuntimeException('Unexpected public access: '.$path);
    }
}
echo "HTTP setup, compiled assets and access restrictions passed.\n";
PHP

app="$(compose ps -q app)"
for attempt in $(seq 1 30); do
    health="$(docker inspect -f '{{.State.Health.Status}}' "$app")"
    if [ "$health" = healthy ]; then
        break
    fi
    sleep 1
done
test "$health" = healthy

for service in app worker collector; do
    container="$(compose ps -q "$service")"
    test -n "$container"
    test "$(docker inspect -f '{{.State.Running}} {{.RestartCount}}' "$container")" = 'true 0'
done
compose ps -a

# Read-only containers: the code cannot be modified, /tmp and the media volume can be written,
# and nothing in the logs reports a write refused by the file system.
for service in app worker collector notifications; do
    container="$(compose ps -q "$service")"
    test "$(docker inspect -f '{{.HostConfig.ReadonlyRootfs}}' "$container")" = true
done
if compose exec -T app sh -c 'touch /app/src/.smoke-write' 2>/dev/null; then
    printf '%s\n' 'The application code must not be writable.' >&2
    exit 1
fi
compose exec -T app sh -c 'touch /tmp/.smoke-write /app/var/media/.smoke-write && rm /tmp/.smoke-write /app/var/media/.smoke-write'
if compose logs 2>&1 | grep -i 'read-only file system'; then
    printf '%s\n' 'A container tried to write to its read-only file system.' >&2
    exit 1
fi

# Health of the worker (heartbeat) and the operational summary.
for attempt in $(seq 1 90); do
    if compose exec -T worker php bin/console app:worker:healthcheck --env=prod >/dev/null 2>&1; then
        worker_ok=1
        break
    fi
    sleep 1
done
test "${worker_ok:-0}" = 1
compose exec -T worker php bin/console app:status --env=prod
