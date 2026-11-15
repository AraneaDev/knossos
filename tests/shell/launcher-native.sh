#!/bin/sh
# The installed launcher in native mode: it passes arguments, exit status and
# the environment through, finds the release only through the install
# directory baked into it (a caller's KNOSSOS_DATA_DIR says where the graph
# goes, never which release runs), and says what to do when no release is
# installed.
set -u

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
TEMPLATE="$HERE/../../tools/launcher/knossos"
failures=0
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

pass() { printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1"; failures=$((failures + 1)); }

# stub_release DIR VERSION: a stub release under DIR/versions/VERSION, with
# current on it, that prints its arguments and the environment it was given.
stub_release() {
    mkdir -p "$1/versions/$2/bin"
    cat > "$1/versions/$2/bin/knossos" <<STUB
#!/bin/sh
printf 'args:'
for a in "\$@"; do printf '[%s]' "\$a"; done
printf '\\nrelease:$2\\ndata:%s\\nroots:%s\\n' "\${KNOSSOS_DATA_DIR:-}" "\${KNOSSOS_ROOTS_FILE:-}"
exit "\${STUB_EXIT:-0}"
STUB
    chmod +x "$1/versions/$2/bin/knossos"
    ln -s "versions/$2" "$1/current"
}

# An install directory with a space in its path.
DATA="$WORK/data dir"
stub_release "$DATA" 1.0.0
printf '{\n  "mode": "native",\n  "version": "1.0.0"\n}\n' > "$DATA/install.json"

# The substitution install.sh performs, done here the simple way.
LAUNCHER="$WORK/knossos"
sed "s|__KNOSSOS_INSTALL_DIR__|$DATA|" "$TEMPLATE" > "$LAUNCHER"
chmod +x "$LAUNCHER"

out=$(env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$LAUNCHER" scan "a b" '--x=1' 2>&1); status=$?
expected=$(printf 'args:[scan][a b][--x=1]\nrelease:1.0.0\ndata:%s\nroots:%s/roots.json' "$DATA" "$DATA")
if [ "$status" -eq 0 ] && [ "$out" = "$expected" ]; then
    pass 'passes arguments, including one with a space, and defaults the environment'
else
    fail "arguments or environment (status $status, output $out)"
fi

STUB_EXIT=7 "$LAUNCHER" version >/dev/null 2>&1; status=$?
if [ "$status" -eq 7 ]; then pass 'passes the exit status through'; else fail "exit status was $status, not 7"; fi

# One-line JSON without a space after the colon reads the same.
printf '{"mode":"native","version":"1.0.0"}\n' > "$DATA/install.json"
out=$("$LAUNCHER" version 2>&1); status=$?
if [ "$status" -eq 0 ]; then pass 'reads compact install.json'; else fail "compact install.json (status $status, output $out)"; fi

# A symlinked install.json is read through the link, as every release has read it.
mv "$DATA/install.json" "$WORK/record.json"
ln -s "$WORK/record.json" "$DATA/install.json"
out=$(env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE "$LAUNCHER" version 2>&1); status=$?
case $out in *'release:1.0.0'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ]; then pass 'a symlinked install.json in native mode runs the release, as released'; else fail "symlinked install.json (status $status, output $out)"; fi
rm -f "$DATA/install.json"
mv "$WORK/record.json" "$DATA/install.json"

# A caller's KNOSSOS_DATA_DIR says where the graph goes and is passed on, and
# so is a caller's roots file; the release still comes from the install
# directory, even when the foreign directory holds a release of its own.
OTHER="$WORK/other"
stub_release "$OTHER" 2.0.0
out=$(KNOSSOS_DATA_DIR="$OTHER" KNOSSOS_ROOTS_FILE="$WORK/my roots.json" "$LAUNCHER" x 2>&1); status=$?
expected=$(printf 'args:[x]\nrelease:1.0.0\ndata:%s\nroots:%s' "$OTHER" "$WORK/my roots.json")
if [ "$status" -eq 0 ] && [ "$out" = "$expected" ]; then
    pass "a caller's KNOSSOS_DATA_DIR and KNOSSOS_ROOTS_FILE reach the release, which still comes from the install directory"
else
    fail "caller's data dir (status $status, output $out)"
fi

# A data dir that does not exist (the quality image exports /data) is no reason to fail.
out=$(env -u KNOSSOS_ROOTS_FILE KNOSSOS_DATA_DIR="$WORK/no such dir" "$LAUNCHER" x 2>&1); status=$?
expected=$(printf 'args:[x]\nrelease:1.0.0\ndata:%s\nroots:%s/roots.json' "$WORK/no such dir" "$WORK/no such dir")
if [ "$status" -eq 0 ] && [ "$out" = "$expected" ]; then
    pass 'a caller data dir that does not exist still runs the installed release, roots default beside the graph'
else
    fail "missing caller data dir (status $status, output $out)"
fi

# KNOSSOS_INSTALL_DIR, the test override, replaces the baked install directory.
out=$(KNOSSOS_INSTALL_DIR="$OTHER" "$LAUNCHER" x 2>&1); status=$?
case $out in *release:2.0.0*) moved=yes ;; *) moved=no ;; esac
if [ "$status" -eq 0 ] && [ "$moved" = yes ]; then pass 'KNOSSOS_INSTALL_DIR overrides the baked install directory'; else fail "install dir override (status $status, output $out)"; fi

