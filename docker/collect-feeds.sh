#!/usr/bin/env sh

set -eu

interval="${ZUNAFLUX_COLLECTION_INTERVAL:-900}"
max_failures="${ZUNAFLUX_MAX_CONSECUTIVE_FAILURES:-10}"

log() {
    printf '%s %s\n' "$(date -u +%FT%TZ)" "$*" >&2
}

case "$interval" in
    ''|*[!0-9]*)
        log 'ZUNAFLUX_COLLECTION_INTERVAL must be a positive integer.'
        exit 64
        ;;
esac

if [ "$interval" -le 0 ]; then
    log 'ZUNAFLUX_COLLECTION_INTERVAL must be a positive integer.'
    exit 64
fi

# Exit promptly on SIGTERM/SIGINT instead of waiting for the next interval.
trap 'exit 143' TERM INT

alive=/tmp/zunaflux-alive
failures=0
while :; do
    # The container healthcheck reads this file's age: touched at the start and the end of each cycle.
    touch "$alive"
    cycle_failed=0
    if ! php bin/console app:feeds:collect --env=prod --no-interaction; then
        log 'Feed collection failed.'
        cycle_failed=1
    fi
    if ! php bin/console app:maintenance:purge-articles --max-seconds=60 --env=prod --no-interaction; then
        log 'Article purge failed.'
        cycle_failed=1
    fi

    if [ "$cycle_failed" -eq 0 ]; then
        failures=0
    else
        failures=$((failures + 1))
        if [ "$failures" -ge "$max_failures" ]; then
            log "Giving up after $failures consecutive failed cycles; the container will be restarted."
            exit 1
        fi
    fi

    touch "$alive"
    # `wait` is interruptible by a trapped signal, unlike a foreground sleep.
    sleep "$interval" &
    wait $!
done
