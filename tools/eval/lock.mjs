/**
 * Dev-only. The lock file that keeps two `continue` runs of the agent eval
 * from sharing a workspace, with a takeover for a lock its holder left
 * behind.
 */
import { randomUUID } from "node:crypto";
import {
    link,
    mkdir,
    readdir,
    readFile,
    rename,
    rm,
    stat,
    writeFile,
} from "node:fs/promises";
import path from "node:path";

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const alive = (pid) => {
    try {
        process.kill(pid, 0);
        return true;
    } catch (error) {
        return error.code === "EPERM";
    }
};

/** A takeover mutex older than this belonged to a process that died holding it. */
const TAKEOVER_STALE_MS = 5000;

/**
 * Holds `${file}.takeover` (an atomic mkdir) while `fn` runs; null when another
 * process holds it. `fn` gets `stillOwner()`: a peer may break the mutex of a
 * process that was suspended for longer than `staleMs`, and that process must
 * notice before it removes anything. A mutex dated more than `staleMs` in the
 * future is stale too. A stale mutex is broken by renaming it to a name of its
 * own, so of several peers only one rename succeeds, and the mover checks that
 * what it moved really was stale and puts a fresh one back.
 */
async function withTakeover(file, staleMs, hooks, fn) {
    const mutex = `${file}.takeover`;
    const token = randomUUID();
    try {
        await mkdir(mutex);
    } catch (error) {
        if (error.code !== "EEXIST") throw error;
        const ageOf = () =>
            stat(mutex)
                .then((st) => Date.now() - st.mtimeMs)
                .catch(() => 0);
        // A little clock skew between the file system and Date.now() is normal; only a clearly future date is stale.
        if (Math.abs(await ageOf()) <= staleMs) {
            await sleep(10);
            return null;
        }
        await hooks.beforeBreak?.();
        const moved = `${mutex}.broken-${process.pid}-${randomUUID()}`;
        try {
            await rename(mutex, moved);
        } catch (error) {
            // Another peer broke it first, or its owner released it.
            if (error.code === "ENOENT") return null;
            throw error;
        }
        const movedAge = await stat(moved)
            .then((st) => Date.now() - st.mtimeMs)
            .catch(() => Infinity);
        // What was moved was a fresh mutex after all: give it back to its owner, who checks it still owns it.
        if (Math.abs(movedAge) <= staleMs)
            await rename(moved, mutex).catch(() =>
                rm(moved, { recursive: true, force: true }),
            );
        else await rm(moved, { recursive: true, force: true });
        return null;
    }
    const mine = (await stat(mutex)).ino;
    let wrote = false;
    const stillOwner = async () =>
        (await readFile(path.join(mutex, "owner"), "utf8").catch(() => "")) ===
        token;
    // Before the owner file exists the directory is only recognisable by its inode.
    const sameDir = () =>
        stat(mutex).then(
            (st) => st.ino === mine,
            () => false,
        );
    try {
        await (hooks.write ?? writeFile)(path.join(mutex, "owner"), token);
        wrote = true;
        return await fn(stillOwner);
    } finally {
        if (wrote ? await stillOwner() : await sameDir())
            await rm(mutex, { recursive: true, force: true });
    }
}

/** Removes `<lock>.takeover.broken-*` directories a crashed breaker left behind; never the live mutex. */
async function sweepBroken(file, staleMs) {
    const dir = path.dirname(file);
    const prefix = `${path.basename(file)}.takeover.broken-`;
    const names = await readdir(dir).catch(() => []);
    for (const name of names.filter((n) => n.startsWith(prefix))) {
        const full = path.join(dir, name);
        const age = await stat(full)
            .then((st) => Date.now() - st.mtimeMs)
            .catch(() => null);
        if (age !== null && Math.abs(age) > staleMs)
            await rm(full, { recursive: true, force: true });
    }
}

/**
 * Takes `file` with the pid of this process; throws while another live
 * process holds it, takes it over when the holder is gone. The lock appears
 * atomically with its pid in it (a temp file linked into place), the takeover
 * runs under a mutex and re-reads the holder inside it, so two processes that
 * both saw a stale lock cannot each remove the other's fresh one. Returns a
 * release that removes the lock only while it still holds this pid.
 * `hooks.beforeRemove`, `hooks.beforeBreak`, `hooks.write` and `hooks.staleMs` exist for the tests.
 */
export async function acquireLock(file, hooks = {}) {
    await mkdir(path.dirname(file), { recursive: true });
    await sweepBroken(file, hooks.staleMs ?? TAKEOVER_STALE_MS);
    const holder = async () =>
        Number((await readFile(file, "utf8").catch(() => "")).trim());
    const release = async () => {
        if ((await holder()) === process.pid) await rm(file, { force: true });
    };
    const create = async () => {
        const temp = `${file}.${process.pid}.${randomUUID()}.tmp`;
        await writeFile(temp, String(process.pid));
        try {
            await link(temp, file);
            return true;
        } catch (error) {
            if (error.code !== "EEXIST") throw error;
            return false;
        } finally {
            await rm(temp, { force: true });
        }
    };
    for (;;) {
        if (await create()) return release;
        const pid = await holder();
        if (pid > 0 && alive(pid))
            throw new Error(`another continue is running (pid ${pid})`);
        const won = await withTakeover(
            file,
            hooks.staleMs ?? TAKEOVER_STALE_MS,
            hooks,
            async (stillOwner) => {
                const current = await holder();
                if (current > 0 && alive(current))
                    throw new Error(
                        `another continue is running (pid ${current})`,
                    );
                await hooks.beforeRemove?.();
                if (!(await stillOwner())) return false;
                await rm(file, { force: true });
                return create();
            },
        );
        if (won) return release;
    }
}
