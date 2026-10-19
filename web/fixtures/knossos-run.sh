#!/bin/sh
# Stands in for hooks/scripts/knossos-run.sh in the web host's specs: prints
# the canned answer for the subcommand (<subcommand>.json beside this script),
# with @ROOT@ replaced by the project directory, and nothing for a subcommand
# that has none, which the mod reads as no data, as it does every failure of the
# real wrapper (and a call that wrapper refuses).
here=$(cd "$(dirname "$0")" && pwd)
answer="$here/$1.json"
[ -f "$answer" ] || exit 0
sed "s#@ROOT@#$2#g" "$answer"
