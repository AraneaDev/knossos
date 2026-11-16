#!/bin/sh
# uninstall.sh against installations laid out by hand: the entries install.sh
# and self-update leave, with marker files in place of a release, so nothing
# is built and nothing touches the real home. Claude Code is a stub on PATH
# that records how it was called. The end-to-end run against a real release,
# in a disposable container, is tests/theseus.
set -u
# shellcheck source=tests/shell/lib/scratch.sh
. "$(dirname -- "$0")/lib/scratch.sh"

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
ROOT="$HERE/../.."
UNINSTALL="${KNOSSOS_TEST_UNINSTALL:-$ROOT/uninstall.sh}"
failures=0
WORK=$(mktemp -d)

pass() { printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1"; failures=$((failures + 1)); }

# A home of its own, so the defaults (~/.knossos, ~/.local/bin,
# ~/.local/share/knossos) never name anything real, and no claude on PATH
# unless a case puts the stub there.
HOME="$WORK/home"
mkdir -p "$HOME"
export HOME
unset KNOSSOS_DATA_DIR KNOSSOS_INSTALL_DIR KNOSSOS_BIN_DIR KNOSSOS_MCP_NAME KNOSSOS_MCP_SCOPE XDG_DATA_HOME
EMPTY_PATH="$WORK/no claude"
mkdir -p "$EMPTY_PATH"
for tool in sh sed grep head rm rmdir chmod cat printf find tr sort; do
    found=$(command -v "$tool") && ln -s "$found" "$EMPTY_PATH/$tool" 2>/dev/null
done

# write_launcher FILE DIR: a launcher as install.sh writes it, for the install
# directory DIR. It records its arguments beside itself.
write_launcher() {
    mkdir -p "$(dirname -- "$1")"
    {
        printf '#!/bin/sh\n'
        printf "INSTALL_DIR='%s'\n" "$2"
        # shellcheck disable=SC2016 # written into the launcher, expanded when it runs
        printf 'printf "%%s\\n" "$*" >> "$0.calls"\n'
    } > "$1"
    chmod 0755 "$1"
}

# make_native DIR: a native install with a graph and settings, its data
# directory and its bin directory both with a space in the name.
make_native() {
    data="$1/data dir"
    mkdir -p "$data/versions/1.0.0/bin" "$data/versions/0.9.0" "$data/plugin/.claude-plugin" "$data/backups" "$data/watch"
    ln -s versions/1.0.0 "$data/current"
    printf '{\n  "mode": "native",\n  "version": "1.0.0",\n  "image": null,\n  "bin_dir": "%s",\n  "installed_at": "2026-01-01T00:00:00Z"\n}\n' "$1/bin dir" > "$data/install.json"
    echo 0.9.0 > "$data/previous-version"
    echo '{}' > "$data/update-check.json"
    : > "$data/self-update.lock"
    : > "$data/roots.json.lock"
    echo graph > "$data/knossos.sqlite"
    : > "$data/knossos.sqlite.migrate.lock"
    echo backup > "$data/backups/pre.sqlite"
    echo '{"roots":["/somewhere"]}' > "$data/roots.json"
    echo '{}' > "$data/enrichers.json"
    write_launcher "$1/bin dir/knossos" "$data"
}

run_uninstall() {
    PATH="${CLAUDE_PATH:-$EMPTY_PATH}" sh "$UNINSTALL" "$@" 2>&1 </dev/null
}

# ---- a native install ------------------------------------------------------

T1="$WORK/native"
make_native "$T1"
# No --bin-dir: the launcher is found through what install.json records.
out=$(run_uninstall --data-dir="$T1/data dir"); status=$?
if [ "$status" -eq 0 ]; then pass 'uninstalling a native install exits 0'; else fail "native uninstall exited $status: $out"; fi
gone=yes
for entry in install.json versions current plugin previous-version update-check.json self-update.lock roots.json.lock; do
    if [ -e "$T1/data dir/$entry" ] || [ -L "$T1/data dir/$entry" ]; then gone="no ($entry)"; fi
done
if [ "$gone" = yes ]; then pass 'the releases, the record, the plugin and the bookkeeping are removed'; else fail "still there: $gone"; fi
if [ ! -e "$T1/bin dir/knossos" ]; then pass 'the launcher install.json points at is removed'; else fail 'the launcher is still there'; fi
if [ "$(cat "$T1/data dir/knossos.sqlite")" = graph ] && [ -f "$T1/data dir/backups/pre.sqlite" ] \
    && [ -f "$T1/data dir/roots.json" ] && [ -f "$T1/data dir/enrichers.json" ]; then
    pass 'the graph, its backups and the settings are kept'
else
    fail "the graph or the settings went: $(ls -A "$T1/data dir" 2>&1)"
fi
case $out in *'Kept, your graph and settings (remove them too with --purge):'*"$T1/data dir:"*knossos.sqlite*) pass 'the run names what it kept and how to remove it' ;; *) fail "kept entries not named: $out" ;; esac
case $out in *'Claude CLI not found; if Claude Code used this installation, run: claude mcp remove knossos -s user;'*) pass 'without claude the run says what to unregister later' ;; *) fail "no claude hint: $out" ;; esac

