#!/bin/sh
# tools/ci/quality-verdict passes only when every job it stands for ran and
# passed, or, for a release-please pull request, when exactly the static lane
# ran and the coverage jobs were skipped.
set -u
HERE="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
CHECK="${QUALITY_VERDICT_CHECK:-$HERE/../../tools/ci/quality-verdict}"
failures=0
out="$(mktemp)"
trap 'rm -f "$out"' EXIT

# expect label expected-status message lanes shards merge [--release-pr]
expect() {
    label=$1 want=$2 says=$3
    shift 3
    sh "$CHECK" "$@" >"$out" 2>&1
    s=$?
    if [ "$s" -eq "$want" ] && grep -qF -- "$says" "$out"; then
        printf 'ok   %s\n' "$label"
    else
        printf 'FAIL %s (status %s, wanted %s saying: %s)\n' "$label" "$s" "$want" "$says"
        sed 's/^/     /' "$out"
        failures=$((failures + 1))
    fi
}

all='expected lanes, shards and merge to be: success success success'
release='expected lanes, shards and merge to be: success skipped skipped'

expect 'everything passed' 0 'passed' success success success
expect 'a release pull request runs static alone' 0 'passed' success skipped skipped --release-pr
expect 'skipped coverage is a failure for any other pull request' 1 "$all" success skipped skipped
expect 'a cancelled shard is a failure' 1 "$all" success cancelled success
expect 'a failed lane is a failure' 1 "$all" failure success success
expect 'a skipped merge is a failure' 1 "$all" success success skipped
expect 'a release pull request whose lane failed is a failure' 1 "$release" failure skipped skipped --release-pr
expect 'a release pull request that ran coverage is not what was expected' 1 "$release" success success success --release-pr
expect 'an empty lanes result is a failure' 1 "$all" '' success success
expect 'an empty shards result is a failure' 1 "$all" success '' success
expect 'an empty merge result is a failure' 1 "$all" success success ''
expect 'an empty result fails a release pull request too' 1 "$release" success '' skipped --release-pr
expect 'a stray fourth argument is a usage error' 2 'usage:' success success success --release-bot
expect 'too few results are a usage error' 2 'usage:' success success

[ "$failures" -eq 0 ]
