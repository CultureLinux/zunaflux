#!/usr/bin/env sh

set -eu

interval="${ZUNAFLUX_COLLECTION_INTERVAL:-900}"

case "$interval" in
    ''|*[!0-9]*)
        printf '%s\n' 'ZUNAFLUX_COLLECTION_INTERVAL must be a positive integer.' >&2
        exit 64
        ;;
esac

if [ "$interval" -le 0 ]; then
    printf '%s\n' 'ZUNAFLUX_COLLECTION_INTERVAL must be a positive integer.' >&2
    exit 64
fi

while :; do
    if ! php bin/console app:feeds:collect --env=prod --no-interaction; then
        printf '%s\n' 'Feed collection failed; retrying after the configured interval.' >&2
    fi

    sleep "$interval"
done