# Again: only the graph and the settings are there, and they still stay.
out=$(run_uninstall --data-dir="$T1/data dir" --bin-dir="$T1/bin dir"); status=$?
case $out in *'Nothing of Knossos was found to remove.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && [ -f "$T1/data dir/knossos.sqlite" ]; then
    pass 'a second run finds nothing, exits 0 and keeps the graph'
else
    fail "second run: status $status, output $out"
fi

# --purge after that removes what was kept, and the directory with it.
out=$(run_uninstall --data-dir="$T1/data dir" --bin-dir="$T1/bin dir" --purge); status=$?
if [ "$status" -eq 0 ] && [ ! -e "$T1/data dir" ]; then pass '--purge removes the graph, the settings and the empty data directory'; else fail "purge: status $status, left $(ls -A "$T1/data dir" 2>&1), output $out"; fi

# ---- what is not Knossos stays ---------------------------------------------

T2="$WORK/foreign"
make_native "$T2"
echo mine > "$T2/data dir/notes.txt"
mkdir -p "$T2/data dir/web"
write_launcher "$T2/bin dir/knossos" "$WORK/another install"
out=$(run_uninstall --data-dir="$T2/data dir" --purge); status=$?
if [ "$status" -eq 0 ] && [ "$(cat "$T2/data dir/notes.txt")" = mine ] && [ -d "$T2/data dir/web" ] \
    && [ "$(find "$T2/data dir/." ! -name . -prune -print | wc -l)" -eq 2 ]; then
    pass '--purge leaves a file and a directory Knossos does not make, and only those'
else
    fail "foreign entries: status $status, left $(ls -A "$T2/data dir" 2>&1), output $out"
fi
case $out in *'Left in place, since Knossos does not put it there:'*notes.txt*) pass 'the run names what it left in place' ;; *) fail "left entries not named: $out" ;; esac
case $out in *"$T2/bin dir/knossos is not the launcher of this installation; left alone"*) said=yes ;; *) said=no ;; esac
if [ "$said" = yes ] && [ -f "$T2/bin dir/knossos" ]; then pass 'the launcher of another installation is left alone'; else fail "foreign launcher: $out"; fi

T3="$WORK/linked launcher"
make_native "$T3"
rm "$T3/bin dir/knossos"
write_launcher "$T3/real launcher" "$T3/data dir"
ln -s "$T3/real launcher" "$T3/bin dir/knossos"
out=$(run_uninstall --data-dir="$T3/data dir"); status=$?
if [ "$status" -eq 0 ] && [ -L "$T3/bin dir/knossos" ] && [ -f "$T3/real launcher" ]; then
    pass 'a link in the bin directory is never followed or removed'
