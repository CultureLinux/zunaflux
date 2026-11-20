#!/usr/bin/env sh
set -eu

max_failures="${ZUNAFLUX_MAX_CONSECUTIVE_FAILURES:-10}"

log() {
    printf '%s %s\n' "$(date -u +%FT%TZ)" "$*" >&2
}

trap 'exit 143' TERM INT

alive=/tmp/zunaflux-alive
failures=0
while :; do
    touch "$alive"
    if php bin/console app:notifications:send --env=prod --no-interaction; then
        failures=0
    else
        failures=$((failures + 1))
        log "Notification processing failed ($failures consecutive); retrying in one minute."
        if [ "$failures" -ge "$max_failures" ]; then
            log 'Giving up; the container will be restarted.'
            exit 1
        fi
    fi
    sleep 60 &
    wait $!
done
