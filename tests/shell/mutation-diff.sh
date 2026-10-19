#!/bin/sh
# tools/mutation-diff mutates what changed under src/ since the base: the
# working tree against where HEAD left the base, as Infection's own
# --git-diff-base reads it. A change that is not committed yet is a change, so
# it reaches Infection instead of reading as nothing to mutate. Infection is a
# stub here that records its arguments, so no case runs a mutant.
set -u
HERE="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
CHECK="${MUTATION_DIFF_CHECK:-$HERE/../../tools/mutation-diff}"
failures=0
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CEILING_DIRECTORIES="$work"
export GIT_AUTHOR_NAME=mutation GIT_AUTHOR_EMAIL=mutation@example.invalid
export GIT_COMMITTER_NAME=mutation GIT_COMMITTER_EMAIL=mutation@example.invalid
unset KNOSSOS_MUTATION_FILES

# repo NAME: a repository whose main holds src/A.php, docs/a.md and the stub
repo() {
    dir="$work/$1"
    git init -q -b main "$dir"
    mkdir -p "$dir/src" "$dir/docs" "$dir/vendor/bin"
    printf '<?php\n\nreturn 1;\n' > "$dir/src/A.php"
    printf 'a\n' > "$dir/docs/a.md"
    # shellcheck disable=SC2016 # $argv is a PHP variable, not a shell one
    printf '<?php\n\nfile_put_contents(__DIR__ . "/../../infection-ran", implode(" ", array_slice($argv, 1)));\n' > "$dir/vendor/bin/infection"
    printf 'vendor/\ninfection-ran\n' > "$dir/.gitignore"
    git -C "$dir" add . && git -C "$dir" commit -q -m base
    git -C "$dir" checkout -q -b work
}

# expect LABEL STATUS RAN(yes|no) STDOUT-SAYS REPO [BASE]
expect() {
    label=$1 want=$2 ran=$3 says=$4 dir=$5 base=${6:-main}
    rm -f "$dir/infection-ran"
    (cd "$dir" && sh "$CHECK" "$base") >"$work/out" 2>"$work/err"
    s=$?
    if [ -f "$dir/infection-ran" ]; then got=yes; else got=no; fi
    if [ "$s" -eq "$want" ] && [ "$got" = "$ran" ] && grep -qF -- "$says" "$work/out" "$work/err"; then
        printf 'ok   %s\n' "$label"
    else
        printf 'FAIL %s (status %s, wanted %s; infection ran: %s, wanted %s; saying: %s)\n' "$label" "$s" "$want" "$got" "$ran" "$says"
        sed 's/^/     out: /' "$work/out"
        sed 's/^/     err: /' "$work/err"
        failures=$((failures + 1))
    fi
}

repo clean
expect 'nothing changed under src is nothing to mutate' 0 no 'nothing to mutate' "$work/clean"

repo uncommitted
printf '<?php\n\nreturn 2;\n' > "$work/uncommitted/src/A.php"
expect 'an uncommitted src edit is mutated, not reported as nothing to mutate' 0 yes 'mutating lines changed since main' "$work/uncommitted"
if grep -qF -- '--git-diff-base=main --git-diff-lines' "$work/uncommitted/infection-ran"; then
    printf 'ok   %s\n' 'and Infection is scoped to the changed lines against the same base'
else
    printf 'FAIL %s\n' 'and Infection is scoped to the changed lines against the same base'
    failures=$((failures + 1))
fi

repo committed
printf '<?php\n\nreturn 3;\n' > "$work/committed/src/A.php"
git -C "$work/committed" commit -q -am change
expect 'a committed src edit is mutated' 0 yes 'mutating lines changed since main' "$work/committed"

repo elsewhere
printf 'b\n' > "$work/elsewhere/docs/a.md"
expect 'an uncommitted edit outside src is still nothing to mutate' 0 no 'nothing to mutate' "$work/elsewhere"

repo moved
git -C "$work/moved" checkout -q main
printf '<?php\n\nreturn 4;\n' > "$work/moved/src/A.php"
git -C "$work/moved" commit -q -am "main moves on"
git -C "$work/moved" checkout -q work
printf 'b\n' > "$work/moved/docs/a.md"
expect "a src change the base made after the branch left it is not the branch's to mutate" 0 no 'nothing to mutate' "$work/moved"

repo missing
expect 'a base that does not exist is refused' 64 no "base ref 'nope' does not exist" "$work/missing" nope

if [ "$failures" -gt 0 ]; then
    printf '%s mutation-diff case(s) failed\n' "$failures"
    exit 1
fi
