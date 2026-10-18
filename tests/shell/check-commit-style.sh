#!/bin/sh
# The commit-style check accepts exactly the types release-please knows, whatever tools are installed.
set -u
HERE="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
CHECK="${COMMIT_STYLE_CHECK:-$HERE/../../tools/check-commit-style.sh}"
failures=0
nojq="$(mktemp -d)"
trap 'rm -rf "$nojq"' EXIT
mkdir "$nojq/bin"
# Every external tool the check needs, and deliberately not jq.
for tool in bash dirname sed grep sort paste tr cat head; do
    real=$(command -v "$tool") && ln -s "$real" "$nojq/bin/$tool"
done
check() { # label expected-status path-mode subject
    if [ "$3" = nojq ]; then p="$nojq/bin"; else p="$PATH"; fi
    printf '%s\n' "$4" > "$nojq/msg"
    PATH="$p" bash "$CHECK" --file "$nojq/msg" >/dev/null 2>&1
    s=$?
    if { [ "$2" = ok ] && [ "$s" -eq 0 ]; } || { [ "$2" = fail ] && [ "$s" -eq 1 ]; }; then
        printf 'ok   %s\n' "$1"
    else
        printf 'FAIL %s (status %s)\n' "$1" "$s"; failures=$((failures + 1))
    fi
}
for mode in jq nojq; do
    check "$mode: feat accepted" ok "$mode" 'feat: add a thing'
    check "$mode: chore accepted" ok "$mode" 'chore: tidy a thing'
    check "$mode: build rejected" fail "$mode" 'build: bump a thing'
    check "$mode: revert rejected" fail "$mode" 'revert: undo a thing'
    check "$mode: generic rejected" fail "$mode" 'generic: not a type'
done
# In CI (--ci) nothing is exempt: a title can be typed to look like anything
# git writes, and a rebase merge puts a branch's fixup! subjects on main. The
# release subject passes only for release-please's own pull request, which the
# workflow names with --release-pr after tools/ci/release-pr said so.
# ci label expected-status message [flags] subject
ci() {
    label=$1 want=$2 says=$3
    shift 3
    bash "$CHECK" "$@" >"$nojq/out" 2>&1
    s=$?
    if { [ "$want" = ok ] && [ "$s" -eq 0 ]; } ||
        { [ "$want" = fail ] && [ "$s" -eq 1 ] && grep -qF -- "$says" "$nojq/out"; }; then
        printf 'ok   %s\n' "$label"
    else
        printf 'FAIL %s (status %s)\n' "$label" "$s"
        sed 's/^/     /' "$nojq/out"
        failures=$((failures + 1))
    fi
}
type='does not start with a conventional commit type'
ci 'ci: a merge subject is checked' fail "$type" --ci 'Merge the scanners'
ci 'ci: a revert subject is checked' fail "$type" --ci 'Revert "feat: add a thing"'
ci 'ci: fixup! is checked' fail "$type" --ci 'fixup! feat: x'
ci 'ci: squash! is checked' fail "$type" --ci 'squash! fix: y'
ci 'ci: amend! is checked' fail "$type" --ci 'amend! fix: y'
ci 'ci: an em dash is refused' fail 'em dash' --ci 'feat: a — b'
ci 'ci: the release subject is refused outside a release pull request' fail \
    "Only release-please's own pull request may use this subject" --ci 'chore(main): release 0.33.0'
ci 'ci: the release subject passes in a release pull request' ok '' --ci --release-pr 'chore(main): release 0.33.0'
ci 'ci: --release-pr exempts nothing else' fail "$type" --ci --release-pr 'fixup! feat: x'
ci 'ci: an ordinary subject passes' ok '' --ci 'feat: add a thing'
ci 'ci: a title that looks like a flag is still a title' fail "$type" --ci -- '--release-pr'
printf '%s\n' 'fixup! feat: x' >"$nojq/msg"
ci 'ci: --file is checked the same way' fail "$type" --ci --file "$nojq/msg"
# Locally a developer still rebases: the exemptions stay.
ci 'local: fixup! is left for the rebase' ok '' 'fixup! feat: x'
ci 'local: a merge subject is left alone' ok '' "Merge branch 'main' into topic"
ci 'local: the release subject is left alone' ok '' 'chore(main): release 0.33.0'
bash "$CHECK" --release-bot 'feat: x' >/dev/null 2>&1
s=$?
if [ "$s" -eq 2 ]; then printf 'ok   an unknown flag is a usage error\n'; else
    printf 'FAIL an unknown flag exited %s, wanted 2\n' "$s"; failures=$((failures + 1)); fi

[ "$failures" -eq 0 ]
