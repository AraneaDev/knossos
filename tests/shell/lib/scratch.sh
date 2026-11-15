# shellcheck shell=sh
# Sourced first by every script in tests/shell. Everything the script makes,
# and everything a program it runs writes to the temp directory, goes under
# one root that is removed however the script ends: when it finishes, when an
# assertion or `set -u` stops it, and when it is interrupted. A signal alone
# does not run an EXIT trap in every sh, so each one exits explicitly, which
# does.
#
# The root's own permissions come back before it is removed, since a case may
# leave a directory unreadable or a file read-only on purpose. Neither chmod
# nor rm follows a symbolic link it meets inside the tree.
#
# tests/shell/run.sh checks afterwards that nothing was left in the temp
# directory it gave the script.

SCRATCH_ROOT=$(mktemp -d) || exit 1
SCRATCH_ROOT=$(CDPATH='' cd -P -- "$SCRATCH_ROOT" && pwd -P) || exit 1
trap 'chmod -R u+rwx "$SCRATCH_ROOT" 2>/dev/null; rm -rf "$SCRATCH_ROOT"' EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
TMPDIR=$SCRATCH_ROOT
export TMPDIR
