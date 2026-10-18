#!/bin/sh
# tools/ci/release-rollback removes the draft and puts the release pull
# request back to pending when the gate did not pass, and does nothing when it
# did or when no release was cut. gh is a stub that records what it was asked
# and answers the pull request lookup from STUB_PR.
set -u
HERE="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
CHECK="${RELEASE_ROLLBACK_CHECK:-$HERE/../../tools/ci/release-rollback}"
failures=0
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir "$work/bin"
cat >"$work/bin/gh" <<'EOF'
#!/bin/sh
printf '%s\n' "$*" >>"$STUB_LOG"
case "$*" in
    "api repos/"*"/commits/"*"/pulls --jq .[0].number")
        [ "$STUB_PR" != FAIL ] || exit 1
        printf '%s\n' "$STUB_PR"
        ;;
esac
EOF
chmod +x "$work/bin/gh"
STUB_LOG="$work/calls"
export STUB_LOG

# run expected-status pr [arguments]: runs the check with the stub, output in
# $work/out and the gh calls in $work/calls.
run() {
    want=$1 STUB_PR=$2
    export STUB_PR
    shift 2
    : >"$work/calls"
    PATH="$work/bin:$PATH" sh "$CHECK" --repo AraneaDev/knossos --tag v0.33.0 --sha abc123 "$@" >"$work/out" 2>&1
    status=$?
    [ "$status" -eq "$want" ]
}
ok() { printf 'ok   %s\n' "$1"; }
bad() {
    printf 'FAIL %s (status %s)\n' "$1" "$status"
    sed 's/^/     out: /' "$work/out"
    sed 's/^/     gh:  /' "$work/calls"
    failures=$((failures + 1))
}
says() { grep -qxF -- "$1" "$work/out"; }
asked() { grep -qxF -- "$1" "$work/calls"; }
writes() { grep -v '^api repos/[^ ]*/commits/[^ ]*/pulls --jq' "$work/calls" | grep -c . || true; }

for gate in failure cancelled ''; do
    label="dry run, gate ${gate:-<empty>}: deletes the draft and relabels the pull request"
    if run 0 362 --release-created true --gate-result "$gate" --dry-run &&
        says 'delete draft v0.33.0' && says 'relabel PR 362' && [ "$(writes)" -eq 0 ]; then
        ok "$label"
    else bad "$label"; fi
done

label='for real, gate failure: the draft is deleted and the labels swapped'
if run 0 362 --release-created true --gate-result failure &&
    asked 'release delete v0.33.0 --yes --repo AraneaDev/knossos' &&
    asked 'api -X DELETE repos/AraneaDev/knossos/issues/362/labels/autorelease:%20tagged' &&
    asked 'api -X POST repos/AraneaDev/knossos/issues/362/labels -f labels[]=autorelease: pending'; then
    ok "$label"
else bad "$label"; fi

label='no pull request for the release commit: exits 1 and says to relabel by hand'
if run 1 '' --release-created true --gate-result failure --dry-run &&
    grep -qF "No pull request found for abc123; relabel the Release PR to 'autorelease: pending' by hand." "$work/out" &&
    says 'delete draft v0.33.0'; then
    ok "$label"
else bad "$label"; fi

label='a lookup that answers null is no pull request either'
if run 1 null --release-created true --gate-result failure --dry-run &&
    grep -qF 'No pull request found for abc123' "$work/out"; then
    ok "$label"
else bad "$label"; fi

label='a lookup that fails is no pull request either'
if run 1 FAIL --release-created true --gate-result failure --dry-run &&
    grep -qF 'No pull request found for abc123' "$work/out"; then
    ok "$label"
else bad "$label"; fi

label='gate success: no actions'
if run 0 362 --release-created true --gate-result success &&
    says 'release-rollback: the gate passed; nothing to roll back' && [ ! -s "$work/calls" ]; then
    ok "$label"
else bad "$label"; fi

for created in '' false; do
    label="no release created (${created:-<empty>}): no actions"
    if run 0 362 --release-created "$created" --gate-result failure &&
        says 'release-rollback: no release was created; nothing to roll back' && [ ! -s "$work/calls" ]; then
        ok "$label"
    else bad "$label"; fi
done

label='a missing argument is a usage error'
if run 2 362 --release-created true; then ok "$label"; else bad "$label"; fi

[ "$failures" -eq 0 ]
