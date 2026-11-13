#!/usr/bin/env sh

set -eu

export ZUNAFLUX_IMAGE_TAG="${1:?Usage: compose-smoke.sh IMAGE_TAG}"
# Let Docker allocate a free loopback port; never compete with existing apps.
export ZUNAFLUX_HTTP_PORT=127.0.0.1:0
project="zunaflux-smoke-$$"

compose() {
    docker compose -p "$project" --env-file docker/env.test -f compose.yaml "$@"
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

// Check a real hidden file, not just a path which would already return 404.
file_put_contents('/app/public/.smoke-secret', 'must-not-be-served');
try {
    foreach (['/.smoke-secret', '/.env', '/other.php', '/other.php/path'] as $path) {
        [$status] = request($path);
        if ($status !== 404) {
            throw new RuntimeException('Unexpected public access: '.$path);
        }
    }
} finally {
    unlink('/app/public/.smoke-secret');
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
