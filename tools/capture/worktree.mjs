/**
 * Dev-only. The capture's throwaway worktree of HEAD and the staged copy of
 * the graph it is scanned into, so no session ever runs in this checkout or
 * against the real graph (see shoot.mjs).
 */
import { execFile } from "node:child_process";
import { constants } from "node:fs";
import {
    cp,
    lstat,
    mkdir,
    mkdtemp,
    readdir,
    readFile,
    readlink,
    realpath,
    rm,
    writeFile,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";
import { copyData, guardEnv, registerCleanup } from "./session.mjs";

const run = promisify(execFile);

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const REPO = path.resolve(HERE, "..", "..");
const REAL_DATA = path.join(os.homedir(), ".knossos");
const REAL_CONFIG = path.join(os.homedir(), ".claude");

const git = (dir, ...args) =>
    run("git", ["-C", dir, ...args]).then(({ stdout }) => stdout.trim());

/** The throwaway worktree's dir name: the pane header and Claude Code's `~/` path both show it. */
export const WORKTREE_NAME = "knossos";

/**
 * A detached worktree of HEAD at `<home>/<name>` (WORKTREE_NAME), so the pane's
 * header and Claude Code's `~/<name>` read the project's name, whatever this
 * checkout's dir is called. Its removal is registered before
 * it is added, so a signal or an error while git adds it still removes it.
 * Never `git config` in it: in a linked worktree that writes the shared
 * repository config.
 */
export async function throwawayWorktree(
    repo,
    { home, name = WORKTREE_NAME, runGit = git, copy = cp },
) {
    const dir = path.join(home, name);
    registerCleanup(async () => {
        await runGit(repo, "worktree", "remove", "--force", dir).catch(
            () => {},
        );
        await rm(dir, { recursive: true, force: true });
        await runGit(repo, "worktree", "prune").catch(() => {});
    });
    await mkdir(home, { recursive: true });
    await runGit(repo, "worktree", "add", "--detach", dir, "HEAD");
    await copyInstalledPackages(repo, dir, runGit, copy);
    return dir;
}

/**
 * Copies each `node_modules` the checkout has, tracked by git or not, into
 * the worktree at the same place. A scan types a call into a package from
 * that package's declarations, so without them the stills would show a graph
 * the checkout's own scan does not have. A copy, not a link: a scan reads
 * nothing outside the root it was given, and a shot's edit must never reach
 * the checkout. For the same reason a link inside the packages is copied only
 * when it is relative and lands inside the checkout, so its copy lands inside
 * the worktree; an absolute link (`npm link`) or one that climbs out is left
 * behind.
 */
async function copyInstalledPackages(repo, dir, runGit, copy) {
    // Every untracked path, ignored or not, with each untracked directory
    // listed once rather than file by file.
    const untracked = await runGit(
        repo,
        "ls-files",
        "-z",
        "--others",
        "--directory",
    );
    const packages = [];
    for (const entry of untracked.split("\0")) {
        if (!entry.endsWith("/")) continue;
        packages.push(...(await packageDirectories(repo, entry.slice(0, -1))));
    }
    const top = await realpath(repo);
    for (const relative of packages)
        await copy(path.join(repo, relative), path.join(dir, relative), {
            recursive: true,
            verbatimSymlinks: true,
            mode: constants.COPYFILE_FICLONE,
            filter: (source) => staysInside(source, top),
        });
}

/** The `node_modules` directories at or under `relative`, not inside one another. */
async function packageDirectories(repo, relative) {
    if (path.basename(relative) === "node_modules") return [relative];
    const entries = await readdir(path.join(repo, relative), {
        withFileTypes: true,
    }).catch(() => []);
    const found = [];
    for (const entry of entries)
        if (entry.isDirectory() && entry.name !== ".git")
            found.push(
                ...(await packageDirectories(
                    repo,
                    path.join(relative, entry.name),
                )),
            );
    return found;
}

/**
 * Anything but a link, or a link that reaches a real file or directory inside
 * `top` through relative links only. Every link on the way counts, a link in
 * the path's directories included: in the worktree each lands where it does
 * here, so one absolute hop, or one that climbs out, takes the copy outside.
 */
async function staysInside(source, top) {
    if (!(await lstat(source)).isSymbolicLink()) return true;
    const directory = await realpath(path.dirname(source));
    return reachesInside(
        path.join(directory, path.basename(source)),
        top,
        MAX_LINK_HOPS,
    );
}

const MAX_LINK_HOPS = 40;

/** Walks `absolute` one component at a time from `top`, following each link. */
async function reachesInside(absolute, top, hops) {
    const relative = path.relative(top, absolute);
    if (
        relative === ".." ||
        relative.startsWith(`..${path.sep}`) ||
        path.isAbsolute(relative)
    )
        return false;
    const parts = relative === "" ? [] : relative.split(path.sep);
    let current = top;
    for (const [index, part] of parts.entries()) {
        const next = path.join(current, part);
        const stats = await lstat(next).catch(() => null);
        // A dangling link reaches nothing here, but its copy could reach
        // whatever the worktree holds at that name.
        if (stats === null) return false;
        if (stats.isSymbolicLink()) {
            const target = await readlink(next);
            if (path.isAbsolute(target) || hops === 0) return false;
            return reachesInside(
                path.join(
                    path.resolve(current, target),
                    ...parts.slice(index + 1),
                ),
                top,
                hops - 1,
            );
        }
        current = next;
    }
    return true;
}

/** The branch origin's HEAD names (`origin/main` gives `main`), or main when there is none. */
export async function defaultBranch(dir, runGit = git) {
    const ref = await runGit(
        dir,
        "symbolic-ref",
        "--short",
        "refs/remotes/origin/HEAD",
    ).catch(() => "");
    return ref.includes("/") ? ref.slice(ref.indexOf("/") + 1) : "main";
}

const knossosBin = path.join(REPO, "bin", "knossos");

/**
 * A copy of the real graph in a fresh dir under `tmpRoot`, with the worktree
 * allowed and freshly scanned into it, for each session to copy in turn.
 * With `base`, the commit where HEAD left the default branch is scanned
 * first, so the Branch tab has a snapshot to compare with. After the scans
 * the notes of the checkout at `notesFrom` are copied onto the worktree's
 * project in the stage (`copyNotes`), so the Notes tab shows them.
 * `realDataDir` is only ever read: the stage is refused
 * inside it before anything is made.
 */
export async function stageGraph(
    worktree,
    {
        source = REAL_DATA,
        realDataDir = REAL_DATA,
        tmpRoot = os.tmpdir(),
        base = false,
        notesFrom = REPO,
        copyNotes: copy = copyNotes,
        runGit = git,
        runScan = (args, options) => run(knossosBin, args, options),
    } = {},
) {
    const guard = (dir) =>
        guardEnv(
            { KNOSSOS_DATA_DIR: dir, CLAUDE_CONFIG_DIR: dir },
            { realDataDir, realConfigDir: REAL_CONFIG },
        );
    guard(path.join(tmpRoot, "knossos-capture-stage"));
    const stage = await mkdtemp(path.join(tmpRoot, "knossos-capture-stage-"));
    registerCleanup(() => rm(stage, { recursive: true, force: true }));
    guard(stage);
    const env = {
        ...process.env,
        KNOSSOS_DATA_DIR: stage,
        KNOSSOS_ROOTS_FILE: path.join(stage, "roots.json"),
    };
    await copyData(source, stage);
    const rootsFile = env.KNOSSOS_ROOTS_FILE;
    const roots = JSON.parse(
        await readFile(rootsFile, "utf8").catch(() => '{"roots":[]}'),
    );
    roots.roots = [...new Set([...(roots.roots ?? []), worktree])];
    await writeFile(rootsFile, `${JSON.stringify(roots, null, 4)}\n`);
    const scan = () =>
        runScan(["scan", worktree, "--snapshot-retention=5"], {
            env,
            maxBuffer: 64 * 1024 * 1024,
        });
    if (base) {
        const head = await runGit(worktree, "rev-parse", "HEAD");
        const fork = await runGit(
            worktree,
            "merge-base",
            "HEAD",
            await defaultBranch(worktree, runGit),
        );
        await runGit(worktree, "checkout", "--quiet", "--detach", fork);
        await scan();
        await runGit(worktree, "checkout", "--quiet", "--detach", head);
    }
    await scan();
    await copy(stage, notesFrom, worktree);
    // Again, now the notes are in: a note on a file no worker scans makes that file a component only in a scan.
    await scan();
    return stage;
}

/**
 * Copies the notes of the project at `from` onto the project at `to`, in the
 * graph at `dataDir`: a project is its root path, so the worktree's starts
 * with none. A note the target already has is kept. Only ever a stage.
 */
export async function copyNotes(dataDir, from, to) {
    const { DatabaseSync } = await import("node:sqlite");
    const real = (dir) => realpath(dir).catch(() => path.resolve(dir));
    const [source, target] = await Promise.all([real(from), real(to)]);
    const db = new DatabaseSync(path.join(dataDir, "knossos.sqlite"));
    try {
        const id = (root) =>
            db
                .prepare("SELECT id FROM projects WHERE root_realpath = ?")
                .get(root)?.id;
        const [fromId, toId] = [id(source), id(target)];
        if (fromId === undefined || toId === undefined) return;
        const columns = db
            .prepare("PRAGMA table_info(annotations)")
            .all()
            .map((c) => c.name);
        const picked = columns
            .map((c) => (c === "project_id" ? "?" : c))
            .join(", ");
        db.prepare(
            `INSERT OR IGNORE INTO annotations (${columns.join(", ")}) SELECT ${picked} FROM annotations WHERE project_id = ?`,
        ).run(toId, fromId);
    } finally {
        db.close();
    }
}

/**
 * Puts the worktree back to HEAD between shots: an earlier shot's edit, or a
 * file it left, is not the next one's. Run only after git itself says the
 * dir is the top of a worktree, and never this checkout.
 */
export async function resetTree(worktree) {
    const top = await git(worktree, "rev-parse", "--show-toplevel");
    if (top !== worktree || worktree === REPO)
        throw new Error(
            `refusing to clean ${worktree}: not the capture's worktree`,
        );
    await run("git", ["checkout", "--quiet", "--", "."], { cwd: worktree });
    await run("git", ["clean", "-fdq"], { cwd: worktree });
}
