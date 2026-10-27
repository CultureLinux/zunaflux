#!/usr/bin/env sh

set -eu

workflow='.github/workflows/publish-image.yml'

test -f "$workflow"
grep -Fq 'uses: ./.github/workflows/ci.yml' "$workflow"
grep -Fq 'clinuxfr/zunaflux' "$workflow"
grep -Fq 'docker/setup-qemu-action' "$workflow"
grep -Fq 'docker/setup-buildx-action' "$workflow"
grep -Fq 'docker/login-action' "$workflow"
grep -Fq 'DOCKERHUB_TOKEN' "$workflow"
grep -Fq 'linux/amd64,linux/arm64' "$workflow"
grep -Fq "'v*'" "$workflow"

if grep -Fq 'pull_request:' "$workflow"; then
    printf '%s\n' 'Publication must not run for pull requests.' >&2
    exit 1
fi
