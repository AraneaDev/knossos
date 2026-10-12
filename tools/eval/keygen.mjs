#!/usr/bin/env node
/**
 * Dev-only. Helps write an answer key: lists text-search candidates for a
 * task's subject, and after the key is written by hand, records where Knossos
 * disagrees with it. Knossos never writes the key.
 */
import { execFile } from "node:child_process";
import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { parseArgs, promisify } from "node:util";
import { normalise } from "./answer.mjs";
import { loadCorpus } from "./corpus.mjs";
import { evalEnv, evalPaths } from "./env.mjs";
import { REPO_ROOT, settings } from "./run.mjs";

const exec = promisify(execFile);
const CALL_KINDS = new Set(["calls", "dispatches"]);

export function knossosAnswer(category, unit, json) {
    if (category === "callers") {
        const usages = (json.data?.usages ?? []).filter((u) =>
            CALL_KINDS.has(u.kind),
        );
        return usages.map((u) =>
            unit === "file" ? u.path : u.source.canonical_name,
        );
    }
    if (category === "impact" || category === "dependents") {
        return (json.data?.dependants ?? [])
            .map((d) =>
                unit === "file"
                    ? d.node.via?.evidence?.path
                    : d.node.canonical_name,
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
    const rg = await exec("rg", ["-n", "--no-heading", "-w", word, "."], {
        cwd: workspace,
        maxBuffer: 1 << 26,
    }).catch((e) => e);
    const lines = String(rg.stdout ?? "")
        .split("\n")
        .filter(Boolean);
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
            task.subject,
            "--limit=500",
            "--json",
        ],
        {
            env: evalEnv(paths, { base: s.env, realHome: s.realHome }),
            maxBuffer: 1 << 26,
        },
    );
    const diff = diffAnswers(
        task.answer.expected,
        knossosAnswer(task.category, task.answer.unit, JSON.parse(stdout)),
        {
            unit: task.answer.unit,
            language: corpus.repos[task.repo].language,
            workspace,
        },
    );
    await mkdir(paths.defects, { recursive: true });
    if (diff.missing.length === 0 && diff.extra.length === 0) {
        console.log("# Knossos agrees with the key");
        return;
    }
    await writeFile(
        path.join(paths.defects, `${task.id}.json`),
        JSON.stringify({ task: task.id, tool: command, ...diff }, null, 2),
    );
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
