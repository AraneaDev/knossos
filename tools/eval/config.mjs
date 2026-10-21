/**
 * Dev-only. Where the agent eval keeps its runs and finds its tools, and how
 * a recorded run is opened again: shared by the CLI entry point and the
 * scripts that read a run it wrote.
 */
import { readFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { corpusHashFor, loadCorpus, plannedCorpus } from "./corpus.mjs";

export const REPO_ROOT = fileURLToPath(new URL("../..", import.meta.url));

export function settings(env = process.env) {
    return {
        runsDir:
            env.KNOSSOS_EVAL_RUNS ??
            path.join(
                os.homedir(),
                ".claude",
                "superpowers",
                "Knossos-MCP",
                "evals",
            ),
        // Outside the home directory: Claude Code loads memory from every ancestor of its cwd.
        evalRoot: env.KNOSSOS_EVAL_ROOT ?? "/var/tmp/knossos-eval",
        pluginDir:
            env.KNOSSOS_EVAL_PLUGIN_DIR ?? path.join(REPO_ROOT, ".plugin"),
        claudeBin: env.KNOSSOS_EVAL_CLAUDE ?? "claude",
        // Clears, keeps and restores a project's annotations between sessions; tests use a fake.
        notesBin:
            env.KNOSSOS_EVAL_NOTES ??
            path.join(REPO_ROOT, "tools", "eval", "annotations.php"),
        // Where the real credentials are copied from; tests point it at an empty dir.
        realHome: env.KNOSSOS_EVAL_REAL_HOME ?? os.homedir(),
        // The Knossos checkout whose commit a run records; tests point it at a throwaway repository.
        sourceRoot: env.KNOSSOS_EVAL_SOURCE_ROOT ?? REPO_ROOT,
        env,
    };
}

export const readJson = async (file) =>
    JSON.parse(await readFile(file, "utf8"));

export async function openRun(runId, s) {
    const dir = path.join(s.runsDir, runId);
    const meta = await readJson(path.join(dir, "meta.json"));
    const corpus = await loadCorpus(meta.corpusDir);
    const plan = await readJson(path.join(dir, "plan.json"));
    const planned = plannedCorpus(
        corpus,
        plan.map((e) => e.task),
    );
    if (corpusHashFor(planned, meta) !== meta.corpusHash)
        throw new Error(
            `the corpus changed since run ${runId} started; start a new run`,
        );
    return {
        dir,
        meta,
        corpus,
        // The tasks this run planned: what ablate, calibrate and the report
        // read, never the whole corpus, which may have grown since.
        tasks: planned.tasks,
        plan,
        ledger: path.join(dir, "ledger.jsonl"),
    };
}
