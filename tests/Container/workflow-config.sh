#!/usr/bin/env sh

set -eu

workflow='.github/workflows/ci.yml'

test -f "$workflow"
grep -Fq 'pull_request:' "$workflow"
grep -Fq 'workflow_call:' "$workflow"
grep -Fq 'main' "$workflow"
if grep -Eq '^[[:space:]]*tags:' "$workflow"; then
    printf '%s\n' 'Tag validation must only run through publication.' >&2
    exit 1
fi
grep -Fq 'mariadb:10.11' "$workflow"
grep -Fq 'php85 vendor/bin/phpunit' "$workflow"
grep -Fq 'tests/JavaScript' "$workflow"

if grep -Eq 'docker/(login|build-push)-action|docker login|docker push' "$workflow"; then
    printf '%s\n' 'CI must not authenticate or push an image.' >&2
    exit 1
fi
