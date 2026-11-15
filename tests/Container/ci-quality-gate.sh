#!/usr/bin/env sh

set -eu

# Execute the actual final gate, rather than duplicate its decision logic.
gate=$(awk '
    /name: Require all mandatory validations/ { found=1 }
    found && /^        run: \|/ { body=1; next }
    body { print substr($0, 11) }
' .github/workflows/ci.yml)
test -n "$gate"

check() {
    expected=$1
    shift
    if env "$@" sh -eu -c "$gate"; then
        actual=0
    else
        actual=1
    fi
    if [ "$actual" != "$expected" ]; then
        printf '%s\n' "Unexpected quality gate result: $*" >&2
        exit 1
    fi
}

export PHP_RESULT=success JAVASCRIPT_RESULT=success ASSETS_RESULT=success
export MIGRATIONS_RESULT=success
export DETECT_RESULT=success DOCKER_ENABLED=true DOCKER_RESULT=success
check 0
check 0 DOCKER_ENABLED=false DOCKER_RESULT=skipped

for result in failure cancelled skipped; do
    for variable in PHP_RESULT JAVASCRIPT_RESULT ASSETS_RESULT MIGRATIONS_RESULT DETECT_RESULT DOCKER_RESULT; do
        check 1 "$variable=$result"
    done
done
check 1 DOCKER_ENABLED=false DOCKER_RESULT=success
check 1 DOCKER_ENABLED= DOCKER_RESULT=skipped
printf '%s\n' 'Quality gate: 22 scenarios passed.'
