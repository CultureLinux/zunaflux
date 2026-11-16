#!/usr/bin/env sh
set -eu

# Synthetic values only: exercise the rendered configuration, never docker/.env.
export ZUNAFLUX_MASTODON_TOKEN_KEY=restore-test-key-not-a-real-secret
export ZUNAFLUX_PUBLIC_BASE_URL=https://restore.example.test
config=$(docker compose --env-file docker/env.test -f compose.yaml config)
for service in app worker collector notifications; do
    section=$(printf '%s\n' "$config" | awk -v target="$service" '
        $0 == "  " target ":" { found=1; next }
        found && /^  [a-z]/ { exit }
        found { print }
    ')
    printf '%s\n' "$section" | grep -Fq 'ZUNAFLUX_MASTODON_TOKEN_KEY: restore-test-key-not-a-real-secret'
    printf '%s\n' "$section" | grep -Fq 'ZUNAFLUX_PUBLIC_BASE_URL: https://restore.example.test'
done
printf '%s\n' 'Private configuration propagated to all application services.'
