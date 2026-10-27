#!/usr/bin/env sh

set -eu

config=config/packages/test/framework.yaml

test -f "$config"
grep -Fq 'test: true' "$config"
grep -Fq 'session.storage.factory.mock_file' "$config"
