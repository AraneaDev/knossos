# The guest the Theseus lifecycle rehearsals run in: a host that can run
# Knossos natively (PHP, Node, Python and git, what `knossos doctor` checks),
# with a Docker client for container mode, and no Knossos. Theseus installs
# nothing in a guest, so everything install.sh needs has to be here already.
# Claude Code comes in as a recipe input (prepare.sh copies the host's), and
# container mode's daemon is Theseus's private sidecar, never the host's.
FROM node:26-trixie-slim AS node_runtime

FROM php:8.5-cli-trixie

RUN apt-get update \
    && apt-get install --no-install-recommends -y docker-cli git libatomic1 python3 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node_runtime /usr/local/bin/node /usr/local/bin/node