# `serve` goes through the release's mcp-serve wrapper when it has one, with
# the arguments after `serve`; a release without one runs bin/knossos serve.
out=$("$LAUNCHER" serve --allow-root=/x 2>&1); status=$?
case $out in 'args:[serve][--allow-root=/x]'*) plain=yes ;; *) plain=no ;; esac
mkdir -p "$DATA/versions/1.0.0/launcher"
cat > "$DATA/versions/1.0.0/launcher/mcp-serve" <<'WRAP'
#!/bin/sh
printf 'wrapper:'
for a in "$@"; do printf '[%s]' "$a"; done
printf '\n'
WRAP
chmod +x "$DATA/versions/1.0.0/launcher/mcp-serve"
wrapped=$("$LAUNCHER" serve --allow-root=/x 2>&1)
other=$("$LAUNCHER" version 2>&1)
case $other in 'args:[version]'*) direct=yes ;; *) direct=no ;; esac
if [ "$status" -eq 0 ] && [ "$plain" = yes ] && [ "$wrapped" = 'wrapper:[--allow-root=/x]' ] && [ "$direct" = yes ]; then
    pass 'serve runs the release mcp-serve wrapper when there is one, every other command runs bin/knossos'
else
    fail "serve routing: plain $out, wrapped $wrapped, other $other"
fi
rm -rf "${DATA:?}/versions/1.0.0/launcher"

# No current: one line, status 1.
EMPTY="$WORK/empty"
mkdir -p "$EMPTY"
out=$(KNOSSOS_INSTALL_DIR="$EMPTY" "$LAUNCHER" version 2>&1); status=$?
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

# A container record goes to container mode, never to a native release
# (tests/shell/launcher-container.sh covers that mode); one without an image
# says how to repair it.
printf '{"mode": "container"}\n' > "$EMPTY/install.json"
out=$(KNOSSOS_INSTALL_DIR="$EMPTY" "$LAUNCHER" version 2>&1); status=$?
case $out in
    *"names no image; re-run the installer"*) said=yes ;;
    *) said=no ;;
esac
if [ "$status" -eq 1 ] && [ "$said" = yes ]; then pass 'a container record without an image is refused clearly'; else fail "container mode (status $status, output $out)"; fi

# No install.json at all means native: the release runs.
NOJSON="$WORK/nojson"
stub_release "$NOJSON" 1.0.0
out=$(KNOSSOS_INSTALL_DIR="$NOJSON" "$LAUNCHER" hello 2>&1); status=$?
case $out in 'args:[hello]'*) ran=yes ;; *) ran=no ;; esac
if [ "$status" -eq 0 ] && [ "$ran" = yes ]; then pass 'a missing install.json means native'; else fail "missing install.json (status $status, output $out)"; fi

# No install.json and no release: one line, exit 1.
BARE_DATA="$WORK/baredata"
mkdir -p "$BARE_DATA"
out=$(KNOSSOS_INSTALL_DIR="$BARE_DATA" "$LAUNCHER" version 2>&1); status=$?
lines=$(printf '%s\n' "$out" | wc -l | tr -d ' ')
if [ "$status" -eq 1 ] && [ "$lines" -eq 1 ]; then pass 'no install.json and no release is one line and exit 1'; else fail "bare data dir (status $status, output $out)"; fi

# An unknown mode: one clear line, exit 1.
printf '{"mode": "weird"}\n' > "$NOJSON/install.json"
out=$(KNOSSOS_INSTALL_DIR="$NOJSON" "$LAUNCHER" hello 2>&1); status=$?
lines=$(printf '%s\n' "$out" | wc -l | tr -d ' ')
case $out in *"unreadable mode"*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 1 ] && [ "$lines" -eq 1 ] && [ "$said" = yes ]; then pass 'an unknown mode is one clear line and exit 1'; else fail "unknown mode (status $status, output $out)"; fi

if [ "$failures" -ne 0 ]; then
    printf '%s failure(s)\n' "$failures"
    exit 1
fi
