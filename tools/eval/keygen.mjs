#!/usr/bin/env node
/**
 * Dev-only. Helps write an answer key: lists text-search candidates for a
 * task's subject, and after the key is written by hand, records where Knossos
 * disagrees with it. Knossos never writes the key.
 */
import { execFile } from "node:child_process";
import { mkdir, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { parseArgs, promisify } from "node:util";
import { normalise } from "./answer.mjs";
import { loadCorpus } from "./corpus.mjs";
import { evalEnv, evalPaths } from "./env.mjs";
import { REPO_ROOT, settings } from "./run.mjs";

const exec = promisify(execFile);
const CALL_KINDS = new Set(["calls", "dispatches"]);

const TEST_DIR = /(^|\/)(tests?|__tests__)\//;
const TEST_FILE =
    /(\.test\.tsx?|\.spec\.ts|(^|\/)(testutil\.rs|conftest\.py))$/;

/** Whether a file is test code; the answer keys never list test callers. */
export const isTestPath = (file) =>
    typeof file === "string" && (TEST_DIR.test(file) || TEST_FILE.test(file));

/**
 * Set when Knossos did not resolve the query: `{query, candidates, summary}`,
 * else null. A resolved answer from either command carries `data.target`; the
 * no-match shapes may still carry empty `usages` or `dependants`, and an error
 * shape has no `data` at all.
 */
export function unresolved(json) {
    const data = json.data;
    if (data !== undefined && data !== null && data.target) return null;
    const candidates = (data?.candidates ?? [])
        .map((c) => (typeof c === "string" ? c : c?.canonical_name))
        .filter(Boolean);
    return {
        query: data?.query ?? null,
        candidates: candidates.slice(0, 5),
        summary: json.summary ?? null,
    };
}

/** Whether Knossos cut its answer off at the result limit. */
export const isTruncated = (json) =>
    (json.bounds ?? json.data?.bounds)?.truncation_reasons?.includes(
        "result_limit",
    ) === true;

export function knossosAnswer(category, unit, json) {
    if (category === "callers") {
        const usages = (json.data?.usages ?? []).filter(
            (u) => CALL_KINDS.has(u.kind) && !isTestPath(u.path),
        );
        return usages
            .map((u) => (unit === "file" ? u.path : u.source?.canonical_name))
            .filter(Boolean);
    }
    if (category === "impact" || category === "dependents") {
        return (json.data?.dependants ?? [])
            .filter((d) => !isTestPath(d.node?.via?.evidence?.path))
            .map((d) =>
                unit === "file"
                    ? d.node?.via?.evidence?.path
                    : d.node?.canonical_name,
            )
            .filter(Boolean);
    }
    return null;
}

export function diffAnswers(expected, knossos, options) {
    const want = new Set(expected.map((e) => normalise(e, options)));
    const got = new Set(knossos.map((k) => normalise(k, options)));
    return {
        missing: [...want].filter((w) => !got.has(w)).sort(),
        extra: [...got].filter((g) => !want.has(g)).sort(),
    };
}

/**
 * Lines of `rg -w word` in `cwd`. Only rg's exit code 1 (no match) is an
 * empty answer; a missing rg or workspace, or any other failure, throws.
 */
export async function rgLines(word, cwd) {
    try {
        const { stdout } = await exec(
            "rg",
            ["-n", "--no-heading", "-w", word, "."],
            { cwd, maxBuffer: 1 << 26 },
        );
        return stdout.split("\n").filter(Boolean);
    } catch (error) {
        if (error.code === 1) return [];
        throw new Error(
            `rg failed in ${cwd} (${error.code ?? "no exit code"}): ${String(error.stderr ?? "").trim() || error.message}`,
            { cause: error },
        );
    }
}

/** Deletes a stale `defects/<task>.json`. */
export const clearDefect = (paths, task) =>
    rm(path.join(paths.defects, `${task}.json`), { force: true });

/** Writes the disagreement to `defects/<task>.json`, or deletes a stale one when Knossos agrees now. Returns whether it disagrees. */
export async function recordDefect(paths, task, command, diff) {
    const file = path.join(paths.defects, `${task}.json`);
    await mkdir(paths.defects, { recursive: true });
    if (diff.missing.length === 0 && diff.extra.length === 0) {
        await clearDefect(paths, task);
        return false;
    }
    await writeFile(
        file,
        JSON.stringify({ task, tool: command, ...diff }, null, 2),
    );
    return true;
}

async function main(args) {
    const s = settings();
    const { values, positionals } = parseArgs({
        args,
        allowPositionals: true,
        options: {
            corpus: {
                type: "string",
                default: path.join(REPO_ROOT, "tools", "eval", "corpus"),
            },
            // Overrides the subject as the Knossos query when the CLI only resolves another spelling.
            query: { type: "string" },
        },
    });
    const corpus = await loadCorpus(path.resolve(values.corpus));
    const task = corpus.tasks.find((t) => t.id === positionals[0]);
    if (!task) throw new Error(`no task ${positionals[0]}`);
    const paths = evalPaths(s.evalRoot);
    const workspace = path.join(paths.repos, task.repo);
    const word = task.subject
        .split(/[^A-Za-z0-9_]+/)
        .filter(Boolean)
        .at(-1);
    const lines = await rgLines(word, workspace);
    console.log(
        `# rg -w ${word}: ${lines.length} lines${lines.length > 200 ? ", first 200 shown" : ""}`,
    );
    console.log(lines.slice(0, 200).join("\n"));
    const command =
        task.category === "callers"
            ? "list-usages"
            : task.category === "impact" || task.category === "dependents"
              ? "impact-analysis"
              : null;
    if (command === null) {
        console.log(
            `# no Knossos CLI comparison for category ${task.category}`,
        );
        return;
    }
    const { stdout } = await exec(
        "php",
        [
            path.join(REPO_ROOT, "bin", "knossos"),
            command,
            workspace,
            values.query ?? task.subject,
            "--limit=100",
            "--json",
        ],
        { env: await evalEnv(paths, { base: s.env }), maxBuffer: 1 << 26 },
    );
    const json = JSON.parse(stdout);
    const miss = unresolved(json);
    if (miss !== null) {
        console.log(
            `# Knossos did not resolve ${miss.query ?? values.query ?? task.subject}${miss.candidates.length > 0 ? `; candidates: ${miss.candidates.join(", ")}` : ""}`,
        );
        if (miss.summary !== null) console.log(`# ${miss.summary}`);
        await clearDefect(paths, task.id);
        return;
    }
    if (isTruncated(json))
        console.log(
            "# warning: Knossos cut its answer off at the result limit (100)",
        );
    const diff = diffAnswers(
        task.answer.expected,
        knossosAnswer(task.category, task.answer.unit, json),
        {
            unit: task.answer.unit,
            language: corpus.repos[task.repo].language,
            workspace,
        },
    );
    if (!(await recordDefect(paths, task.id, command, diff))) {
        console.log("# Knossos agrees with the key");
        return;
    }
    console.log(
        `# Knossos disagrees: missing ${diff.missing.join(", ") || "none"}; extra ${diff.extra.join(", ") || "none"}`,
    );
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    main(process.argv.slice(2)).catch((error) => {
        console.error(error.message);
        process.exit(1);
    });
}
