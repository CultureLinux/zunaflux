#!/usr/bin/env sh

set -eu

# Execute the actual preparation step with a read-only Git remote fixture.
step=$(awk '
    /name: Prepare release tags/ { found=1 }
    found && /^        run: \|/ { body=1; next }
    body && /^      - / { exit }
    body { print substr($0, 11) }
' .github/workflows/publish-image.yml)
test -n "$step"

workspace=$(mktemp -d)
trap 'rm -rf "$workspace"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$workspace/bin" "$workspace/runner"
cat > "$workspace/bin/git" <<'GIT'
#!/usr/bin/env sh
set -eu
test "$#" = 3
test "$1" = ls-remote
test "$2" = --tags
test "$3" = origin
printf '%s\n' called >> "$GIT_CALLS"
if [ "${REMOTE_FAILURE:-0}" = 1 ]; then
    exit 1
fi
cat "$REMOTE_FIXTURE"
GIT
chmod +x "$workspace/bin/git"
export PATH="$workspace/bin:$PATH"
export RUNNER_TEMP="$workspace/runner"
export GITHUB_OUTPUT="$workspace/output"
export REMOTE_FIXTURE="$workspace/refs"
export GIT_CALLS="$workspace/calls"
export RELEASE_TAG=v1.2.3
export RELEASE_SHA=1111111111111111111111111111111111111111
export LC_ALL=C
count=0

success() {
    expected=$1
    : > "$GITHUB_OUTPUT"
    sh -eu -c "$step" >/dev/null
    actual=$(cat "$GITHUB_OUTPUT")
    if [ "$actual" != "$expected" ]; then
        printf '%s\n' 'FAIL: incorrect metadata tags' "$actual" >&2
        exit 1
    fi
    count=$((count + 1))
}

failure() {
    : > "$GITHUB_OUTPUT"
    if sh -eu -c "$step" >/dev/null 2>"$workspace/error"; then
        printf '%s\n' 'FAIL: invalid publication preparation succeeded' >&2
        exit 1
    fi
    test ! -s "$GITHUB_OUTPUT"
    count=$((count + 1))
}

printf '%s\n' "$RELEASE_SHA refs/tags/v1.2.3" > "$REMOTE_FIXTURE"
success 'tags<<RELEASE_TAGS
type=raw,value=1.2.3
type=raw,value=1.2
type=raw,value=1
type=raw,value=latest
RELEASE_TAGS'

# Annotated refs must use their peeled commit, not the tag object hash.
printf '%s\n' 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refs/tags/v1.2.3' \
    "$RELEASE_SHA refs/tags/v1.2.3^{}" \
    '2222222222222222222222222222222222222222 refs/tags/v2.0.0' > "$REMOTE_FIXTURE"
success 'tags<<RELEASE_TAGS
type=raw,value=1.2.3
type=raw,value=1.2
type=raw,value=1
RELEASE_TAGS'

printf '%s\n' "$RELEASE_SHA refs/tags/v1.2.3" \
    '2222222222222222222222222222222222222222 refs/tags/v1.2.4' > "$REMOTE_FIXTURE"
success 'tags<<RELEASE_TAGS
type=raw,value=1.2.3
RELEASE_TAGS'

RELEASE_TAG=v1.3.0-rc.1
printf '%s\n' "$RELEASE_SHA refs/tags/$RELEASE_TAG" > "$REMOTE_FIXTURE"
success 'tags<<RELEASE_TAGS
type=raw,value=1.3.0-rc.1
RELEASE_TAGS'
RELEASE_TAG=v1.2.3

printf '%s\n' '2222222222222222222222222222222222222222 refs/tags/v1.2.3' > "$REMOTE_FIXTURE"
failure
printf '%s\n' "$RELEASE_SHA refs/tags/v1.2.4" > "$REMOTE_FIXTURE"
failure
: > "$REMOTE_FIXTURE"
failure
printf '%s\n' "$RELEASE_SHA refs/tags/v1.2.3" \
    '2222222222222222222222222222222222222222 refs/tags/v1.2.3^{}' > "$REMOTE_FIXTURE"
failure
REMOTE_FAILURE=1
export REMOTE_FAILURE
failure
REMOTE_FAILURE=0

# Invalid versions must fail before even reading the remote.
: > "$GIT_CALLS"
RELEASE_TAG=vtest
failure
test ! -s "$GIT_CALLS"
printf '%s\n' "Release workflow: $count scenarios passed."
