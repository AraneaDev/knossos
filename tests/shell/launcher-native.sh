#!/bin/sh
# The installed launcher in native mode: it passes arguments, exit status and
# the environment through, honours a caller's data dir, and says what to do
# when no release is installed.
set -u

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
TEMPLATE="$HERE/../../tools/launcher/knossos"
failures=0
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

pass() { printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1"; failures=$((failures + 1)); }

# An installed data dir with a space in its path, holding a stub release that
# prints its arguments and the environment the launcher set.
DATA="$WORK/data dir"
mkdir -p "$DATA/versions/1.0.0/bin"
cat > "$DATA/versions/1.0.0/bin/knossos" <<'STUB'
#!/bin/sh
printf 'args:'
for a in "$@"; do printf '[%s]' "$a"; done
printf '\ndata:%s\nroots:%s\n' "${KNOSSOS_DATA_DIR:-}" "${KNOSSOS_ROOTS_FILE:-}"
exit "${STUB_EXIT:-0}"
STUB
chmod +x "$DATA/versions/1.0.0/bin/knossos"
ln -s versions/1.0.0 "$DATA/current"
printf '{\n  "mode": "native",\n  "version": "1.0.0"\n}\n' > "$DATA/install.json"

# The substitution install.sh performs, done here the simple way.
LAUNCHER="$WORK/knossos"
sed "s|__KNOSSOS_DATA_DIR__|$DATA|" "$TEMPLATE" > "$LAUNCHER"
chmod +x "$LAUNCHER"

out=$(env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$LAUNCHER" scan "a b" '--x=1' 2>&1); status=$?
expected=$(printf 'args:[scan][a b][--x=1]\ndata:%s\nroots:%s/roots.json' "$DATA" "$DATA")
if [ "$status" -eq 0 ] && [ "$out" = "$expected" ]; then
    pass 'passes arguments, including one with a space, and defaults the environment'
else
    fail "arguments or environment (status $status, output $out)"
fi

env -u KNOSSOS_DATA_DIR STUB_EXIT=7 "$LAUNCHER" version >/dev/null 2>&1; status=$?
if [ "$status" -eq 7 ]; then pass 'passes the exit status through'; else fail "exit status was $status, not 7"; fi

# One-line JSON without a space after the colon reads the same.
printf '{"mode":"native","version":"1.0.0"}\n' > "$DATA/install.json"
out=$(env -u KNOSSOS_DATA_DIR "$LAUNCHER" version 2>&1); status=$?
if [ "$status" -eq 0 ]; then pass 'reads compact install.json'; else fail "compact install.json (status $status, output $out)"; fi

# A caller's data dir wins, and so does a caller's roots file.
OTHER="$WORK/other"
mkdir -p "$OTHER/versions/2.0.0/bin"
cp "$DATA/versions/1.0.0/bin/knossos" "$OTHER/versions/2.0.0/bin/knossos"
ln -s versions/2.0.0 "$OTHER/current"
out=$(KNOSSOS_DATA_DIR="$OTHER" KNOSSOS_ROOTS_FILE="$WORK/my roots.json" "$LAUNCHER" x 2>&1); status=$?
expected=$(printf 'args:[x]\ndata:%s\nroots:%s' "$OTHER" "$WORK/my roots.json")
if [ "$status" -eq 0 ] && [ "$out" = "$expected" ]; then
    pass "honours a caller's KNOSSOS_DATA_DIR and KNOSSOS_ROOTS_FILE"
else
    fail "caller's data dir (status $status, output $out)"
fi

# No current: one line, status 1.
EMPTY="$WORK/empty"
mkdir -p "$EMPTY"
out=$(KNOSSOS_DATA_DIR="$EMPTY" "$LAUNCHER" version 2>&1); status=$?
lines=$(printf '%s\n' "$out" | wc -l | tr -d ' ')
case $out in
    *"re-run the installer"*) said=yes ;;
    *) said=no ;;
esac
if [ "$status" -eq 1 ] && [ "$lines" -eq 1 ] && [ "$said" = yes ]; then
    pass 'a missing current prints one line about re-running the installer and exits 1'
else
    fail "missing current (status $status, lines $lines, output $out)"
fi

# Container mode is not served by this launcher yet.
printf '{"mode": "container"}\n' > "$EMPTY/install.json"
out=$(KNOSSOS_DATA_DIR="$EMPTY" "$LAUNCHER" version 2>&1); status=$?
case $out in
    *"container mode"*) said=yes ;;
    *) said=no ;;
esac
if [ "$status" -eq 1 ] && [ "$said" = yes ]; then pass 'container mode is refused clearly'; else fail "container mode (status $status, output $out)"; fi

# No install.json at all means native: the release runs.
NOJSON="$WORK/nojson"
mkdir -p "$NOJSON/versions/1.0.0/bin"
cp "$DATA/versions/1.0.0/bin/knossos" "$NOJSON/versions/1.0.0/bin/knossos"
ln -s versions/1.0.0 "$NOJSON/current"
out=$(KNOSSOS_DATA_DIR="$NOJSON" "$LAUNCHER" hello 2>&1); status=$?
case $out in 'args:[hello]'*) ran=yes ;; *) ran=no ;; esac
if [ "$status" -eq 0 ] && [ "$ran" = yes ]; then pass 'a missing install.json means native'; else fail "missing install.json (status $status, output $out)"; fi

# No install.json and no release: one line, exit 1.
BARE_DATA="$WORK/baredata"
mkdir -p "$BARE_DATA"
out=$(KNOSSOS_DATA_DIR="$BARE_DATA" "$LAUNCHER" version 2>&1); status=$?
lines=$(printf '%s\n' "$out" | wc -l | tr -d ' ')
if [ "$status" -eq 1 ] && [ "$lines" -eq 1 ]; then pass 'no install.json and no release is one line and exit 1'; else fail "bare data dir (status $status, output $out)"; fi

# An unknown mode: one clear line, exit 1.
printf '{"mode": "weird"}\n' > "$NOJSON/install.json"
out=$(KNOSSOS_DATA_DIR="$NOJSON" "$LAUNCHER" hello 2>&1); status=$?
lines=$(printf '%s\n' "$out" | wc -l | tr -d ' ')
case $out in *"unreadable mode"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$lines" -eq 1 ] && [ "$said" = yes ]; then pass 'an unknown mode is one clear line and exit 1'; else fail "unknown mode (status $status, output $out)"; fi

if [ "$failures" -ne 0 ]; then
    printf '%s failure(s)\n' "$failures"
    exit 1
fi