else
    fail "linked launcher: status $status, output $out"
fi

# ---- dry run ---------------------------------------------------------------

T4="$WORK/dry"
make_native "$T4"
before=$(find "$T4" | LC_ALL=C sort)
out=$(run_uninstall --data-dir="$T4/data dir" --purge --dry-run); status=$?
after=$(find "$T4" | LC_ALL=C sort)
if [ "$status" -eq 0 ] && [ "$before" = "$after" ]; then pass '--dry-run changes nothing'; else fail "dry run changed the tree: status $status, output $out"; fi
case $out in *"would remove $T4/data dir/versions"*"would remove $T4/data dir/knossos.sqlite"*"would remove $T4/bin dir/knossos"*) pass '--dry-run lists what would go' ;; *) fail "dry run output: $out" ;; esac

# ---- Claude Code -----------------------------------------------------------

# A claude that answers `mcp get` and `plugin marketplace list` from two
# files and records every call.
STUB="$WORK/stub bin"
mkdir -p "$STUB"
for tool in "$EMPTY_PATH"/*; do ln -s "$(readlink "$tool")" "$STUB/${tool##*/}"; done
cat > "$STUB/claude" <<'CLAUDE'
#!/bin/sh
printf '%s\n' "$*" >> "$CLAUDE_STUB_DIR/calls"
case "$1 $2" in
    'mcp get') cat "$CLAUDE_STUB_DIR/mcp" 2>/dev/null ;;
    'plugin marketplace') if [ "$3" = list ]; then cat "$CLAUDE_STUB_DIR/marketplaces" 2>/dev/null; fi ;;
esac
exit 0
CLAUDE
chmod 0755 "$STUB/claude"
CLAUDE_STUB_DIR="$WORK/stub state"
mkdir -p "$CLAUDE_STUB_DIR"
export CLAUDE_STUB_DIR

T5="$WORK/registered"
make_native "$T5"
printf 'knossos:\n  Scope: User config\n  Command: %s\n  Args: serve\n' "$T5/bin dir/knossos" > "$CLAUDE_STUB_DIR/mcp"
printf '  knossos\n    Source: Directory (%s)\n' "$T5/data dir/plugin" > "$CLAUDE_STUB_DIR/marketplaces"
: > "$CLAUDE_STUB_DIR/calls"
out=$(CLAUDE_PATH=$STUB run_uninstall --data-dir="$T5/data dir"); status=$?
calls=$(cat "$CLAUDE_STUB_DIR/calls")
case $calls in
    *'mcp remove knossos -s user'*'plugin uninstall knossos@knossos --scope user'*'plugin marketplace remove knossos'*) said=yes ;;
    *) said=no ;;
esac
if [ "$status" -eq 0 ] && [ "$said" = yes ]; then pass 'the MCP server and the plugin that point here are unregistered'; else fail "registered: status $status, calls $calls, output $out"; fi

# Registered to a checkout: the same name, another command, another marketplace.
T6="$WORK/checkout"
make_native "$T6"
printf 'knossos:\n  Command: /src/knossos/tools/mcp-serve\n' > "$CLAUDE_STUB_DIR/mcp"
printf '  knossos\n    Source: Directory (/src/knossos/.plugin)\n' > "$CLAUDE_STUB_DIR/marketplaces"
: > "$CLAUDE_STUB_DIR/calls"
out=$(CLAUDE_PATH=$STUB run_uninstall --data-dir="$T6/data dir"); status=$?
calls=$(cat "$CLAUDE_STUB_DIR/calls")
case $calls in *remove* | *uninstall*) said=no ;; *) said=yes ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && [ ! -e "$T6/data dir/versions" ]; then
    pass 'a registration of a checkout or another installation is left alone'
else
    fail "checkout registration: status $status, calls $calls, output $out"
fi

