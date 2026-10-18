#!/bin/sh
# Stands in for hooks/scripts/knossos-run.sh in the web host's specs: prints
# the canned answer for the subcommand (<subcommand>.json beside this script),
# with @ROOT@ replaced by the project directory, and nothing for a subcommand
# that has none, as the real wrapper is silent on any failure.
here=$(cd "$(dirname "$0")" && pwd)
answer="$here/$1.json"
[ -f "$answer" ] || exit 0
sed "s#@ROOT@#$2#g" "$answer"
