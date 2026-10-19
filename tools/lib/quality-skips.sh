# shellcheck shell=sh
# The checks tools/quality could not run, said out loud.
#
# A check whose tool is missing used to be skipped without a word outside the
# container, so a local run that never ran ShellCheck, cargo or the mod's
# type-check ended as green as one that did. Every such skip now goes through
# here: it prints `NOT run: <check>` when it happens, and the summary at the
# end of the run lists every check that did not run. In the quality container
# (KNOSSOS_QUALITY_CONTAINER=1) the image is meant to hold every tool, so a
# missing one is fatal instead.
#
# A deliberate opt-out, such as KNOSSOS_EXTERNAL_LINKS=0, is listed the same
# way but is never fatal, because the run was told not to do it.
#
# Only shell builtins are used past quality_skips_begin, so the helpers work
# with a PATH that holds none of the tools they look for.

quality_skip_file=''

# quality_skips_begin FILE: start recording skips in FILE, which the caller
# creates and removes. A file rather than a variable, because some checks run
# in a subshell, and a variable set there never reaches the summary.
quality_skips_begin() {
    quality_skip_file=$1
    : > "$quality_skip_file"
}

quality_in_container() {
    [ "${KNOSSOS_QUALITY_CONTAINER:-0}" = "1" ]
}

# quality_not_run CHECK REASON: CHECK could not run. Fatal in the container.
quality_not_run() {
    if quality_in_container; then
        printf 'NOT run: %s (%s); the quality container must run it\n' "$1" "$2" >&2
        exit 1
    fi
    printf 'NOT run: %s (%s)\n' "$1" "$2" >&2
    if [ -n "$quality_skip_file" ]; then
        printf '%s (%s)\n' "$1" "$2" >> "$quality_skip_file"
    fi
}

# quality_opted_out CHECK REASON: CHECK was turned off on purpose. Never fatal.
quality_opted_out() {
    printf 'NOT run: %s (%s)\n' "$1" "$2" >&2
    if [ -n "$quality_skip_file" ]; then
        printf '%s (%s)\n' "$1" "$2" >> "$quality_skip_file"
    fi
}

# quality_has TOOL CHECK: true when TOOL is on PATH; otherwise CHECK is
# reported as not run (fatal in the container) and the result is false.
quality_has() {
    if command -v "$1" >/dev/null 2>&1; then
        return 0
    fi
    quality_not_run "$2" "$1 not found"
    return 1
}

# quality_skips_end: print the checks that did not run, if any. Keeps the
# status it was called with, so it can run from an EXIT trap.
quality_skips_end() {
    quality_status=$?
    if [ -n "$quality_skip_file" ] && [ -s "$quality_skip_file" ]; then
        quality_count=0
        while IFS= read -r quality_line; do
            quality_count=$((quality_count + 1))
        done < "$quality_skip_file"
        printf '\ntools/quality: %s check(s) NOT run:\n' "$quality_count" >&2
        while IFS= read -r quality_line; do
            printf '  - %s\n' "$quality_line" >&2
        done < "$quality_skip_file"
    fi
    return "$quality_status"
}