T7="$WORK/no plugin"
make_native "$T7"
: > "$CLAUDE_STUB_DIR/calls"
out=$(CLAUDE_PATH=$STUB run_uninstall --data-dir="$T7/data dir" --no-plugin); status=$?
if [ "$status" -eq 0 ] && [ ! -s "$CLAUDE_STUB_DIR/calls" ]; then pass '--no-plugin never calls claude'; else fail "--no-plugin: status $status, calls $(cat "$CLAUDE_STUB_DIR/calls")"; fi

T8="$WORK/dry claude"
make_native "$T8"
printf 'knossos:\n  Command: %s\n' "$T8/bin dir/knossos" > "$CLAUDE_STUB_DIR/mcp"
: > "$CLAUDE_STUB_DIR/calls"
out=$(CLAUDE_PATH=$STUB run_uninstall --data-dir="$T8/data dir" --dry-run); status=$?
case $(cat "$CLAUDE_STUB_DIR/calls") in *remove* | *uninstall*) said=no ;; *) said=yes ;; esac
case $out in *'would run: claude mcp remove knossos -s user'*) listed=yes ;; *) listed=no ;; esac
if [ "$status" -eq 0 ] && [ "$said" = yes ] && [ "$listed" = yes ]; then pass '--dry-run unregisters nothing and says what it would'; else fail "dry claude: status $status, output $out"; fi

# ---- a container install ---------------------------------------------------

T9="$WORK/container"
INST="$T9/install dir"
GRAPH="$T9/graph dir"
mkdir -p "$INST/plugin" "$INST/containers" "$INST/settings" "$INST/tmp" "$GRAPH/backups"
printf '{\n  "mode": "container",\n  "layout": "install-dir",\n  "version": "1.0.0",\n  "image": "ghcr.io/araneadev/knossos@sha256:abc",\n  "previous_version": null,\n  "previous_image": null,\n  "runtime": "docker",\n  "bin_dir": "%s",\n  "data_dir": "%s",\n  "installed_at": "2026-01-01T00:00:00Z"\n}\n' "$T9/bin dir" "$GRAPH" > "$INST/install.json"
: > "$INST/container-update"
echo '{"roots":[]}' > "$INST/roots.json"
echo graph > "$GRAPH/knossos.sqlite"
write_launcher "$T9/bin dir/knossos" "$INST"
out=$(run_uninstall --install-dir="$INST"); status=$?
if [ "$status" -eq 0 ] && [ ! -e "$INST" ] && [ ! -e "$T9/bin dir/knossos" ]; then
    pass 'a container install loses its install directory and its launcher'
else
    fail "container uninstall: status $status, left $(ls -A "$INST" 2>&1), output $out"
fi
if [ "$(cat "$T9/bin dir/knossos.calls" 2>/dev/null)" = 'stop --all' ]; then pass 'its containers are stopped first, through the launcher'; else fail "launcher calls: $(cat "$T9/bin dir/knossos.calls" 2>&1)"; fi
if [ "$(cat "$GRAPH/knossos.sqlite")" = graph ]; then pass 'the graph directory the record names is kept'; else fail 'the graph went'; fi
case $out in *'remove it with: docker image rm ghcr.io/araneadev/knossos@sha256:abc'*) pass 'the run says how to remove the image' ;; *) fail "no image hint: $out" ;; esac
rm -f "$T9/bin dir/knossos.calls"
out=$(run_uninstall --install-dir="$INST" --data-dir="$GRAPH" --purge); status=$?
if [ "$status" -eq 0 ] && [ ! -e "$GRAPH" ]; then pass '--purge with --data-dir removes the graph a container install left'; else fail "container purge: status $status, output $out"; fi

