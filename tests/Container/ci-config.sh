#!/usr/bin/env sh

set -eu

workflow=.github/workflows/ci.yml

grep -Fq 'uses: actions/checkout@v5' "$workflow"
grep -Fq 'uses: actions/setup-node@v5' "$workflow"
grep -Fq 'node-version: 24' "$workflow"
grep -Fq 'name: Prepare Symfony test environment' "$workflow"
grep -Fq 'touch .env' "$workflow"
grep -Fq 'APP_ENV: test' "$workflow"
