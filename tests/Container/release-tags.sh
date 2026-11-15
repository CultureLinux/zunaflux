#!/usr/bin/env sh

set -eu
export LC_ALL=C

script=.github/scripts/release-tags.awk
count=0

check() {
    name=$1
    tag=$2
    inventory=$3
    expected=$4
    actual=$(printf '%s\n' "$inventory" | RELEASE_TAG="$tag" awk -f "$script")
    if [ "$actual" != "$expected" ]; then
        printf '%s\n' "FAIL: $name" "Expected: $expected" "Actual: $actual" >&2
        exit 1
    fi
    count=$((count + 1))
}

reject() {
    tag=$1
    if output=$(printf '%s\n' "$tag" | RELEASE_TAG="$tag" awk -f "$script" 2>/dev/null); then
        printf '%s\n' "FAIL: accepted invalid publication tag: $tag" >&2
        exit 1
    fi
    test -z "$output"
    count=$((count + 1))
}

check 'first stable' v1.2.3 v1.2.3 '1.2.3
1.2
1
latest'
check 'prerelease has no stable aliases' v1.3.0-rc.1 'v1.2.3
v1.3.0-rc.1' '1.3.0-rc.1'
check 'older patch has no aliases' v1.2.3 'v1.2.4
v1.2.3' '1.2.3'
check 'maintenance updates only its own series' v1.2.4 'v2.0.0
v1.2.4
v1.2.3' '1.2.4
1.2
1'
check 'older minor updates only minor alias' v1.2.4 'v1.3.0
v1.2.4' '1.2.4
1.2'
check 'numeric rather than lexical order' v1.10.0 'v1.9.99
v1.10.0' '1.10.0
1.10
1
latest'
check 'higher prerelease cannot suppress stable aliases' v1.2.3 'v9.0.0-rc.1
v1.2.3' '1.2.3
1.2
1
latest'
check 'invalid historical tags ignored' v1.2.3 'vtest
v01.99.0
v1.2.3
v9.0.0+bad..metadata' '1.2.3
1.2
1
latest'
check 'historical build metadata still reserves global precedence' v1.2.3 'v2.0.0+build.01
v1.2.3' '1.2.3
1.2
1'
check 'historical build metadata still reserves minor precedence' v1.2.3 'v1.2.4+build
v1.2.3' '1.2.3'
check 'zero major follows approved numeric alias rule' v0.2.0 v0.2.0 '0.2.0
0.2
0
latest'
check 'mixed alphanumeric prerelease' v1.2.3-0.alpha-01.X v1.2.3-0.alpha-01.X '1.2.3-0.alpha-01.X'
check 'very large integers compared exactly' v1.9007199254740992.1 'v1.9007199254740993.0
v1.9007199254740992.1' '1.9007199254740992.1
1.9007199254740992'
check 'duplicate or shuffled inventory is harmless' v1.2.3 'v0.9.0
v1.2.3
v0.9.0
v1.2.3' '1.2.3
1.2
1
latest'

for tag in vtest 1.2.3 v1.2 v1.2.3.4 v01.2.3 v1.02.3 v1.2.03 v1.2.3-01 v1.2.3-rc.01 v1.2.3- v1.2.3-rc..1 v1.2.3+build v1.2.3_rc.1 'v1.2.3/rc' 'v1.2.3 rc' 'v1.2.3-rc,enable=true' ''; do
    reject "$tag"
done
reject 'v1.2.3
v2.0.0'
long_prerelease=$(printf '%0123d' 0 | tr '0' 'a')
reject "v1.2.3-$long_prerelease"
maximum_prerelease=$(printf '%0122d' 0 | tr '0' 'a')
check 'maximum Docker tag length accepted' "v1.2.3-$maximum_prerelease" \
    "v1.2.3-$maximum_prerelease" "1.2.3-$maximum_prerelease"
check 'large numeric patch' v1.2.9007199254740992 'v1.2.9007199254740993
v1.2.9007199254740992' '1.2.9007199254740992'
check 'large numeric major' v9007199254740992.0.0 'v9007199254740993.0.0
v9007199254740992.0.0' '9007199254740992.0.0
9007199254740992.0
9007199254740992' 

# A missing/incomplete inventory must not restore all stable aliases.
if output=$(printf '%s\n' v1.2.2 | RELEASE_TAG=v1.2.3 awk -f "$script" 2>/dev/null); then
    printf '%s\n' 'FAIL: missing current tag accepted' >&2
    exit 1
fi
test -z "$output"
count=$((count + 1))

output=$(RELEASE_TAG=v1.2.3 RELEASE_VALIDATE_ONLY=1 awk -f "$script" </dev/null)
test "$output" = 1.2.3
count=$((count + 1))
if output=$(RELEASE_TAG=vtest RELEASE_VALIDATE_ONLY=1 awk -f "$script" </dev/null 2>/dev/null); then
    printf '%s\n' 'FAIL: validation-only mode accepted invalid tag' >&2
    exit 1
fi
test -z "$output"
count=$((count + 1))
printf '%s\n' "Release tags: $count scenarios passed."
