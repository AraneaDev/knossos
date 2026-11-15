import { mkdir, mkdtemp, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { describe, expect, it } from "vitest";
import { runCleanups } from "./session.mjs";
import { stageGraph, throwawayWorktree } from "./worktree.mjs";

/** A fake git that records each call and answers rev-parse and merge-base with the given commits. */
const fakeGit = ({ head = "headsha", fork = "forksha" } = {}) => {
    const calls = [];
    const runGit = async (_dir, ...args) => {
        calls.push(args.join(" "));
        if (args[0] === "rev-parse") return head;
        if (args[0] === "merge-base") return fork;
        return "";
    };
    return { calls, runGit };
};

describe("throwawayWorktree's commit", () => {
    it("is HEAD unless a ref is given, and that ref when one is", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "tree-ref-"));
        try {
            for (const [options, ref] of [
                [{}, "HEAD"],
                [{ ref: "origin/topic" }, "origin/topic"],
            ]) {
                const { calls, runGit } = fakeGit();
                const home = path.join(root, ref.replace("/", "-"));
                await throwawayWorktree(root, { home, runGit, ...options });
                expect(calls[0]).toBe(
                    `worktree add --detach ${path.join(home, "knossos")} ${ref}`,
                );
            }
        } finally {
            await runCleanups();
            await rm(root, { recursive: true, force: true });
        }
    });
});

describe("stageGraph with a base", () => {
    it("refuses a checkout that has not left the default branch, before it scans anything", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "stage-base-"));
        const source = path.join(root, "real");
        await mkdir(source);
        const scans = [];
        const { calls, runGit } = fakeGit({ head: "same", fork: "same" });
        try {
            await expect(
                stageGraph("/w/knossos", {
                    source,
                    realDataDir: source,
                    tmpRoot: root,
                    base: true,
                    copyNotes: async () => {},
                    runScan: async (args) => void scans.push(args),
                    runGit,
                }),
            ).rejects.toThrow(/--ref=<a branch ahead of the default branch>/);
            expect(scans).toEqual([]);
            expect(calls.some((c) => c.startsWith("checkout"))).toBe(false);
        } finally {
            await runCleanups();
            await rm(root, { recursive: true, force: true });
        }
    });
});
