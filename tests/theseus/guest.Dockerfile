# The guest the Theseus lifecycle rehearsal runs in: a host that can run
# Knossos natively (PHP, Node, Python and git, what `knossos doctor` checks)
# and holds no Knossos. Theseus installs nothing in a guest, so everything
# install.sh needs has to be here already. No Claude Code CLI: the installer's
# "Claude CLI not found" path is the one rehearsed.
FROM node:26-trixie-slim AS node_runtime

FROM php:8.5-cli-trixie

RUN apt-get update \
    && apt-get install --no-install-recommends -y git libatomic1 python3 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node_runtime /usr/local/bin/node /usr/local/bin/node
