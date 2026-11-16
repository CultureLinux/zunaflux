#!/usr/bin/env sh
set -eu
# Validate the dedicated target and schema before any HTTP process is started.
php85 tests/Browser/fixtures.php check
exec php85 -S 127.0.0.1:8175 -t public tests/Browser/router.php
