#!/usr/bin/env sh
# Exercise the real runner's refusal paths without a Docker daemon or test DB.
set -eu
task_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
task_tmp=$(mktemp -d /tmp/zunaflux-restore-preflight.XXXXXXXX)
trap 'rm -f "$task_tmp/docker" "$task_tmp/calls" "$task_tmp/output"; rmdir "$task_tmp"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
cat > "$task_tmp/docker" <<'SH'
#!/usr/bin/env sh
printf '%s\n' "$*" >> "$RESTORE_PREFLIGHT_CALLS"
case "$1 $2" in
    'context inspect') printf '%s\n' "$RESTORE_PREFLIGHT_ENDPOINT" ;;
    'image inspect') exit 17 ;;
    *) exit 99 ;;
esac
SH
chmod 0700 "$task_tmp/docker"
export PATH="$task_tmp:$PATH"
export RESTORE_PREFLIGHT_CALLS="$task_tmp/calls"
export RESTORE_PREFLIGHT_ENDPOINT=unix:///var/run/docker.sock

expect_refusal() {
    : > "$task_tmp/calls"
    task_status=0
    sh "$task_root/tests/Restoration/run.sh" local:test > "$task_tmp/output" 2>&1 || task_status=$?
    test "$task_status" = 64
    grep -Fq 'Only a local Unix Docker context is accepted.' "$task_tmp/output"
    if grep -Eq '^(image|compose) ' "$task_tmp/calls"; then
        printf '%s\n' 'Remote endpoint reached image/Compose operations.' >&2
        exit 1
    fi
}

# DOCKER_HOST can override the default context: checking context alone is unsafe.
unset DOCKER_CONTEXT
export DOCKER_HOST=tcp://remote.example.test:2375
expect_refusal

# An explicit Docker context takes precedence over DOCKER_HOST.
export DOCKER_HOST=unix:///var/run/docker.sock
export DOCKER_CONTEXT=remote-test
export RESTORE_PREFLIGHT_ENDPOINT=ssh://remote.example.test
expect_refusal

# A missing local image must stop before project creation or cleanup.
unset DOCKER_CONTEXT
export DOCKER_HOST=unix:///var/run/docker.sock
: > "$task_tmp/calls"
task_status=0
sh "$task_root/tests/Restoration/run.sh" missing:test > "$task_tmp/output" 2>&1 || task_status=$?
test "$task_status" = 17
if grep -Eq '^compose ' "$task_tmp/calls"; then
    printf '%s\n' 'Missing image reached Compose operations.' >&2
    exit 1
fi
printf '%s\n' 'Restoration preflight: remote endpoints and missing local images rejected.'
