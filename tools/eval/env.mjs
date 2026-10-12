/**
 * Dev-only. The eval's isolated world: its own Claude config dir and HOME (so
 * none of the user's rules, plugins, MCP servers or memory load), its own
 * Knossos data dir, and one clean checkout per pinned repo.
 */
import { execFile } from "node:child_process";
import { access, copyFile, mkdir, stat, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { promisify } from "node:util";
import { guardEnv } from "../capture/session.mjs";

const git = promisify(execFile);

export function evalPaths(root) {
    return {
        root,
        config: path.join(root, "config"),
        home: path.join(root, "home"),
        data: path.join(root, "data"),
        repos: path.join(root, "repos"),
        defects: path.join(root, "defects"),
        mcpConfig: path.join(root, "mcp.json"),
    };
}

/**
 * Claude Code loads `CLAUDE.md` files and `.claude` directories from every
 * ancestor of its working directory, so a workspace under a directory that
 * holds one would read that memory. Throws when any ancestor of the repos
 * directory, up to `/`, has either.
 */
export async function assertCleanAncestors(paths) {
    const present = (p) =>
        stat(p).then(
            () => true,
            () => false,
        );
    for (let dir = paths.repos; ; dir = path.dirname(dir)) {
        for (const name of ["CLAUDE.md", ".claude"]) {
            if (await present(path.join(dir, name))) {
                throw new Error(
                    `refusing an eval root whose ancestors carry ${path.join(dir, name)}; it would load into the sessions`,
                );
            }
        }
        if (path.dirname(dir) === dir) return;
    }
}

/**
 * The environment of an eval session. Always guarded against the real home of
 * this machine, whatever the credentials source is, and checked for ancestor
 * memory, so call it before anything is written.
 */
export async function evalEnv(paths, { base = process.env } = {}) {
    const inherited = Object.fromEntries(
        Object.entries(base).filter(
            ([k]) => !k.startsWith("CLAUDE") && !k.startsWith("ANTHROPIC"),
        ),
    );
    const env = {
        ...inherited,
        CLAUDE_CONFIG_DIR: paths.config,
        HOME: paths.home,
        KNOSSOS_DATA_DIR: paths.data,
        KNOSSOS_ROOTS_FILE: path.join(paths.data, "roots.json"),
        ENABLE_CLAUDEAI_MCP_SERVERS: "false",
        DISABLE_AUTOUPDATER: "1",
        CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC: "1",
    };
    guardEnv(env, {
        realDataDir: path.join(os.homedir(), ".knossos"),
        realConfigDir: path.join(os.homedir(), ".claude"),
        tempBase: paths.root,
    });
    await assertCleanAncestors(paths);
    return env;
}

const exists = (p) =>
    access(p).then(
        () => true,
        () => false,
    );

/** Credentials are copied again on every call: an OAuth refresh in the copy would otherwise leave it stale. */
export async function seedConfig(
    paths,
    workspaces,
    { realHome = os.homedir() } = {},
) {
    for (const dir of [
        paths.config,
        paths.home,
        paths.data,
        paths.repos,
        paths.defects,
    ])
        await mkdir(dir, { recursive: true });
    const credentials = path.join(realHome, ".claude", ".credentials.json");
    if (await exists(credentials))
        await copyFile(
            credentials,
            path.join(paths.config, ".credentials.json"),
        );
    const projects = Object.fromEntries(
        workspaces.map((w) => [w, { hasTrustDialogAccepted: true }]),
    );
    await writeFile(
        path.join(paths.config, ".claude.json"),
        JSON.stringify({ hasCompletedOnboarding: true, projects }),
    );
    await writeFile(path.join(paths.config, "settings.json"), "{}");
    await writeFile(
        path.join(paths.data, "roots.json"),
        JSON.stringify({ roots: workspaces }),
    );
}

export async function writeMcpConfig(paths, repoRoot) {
    const config = {
        mcpServers: {
            knossos: {
                command: path.join(repoRoot, "tools", "mcp-serve"),
                env: {
                    KNOSSOS_DATA_DIR: paths.data,
                    KNOSSOS_ROOTS_FILE: path.join(paths.data, "roots.json"),
                },
            },
        },
    };
    await mkdir(paths.root, { recursive: true });
    await writeFile(paths.mcpConfig, JSON.stringify(config, null, 2));
    return paths.mcpConfig;
}

export async function ensureClone(name, repo, paths) {
    const dir = path.join(paths.repos, name);
    if (!(await exists(path.join(dir, ".git")))) {
        await mkdir(paths.repos, { recursive: true });
        await git("git", ["clone", "-q", "--no-checkout", repo.url, dir], {
            maxBuffer: 1 << 24,
        });
    }
    await resetWorkspace(dir, repo.commit);
    return dir;
}

export async function resetWorkspace(dir, commit) {
    await git("git", [
        "-C",
        dir,
        "checkout",
        "-q",
        "--force",
        "--detach",
        commit,
    ]);
    await git("git", ["-C", dir, "clean", "-fdxq"]);
}
