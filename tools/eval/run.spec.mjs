import { execFile } from "node:child_process";
import { mkdir, mkdtemp, readFile, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";
import { describe, expect, it, vi } from "vitest";
import { readLedger } from "./ledger.mjs";
import { main } from "./run.mjs";

const git = promisify(execFile);
const FAKE = fileURLToPath(
    new URL("./fixtures/fake-claude.mjs", import.meta.url),
);
const tmp = (p) => mkdtemp(path.join(os.tmpdir(), p));

async function fixture() {
    const origin = await tmp("eval-origin-");
    await git("git", ["init", "-q", origin]);
    await writeFile(path.join(origin, "a.php"), "<?php\n");
    await git("git", [
        "-C",
        origin,
        "-c",
        "user.name=t",
        "-c",
        "user.email=t@t",
        "add",
        ".",
    ]);
    await git("git", [
        "-C",
        origin,
        "-c",
        "user.name=t",
        "-c",
        "user.email=t@t",
        "commit",
        "-qm",
        "one",
    ]);
    const commit = (
        await git("git", ["-C", origin, "rev-parse", "HEAD"])
    ).stdout.trim();
    const corpus = await tmp("eval-corpus-");
    await writeFile(
        path.join(corpus, "repos.json"),
        JSON.stringify({
            repos: { demo: { url: origin, commit, language: "php" } },
        }),
    );
    await mkdir(path.join(corpus, "tasks", "demo"), { recursive: true });
    for (const id of ["demo-callers-a", "demo-callers-b"]) {
        await writeFile(
            path.join(corpus, "tasks", "demo", `${id}.json`),
            JSON.stringify({
                id,
                repo: "demo",
                kind: "question",
                category: "callers",
                subject: "Invoice::total",
                prompt: "Who calls Invoice::total?",
                answer: {
                    unit: "symbol",
                    expected: ["App\\Billing::summarise"],
                },
                verified: "2026-10-13",
            }),
        );
    }
    const env = {
        ...process.env,
        KNOSSOS_EVAL_RUNS: await tmp("eval-runs-"),
        KNOSSOS_EVAL_ROOT: await tmp("eval-root-"),
        KNOSSOS_EVAL_CLAUDE: FAKE,
        KNOSSOS_EVAL_REAL_HOME: await tmp("eval-nohome-"),
    };
    return { corpus, env };
}

async function started(extra = []) {
    const { corpus, env } = await fixture();
    const log = vi.spyOn(console, "log").mockImplementation(() => {});
    expect(
        await main(
            [
                "start",
                "--corpus",
                corpus,
                "--repeats",
                "1",
                "--seed",
                "1",
                ...extra,
            ],
            env,
        ),
    ).toBe(0);
    const runId = log.mock.calls.at(-1)[0];
    expect(await main(["prepare", runId, "--no-scan"], env)).toBe(0);
    log.mockRestore();
    return { corpus, env, runId, dir: path.join(env.KNOSSOS_EVAL_RUNS, runId) };
}

describe("run CLI", () => {
    it("runs one small batch at a time and resumes where it stopped", async () => {
        const { env, runId, dir } = await started();
        expect(
            await main(
                ["continue", runId, "--batch", "2", "--concurrency", "2"],
                env,
            ),
        ).toBe(0);
        expect(await readLedger(path.join(dir, "ledger.jsonl"))).toHaveLength(
            2,
        );
        expect(await main(["continue", runId, "--batch", "10"], env)).toBe(0);
        const rows = await readLedger(path.join(dir, "ledger.jsonl"));
        expect(rows).toHaveLength(6);
        expect(rows.every((r) => r.status === "scored")).toBe(true);
    });
    it("stops the batch when the five-hour window passes the threshold", async () => {
        const { env, runId, dir } = await started();
        const code = await main(
            ["continue", runId, "--batch", "6", "--concurrency", "1"],
            { ...env, FAKE_UTILIZATION: "0.9" },
        );
        expect(code).toBe(3);
        expect(await readLedger(path.join(dir, "ledger.jsonl"))).toHaveLength(
            1,
        );
    });
    it("runs sessions isolated, with the prompt on stdin", async () => {
        const { env, runId } = await started(["--arms", "baseline"]);
        const fakeLog = path.join(await tmp("eval-log-"), "fake.jsonl");
        await main(["continue", runId, "--batch", "1"], {
            ...env,
            FAKE_LOG: fakeLog,
        });
        const [call] = (await readFile(fakeLog, "utf8"))
            .trim()
            .split("\n")
            .map((l) => JSON.parse(l));
        expect(call.home).toBe(path.join(env.KNOSSOS_EVAL_ROOT, "home"));
        expect(call.cwd).toBe(
            path.join(env.KNOSSOS_EVAL_ROOT, "repos", "demo"),
        );
        expect(call.prompt).toMatch(
            /Who calls Invoice::total\?[\s\S]*```answer|tagged `answer`/,
        );
        expect(call.argv).not.toContain("--mcp-config");
    });
    it("refuses to continue after the corpus changed", async () => {
        const { corpus, env, runId } = await started();
        const file = path.join(corpus, "tasks", "demo", "demo-callers-a.json");
        const task = JSON.parse(await readFile(file, "utf8"));
        await writeFile(file, JSON.stringify({ ...task, prompt: "changed" }));
        await expect(main(["continue", runId], env)).rejects.toThrow(
            /corpus changed/,
        );
    });
    it("adds ablation arms for tools full used", async () => {
        const { env, runId, dir } = await started();
        await main(["continue", runId, "--batch", "10"], env);
        expect(await main(["ablate", runId], env)).toBe(0);
        const plan = JSON.parse(
            await readFile(path.join(dir, "plan.json"), "utf8"),
        );
        expect(
            plan.filter((e) => e.arm === "full-minus-list_usages"),
        ).toHaveLength(2);
    });
});
