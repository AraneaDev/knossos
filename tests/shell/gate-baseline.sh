#!/bin/sh
# tools/gate-baseline names the commit the gate lane measures against: the
# event's base when CI gives one, else where HEAD left origin/main or main,
# stepping back to HEAD's parent only when HEAD is a commit of the default
# branch. Without any of those it refuses, rather than gating the last commit
# alone or a name that does not resolve.
set -u
HERE="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
CHECK="${GATE_BASELINE_CHECK:-$HERE/../../tools/gate-baseline}"
failures=0
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# Repositories of the test's own, unaffected by whatever the machine's git
# configuration says about identities, signing or the initial branch.
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CEILING_DIRECTORIES="$work"
export GIT_AUTHOR_NAME=gate GIT_AUTHOR_EMAIL=gate@example.invalid
export GIT_COMMITTER_NAME=gate GIT_COMMITTER_EMAIL=gate@example.invalid
unset KNOSSOS_GATE_BASE_REF

# repo NAME BRANCH: a new repository on BRANCH with no commits yet
repo() {
    git init -q -b "$2" "$work/$1"
}

# commit NAME: one more commit on the current branch; prints its id
commit() {
    n=$(git -C "$work/$1" rev-list --all --count 2>/dev/null || echo 0)
    printf '%s\n' "$n" > "$work/$1/file-$n"
    git -C "$work/$1" add . && git -C "$work/$1" commit -q -m "commit $n"
    git -C "$work/$1" rev-parse HEAD
}

# expect LABEL STATUS STDOUT STDERR-SAYS REPO
expect() {
    label=$1 want=$2 prints=$3 says=$4 dir=$5
    sh "$CHECK" "$dir" >"$work/out" 2>"$work/err"
    s=$?
    if [ "$s" -eq "$want" ] && [ "$(cat "$work/out")" = "$prints" ] \
        && { [ -z "$says" ] || grep -qF -- "$says" "$work/err"; }; then
        printf 'ok   %s\n' "$label"
    else
        printf 'FAIL %s (status %s, wanted %s printing "%s" saying: %s)\n' "$label" "$s" "$want" "$prints" "$says"
        sed 's/^/     out: /' "$work/out"
        sed 's/^/     err: /' "$work/err"
        failures=$((failures + 1))
    fi
}

none='gate: no baseline commit found in'

repo orphan work
commit orphan >/dev/null
expect 'a lone commit off the default branch has no baseline' 1 '' "$none" "$work/orphan"
expect 'and the refusal says why' 1 '' 'shares no history with origin/main or main' "$work/orphan"

repo root main
commit root >/dev/null
expect 'a lone commit on the default branch has no parent to compare with' 1 '' 'has no parent to compare with' "$work/root"

repo branch work
commit branch >/dev/null
commit branch >/dev/null
expect 'a branch with no main to fork from is refused, not measured against its last commit' 1 '' "$none" "$work/branch"

repo trunk main
first=$(commit trunk)
commit trunk >/dev/null
expect 'on the default branch the base steps back to the parent' 0 "$first" '' "$work/trunk"

repo fork main
forked=$(commit fork)
git -C "$work/fork" checkout -q -b work
commit fork >/dev/null
commit fork >/dev/null
expect 'a branch is measured from where it left main, not from its last commit' 0 "$forked" '' "$work/fork"

git -C "$work/fork" checkout -q main
moved=$(commit fork)
git -C "$work/fork" update-ref refs/remotes/origin/main "$moved"
git -C "$work/fork" reset -q --hard "$forked"
git -C "$work/fork" checkout -q work
git -C "$work/fork" merge -q --no-edit refs/remotes/origin/main
expect 'origin/main is preferred over a stale local main' 0 "$moved" '' "$work/fork"

KNOSSOS_GATE_BASE_REF=$(git -C "$work/branch" rev-list --max-parents=0 HEAD)
export KNOSSOS_GATE_BASE_REF
expect 'a base named by the event is used as it is' 0 "$KNOSSOS_GATE_BASE_REF" '' "$work/branch"
KNOSSOS_GATE_BASE_REF=0123456789abcdef0123456789abcdef01234567
expect 'a named base the history does not hold is refused' 1 '' 'baseline commit 0123456789abcdef0123456789abcdef01234567 is not in' "$work/branch"
KNOSSOS_GATE_BASE_REF=HEAD
expect 'a named base that is HEAD on the default branch steps back too' 0 "$first" '' "$work/trunk"
unset KNOSSOS_GATE_BASE_REF

mkdir "$work/plain"
expect 'a directory that is no repository has no baseline' 1 '' 'HEAD is not a commit.' "$work/plain"

sh "$CHECK" >/dev/null 2>"$work/err"
s=$?
if [ "$s" -eq 2 ] && grep -qF 'usage: tools/gate-baseline' "$work/err"; then
    printf 'ok   %s\n' 'no repository is a usage error'
else
    printf 'FAIL %s (status %s)\n' 'no repository is a usage error' "$s"
    failures=$((failures + 1))
fi

[ "$failures" -eq 0 ]
