# RELEASE_TAG is a Git tag; stdin is the current remote tag inventory.
# stdout is a list of Docker tags, never metadata-action configuration.

function valid(tag,    version, prerelease, identifiers, count, i) {
    if (tag !~ /^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$/) {
        return 0
    }
    version = substr(tag, 2)
    if (length(version) > 128) {
        return 0
    }
    prerelease = version
    if (sub(/^[0-9]+\.[0-9]+\.[0-9]+-/, "", prerelease)) {
        count = split(prerelease, identifiers, ".")
        for (i = 1; i <= count; i++) {
            if (identifiers[i] ~ /^0[0-9]+$/) {
                return 0
            }
        }
    }
    return 1
}

# Older workflows accepted SemVer build metadata. Preserve its precedence in
# the inventory, although new publication tags cannot contain Docker-unsafe '+'.
function comparable_tag(tag,    position, metadata) {
    position = index(tag, "+")
    if (position) {
        metadata = substr(tag, position + 1)
        if (metadata !~ /^[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*$/) {
            return ""
        }
        tag = substr(tag, 1, position - 1)
    }
    return valid(tag) ? tag : ""
}

# Compare decimal strings without floating-point conversion or integer overflow.
function compare_integer(left, right) {
    if (length(left) != length(right)) {
        return length(left) > length(right) ? 1 : -1
    }
    if ("x" left == "x" right) {
        return 0
    }
    return ("x" left > "x" right) ? 1 : -1
}

function newer(candidate, current,    i, result) {
    for (i = 1; i <= 3; i++) {
        result = compare_integer(candidate[i], current[i])
        if (result != 0) {
            return result > 0
        }
    }
    return 0
}

function fail(message) {
    print message > "/dev/stderr"
    failed = 1
    exit 1
}

BEGIN {
    tag = ENVIRON["RELEASE_TAG"]
    validate_only = ENVIRON["RELEASE_VALIDATE_ONLY"] == "1"
    if (!valid(tag)) {
        fail("Invalid publication tag: expected vMAJOR.MINOR.PATCH[-prerelease], no leading numeric zeros or +build, at most 128 Docker tag characters.")
    }
    version = substr(tag, 2)
    if (validate_only) {
        print version
        exit
    }
    stable = version !~ /-/
    split(version, current, ".")
    minor_alias = major_alias = latest_alias = 1
}

{
    if ($0 == tag) {
        seen = 1
    }
    historical_tag = comparable_tag($0)
    if (!stable || historical_tag == "" || historical_tag ~ /-/) {
        next
    }
    split(substr(historical_tag, 2), candidate, ".")
    if (newer(candidate, current)) {
        latest_alias = 0
        if ("x" candidate[1] == "x" current[1]) {
            major_alias = 0
            if ("x" candidate[2] == "x" current[2]) {
                minor_alias = 0
            }
        }
    }
}

END {
    if (!failed && !validate_only) {
        if (!seen) {
            fail("Current release tag is absent from the remote inventory; refusing publication.")
        }
        print version
        if (stable) {
            if (minor_alias) {
                print current[1] "." current[2]
            }
            if (major_alias) {
                print current[1]
            }
            if (latest_alias) {
                print "latest"
            }
        }
    }
}
