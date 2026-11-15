#!/usr/bin/env sh
set -eu
while :; do
    if ! php bin/console app:notifications:send --env=prod --no-interaction; then
        printf '%s\n' 'Notification processing failed; retrying in one minute.' >&2
    fi
    sleep 60
done
