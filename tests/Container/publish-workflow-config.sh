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
grep -Fq 'needs: validate-version' "$workflow"
grep -Fq 'flavor: latest=false' "$workflow"
grep -Fq 'queue: max' "$workflow"
grep -Fq 'cancel-in-progress: false' "$workflow"
grep -Fq '${{ steps.version.outputs.tags }}' "$workflow"

if grep -Fq 'type=semver' "$workflow"; then
    printf '%s\n' 'Alias selection must use the tested release policy, not automatic SemVer aliases.' >&2
    exit 1
fi

if grep -Fq 'pull_request:' "$workflow"; then
    printf '%s\n' 'Publication must not run for pull requests.' >&2
    exit 1
fi

if grep -Eq '^[[:space:]]*(branches|branches-ignore|workflow_dispatch|workflow_call|schedule):' "$workflow"; then
    printf '%s\n' 'Publication must only run for version tag pushes.' >&2
    exit 1
fi