# Claude Code first, then the stop: asking claude about the server starts a
# container in container mode, which a stop before it would leave running.
T10="$WORK/container order"
INST10="$T10/install dir"
mkdir -p "$INST10/plugin" "$T10/graph dir"
printf '{\n  "mode": "container",\n  "layout": "install-dir",\n  "version": "1.0.0",\n  "image": "sha256:abc",\n  "bin_dir": "%s",\n  "data_dir": "%s"\n}\n' "$T10/bin dir" "$T10/graph dir" > "$INST10/install.json"
mkdir -p "$T10/bin dir"
{
    printf '#!/bin/sh\n'
    printf "INSTALL_DIR='%s'\n" "$INST10"
    # shellcheck disable=SC2016 # written into the launcher, expanded when it runs
    printf 'printf "launcher %%s\\n" "$*" >> "$CLAUDE_STUB_DIR/calls"\n'
} > "$T10/bin dir/knossos"
chmod 0755 "$T10/bin dir/knossos"
printf 'knossos:\n  Command: %s\n' "$T10/bin dir/knossos" > "$CLAUDE_STUB_DIR/mcp"
printf '  knossos\n    Source: Directory (%s)\n' "$INST10/plugin" > "$CLAUDE_STUB_DIR/marketplaces"
: > "$CLAUDE_STUB_DIR/calls"
out=$(CLAUDE_PATH=$STUB run_uninstall --install-dir="$INST10"); status=$?
order=$(grep -n -e '^mcp remove' -e '^launcher stop --all' "$CLAUDE_STUB_DIR/calls" | cut -d: -f2- | tr '\n' '|')
if [ "$status" -eq 0 ] && [ "$order" = 'mcp remove knossos -s user|launcher stop --all|' ]; then
    pass 'a container install is unregistered from Claude Code before its containers are stopped'
else
    fail "unregister and stop order: status $status, order $order, output $out"
fi

# ---- refusals --------------------------------------------------------------

out=$(run_uninstall --data-dir="$HOME"); status=$?
case $out in *'is the filesystem root or your home directory'*'Nothing was changed.'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ]; then pass 'the home directory is never taken as a data directory'; else fail "home as data dir: status $status, output $out"; fi
out=$(run_uninstall --data-dir=/); status=$?
if [ "$status" -ne 0 ]; then pass '/ is never taken as a data directory'; else fail "/ as data dir: output $out"; fi
out=$(run_uninstall --everything); status=$?
case $out in *'unknown argument: --everything'*) said=yes ;; *) said=no ;; esac
if [ "$status" -ne 0 ] && [ "$said" = yes ]; then pass 'an unknown argument is refused'; else fail "unknown argument: status $status, output $out"; fi

# ---- the lists are install.sh's --------------------------------------------

# list FILE NAME: the names on a list, one per line, sorted. install.sh's
# GRAPH_ENTRIES carries a shape after each name, which is dropped.
list() {
    sed -n "/^$2='\$/,/^'\$/p" "$1" | sed '1d;$d' | sed 's/ .*$//' | LC_ALL=C sort
}
INSTALL="$ROOT/install.sh"
if [ -n "$(list "$INSTALL" NATIVE_ENTRIES)" ] \
    && [ "$( { list "$UNINSTALL" PROGRAM_ENTRIES; list "$UNINSTALL" SETTINGS_ENTRIES; } | LC_ALL=C sort)" = "$(list "$INSTALL" NATIVE_ENTRIES)" ]; then
    pass 'PROGRAM_ENTRIES and SETTINGS_ENTRIES together are install.sh NATIVE_ENTRIES'
else
    fail 'the native lists of uninstall.sh and install.sh differ'
fi
for name in GRAPH_ENTRIES INSTALL_ENTRIES; do
    if [ -n "$(list "$INSTALL" "$name")" ] && [ "$(list "$UNINSTALL" "$name")" = "$(list "$INSTALL" "$name")" ]; then
        pass "$name names what install.sh's does"
    else
        fail "$name differs between uninstall.sh and install.sh"
    fi
done

if [ "$failures" -ne 0 ]; then
    printf '%s failure(s)\n' "$failures"
    exit 1
fi
