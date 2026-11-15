---
layout: home
title: Knossos documentation
titleTemplate: false

hero:
    name: Knossos
    text: The labyrinth mapped once
    tagline: On screen beside Claude Code, and in the notes your agent reads while it works.
    actions:
        - theme: brand
          text: Install
          link: /get-started/installation
        - theme: alt
          text: First scan
          link: /get-started/first-scan
---

Knossos scans a repository into a local graph of its components and the relationships between them, each with the file and line that proves it. In Claude Code, `/knossos` opens that graph as a live pane beside your session, and the same graph answers 40 MCP tools and a CLI for Claude Code, Codex or any MCP client.

Facts that static analysis cannot prove are labelled with their confidence and origin. Nothing in the scan installs dependencies, imports a module or boots a framework.

## Install

You need PHP 8.3 or newer, Node 24 or newer and Python 3.11 or newer. This installs the latest release:

```sh
curl -fsSL https://github.com/AraneaDev/knossos/releases/latest/download/install.sh | sh
```

[Installation](get-started/installation.md) covers installing from a checkout or in Docker, and what to do when it does not start.

## Where to go next

- [Install Knossos](get-started/installation.md): register it with Claude Code or another MCP client.
- [Scan your first project](get-started/first-scan.md): scan a project, check the result and see what an answer looks like.
- [The pane](claude-code/pane/index.md): open it beside your session and read its tabs.
- [MCP tool reference](reference/mcp-tools.md): what each tool takes and returns.
- [The graph and its evidence](concepts/graph-and-evidence.md): what a scan stores and how far you can trust each fact.
- [All pages](README.md): every page, grouped by the question it answers.
