#!/bin/sh
# tools/mutation-gate decides the sharded mutation run: every shard's log must
# be there, MSI and covered MSI must each clear their own floor in the config,
# and the tested and ignored mutant counts must stay within the population
# file's tolerance. Each case below breaks one of those on a copy of the
# fixture shards and expects the refusal to say which.
set -u
HERE="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
REPO="$HERE/../.."
CHECK="${MUTATION_GATE_CHECK:-$REPO/tools/mutation-gate}"
FIXTURES="$REPO/tests/Fixtures/mutation-gate"
failures=0
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# shards NAME: a fresh copy of the four fixture shard logs; prints its path
shards() {
    mkdir -p "$work/$1"
    cp "$FIXTURES"/shards/mutation-shard-*.json "$work/$1/"
    printf '%s\n' "$work/$1"
}

# set_stat DIR KEY VALUE: one count, in every shard log under DIR
set_stat() {
    for log in "$1"/mutation-shard-*.json; do
        sed "s/\"$2\": [0-9]*/\"$2\": $3/" "$log" > "$log.new" && mv "$log.new" "$log"
    done
}

# expect LABEL STATUS STDERR-SAYS STDERR-NEVER-SAYS SHARDS [CONFIG [POPULATION]]
expect() {
    label=$1 want=$2 says=$3 never=$4 dir=$5
    config=${6:-$FIXTURES/infection.json5}
    population=${7:-$FIXTURES/population.json}
    php "$CHECK" --shards="$dir" --expected=4 --config="$config" --population="$population" >"$work/out" 2>"$work/err"
    s=$?
    if [ "$s" -eq "$want" ] \
        && { [ -z "$says" ] || grep -qF -- "$says" "$work/err"; } \
        && { [ -z "$never" ] || ! grep -qF -- "$never" "$work/err"; }; then
        printf 'ok   %s\n' "$label"
    else
        printf 'FAIL %s (status %s, wanted %s saying: %s)\n' "$label" "$s" "$want" "$says"
        sed 's/^/     out: /' "$work/out"
        sed 's/^/     err: /' "$work/err"
        failures=$((failures + 1))
    fi
}

good=$(shards good)
expect 'four shards above both floors and on the recorded population pass' 0 '' 'mutation-gate:' "$good"
if grep -qF 'MSI: 84.21% (floor 80%)  Covered MSI: 88.89% (floor 85%)' "$work/out"; then
    printf 'ok   %s\n' 'and the pass prints both scores against both floors'
else
    printf 'FAIL %s\n' 'and the pass prints both scores against both floors'
    sed 's/^/     out: /' "$work/out"
    failures=$((failures + 1))
fi

expect "the repository's own infection.json5 has floors the gate can read" 0 '' 'could not read' "$good" "$REPO/infection.json5"

three=$(shards three)
rm "$three/mutation-shard-2.json"
expect 'three of four shard logs is refused, not scored' 1 'expected 4 shard logs' '' "$three"

nested=$(shards nested)
mkdir "$nested/mutation-shard-3" && mv "$nested/mutation-shard-3.json" "$nested/mutation-shard-3/"
expect 'a shard log one directory down still counts' 0 '' 'mutation-gate:' "$nested"

low=$(shards low)
set_stat "$low" killedCount 700
set_stat "$low" escapedCount 180
expect 'MSI below minMsi is refused' 1 'MSI 75.79% is below minMsi 80%' '' "$low"

uncovered=$(shards uncovered)
set_stat "$uncovered" notCoveredCount 0
set_stat "$uncovered" escapedCount 150
expect 'covered MSI below minCoveredMsi is refused while MSI clears minMsi' 1 \
    'covered MSI 84.21% is below minCoveredMsi 85%' 'is below minMsi' "$uncovered"

ignored=$(shards ignored)
set_stat "$ignored" ignoredCount 50
set_stat "$ignored" totalMutantsCount 1025
expect 'twice the ignored mutants is refused although MSI is unchanged' 1 \
    'ignored mutants moved from 100 to 200, more than 10%' 'below' "$ignored"

grown=$(shards grown)
set_stat "$grown" totalMutantsCount 1200
set_stat "$grown" killedCount 980
expect 'a tested population moved past the tolerance is refused' 1 \
    'tested mutants moved from 3800 to 4600, more than 10%' 'below' "$grown"

within=$(shards within)
set_stat "$within" totalMutantsCount 1090
set_stat "$within" killedCount 870
expect 'a tested population moved within the tolerance passes' 0 '' 'mutation-gate:' "$within"

printf '{\n  minMsi: 80,\n}\n' > "$work/no-covered.json5"
expect 'a config without minCoveredMsi is refused, not read as zero' 1 \
    'could not read exactly one numeric minCoveredMsi' '' "$good" "$work/no-covered.json5"

printf '{\n  minMsi: 80,\n  minCoveredMsi: "85",\n}\n' > "$work/quoted.json5"
expect 'a floor that is not a bare number is refused' 1 \
    'could not read exactly one numeric minCoveredMsi' '' "$good" "$work/quoted.json5"

broken=$(shards broken)
printf '{"stats": {}}\n' > "$broken/mutation-shard-1.json"
expect 'a shard log without its counts is refused' 1 'has no count for stats.totalMutantsCount' '' "$broken"

printf '{"tested": 3800}\n' > "$work/thin-population.json"
expect 'a population file without its tolerance is refused' 1 'must hold integer tested' '' "$good" '' "$work/thin-population.json"

if [ "$failures" -gt 0 ]; then
    printf '%s mutation-gate case(s) failed\n' "$failures"
    exit 1
fi
