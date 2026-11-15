#!/bin/sh
# Runs one script from tests/shell with a temp directory of its own, and fails
# when the script leaves anything in it.
#
# Every script sources tests/shell/lib/scratch.sh, which makes its root in the
# temp directory and removes it on exit. What is still here afterwards is a
# root that could not be removed, or a file a program the script ran wrote to
# the temp directory before the script pointed TMPDIR at its root.
#
#   sh tests/shell/run.sh tests/shell/session-brief-hook.sh
set -u

if [ $# -lt 1 ]; then
    echo 'usage: sh tests/shell/run.sh SCRIPT [ARG...]' >&2
    exit 2
fi
given=$(mktemp -d) || exit 1
trap 'chmod -R u+rwx "$given" 2>/dev/null; rm -rf "$given"' EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

TMPDIR=$given sh "$@"
status=$?
left=$(ls -A "$given")
if [ -n "$left" ]; then
    printf 'FAIL %s left this in its temp directory:\n%s\n' "$1" "$left" >&2
    [ "$status" -ne 0 ] || status=1
fi
exit "$status"
