/**
 * Dev-only. Loads and validates the eval corpus: the pinned repos and the
 * questions asked about them, each with a hand-verified answer key.
 */
import { createHash } from "node:crypto";
import { readdir, readFile } from "node:fs/promises";
import path from "node:path";

export const CATEGORIES = [
    "callers",
    "impact",
    "location",
    "flow",
    "dependents",
];
export const LANGUAGES = ["php", "typescript", "python", "rust"];
const UNITS = ["symbol", "file"];
const NAME = /^[a-z0-9-]+$/;

export function validateRepo(name, repo) {
    const errors = [];
    const fail = (m) => errors.push(`repo ${name}: ${m}`);
    if (!NAME.test(name))
        fail("name must be lower-case letters, digits and dashes");
    if (typeof repo.url !== "string" || repo.url === "")
        fail("url is required");
    if (!/^[0-9a-f]{40}$/.test(repo.commit ?? ""))
        fail("commit must be a full 40-character SHA");
    if (!LANGUAGES.includes(repo.language))
        fail(`language must be one of ${LANGUAGES.join(", ")}`);
    return errors;
}

export function validateTask(task, repos) {
    const errors = [];
    const fail = (m) => errors.push(`task ${task.id ?? "(no id)"}: ${m}`);
    if (!NAME.test(task.id ?? ""))
        fail("id must be lower-case letters, digits and dashes");
    if (!Object.hasOwn(repos, task.repo ?? ""))
        fail(`unknown repo ${task.repo}`);
    if (task.kind !== "question")
        fail(`kind ${task.kind} is not supported yet, only question`);
    if (!CATEGORIES.includes(task.category))
        fail(`category must be one of ${CATEGORIES.join(", ")}`);
    for (const field of ["subject", "prompt"]) {
        if (typeof task[field] !== "string" || task[field].trim() === "")
            fail(`${field} is required`);
    }
    if (!UNITS.includes(task.answer?.unit))
        fail("answer.unit must be symbol or file");
    const expected = task.answer?.expected;
    if (
        !Array.isArray(expected) ||
        expected.length === 0 ||
        expected.some((e) => typeof e !== "string" || e === "")
    ) {
        fail("answer.expected must be a non-empty list of strings");
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(task.verified ?? ""))
        fail("verified must be a YYYY-MM-DD date");
    return errors;
}

async function readJson(file) {
    try {
        return JSON.parse(await readFile(file, "utf8"));
    } catch (error) {
        throw new Error(`${file}: ${error.message}`, { cause: error });
    }
}

export async function loadCorpus(dir) {
    const { repos } = await readJson(path.join(dir, "repos.json"));
    const errors = Object.entries(repos).flatMap(([name, repo]) =>
        validateRepo(name, repo),
    );
    const tasks = [];
    const tasksDir = path.join(dir, "tasks");
    const repoDirs = (await readdir(tasksDir, { withFileTypes: true }))
        .filter((e) => e.isDirectory())
        .map((e) => e.name)
        .sort();
    for (const repoDir of repoDirs) {
        const files = (await readdir(path.join(tasksDir, repoDir)))
            .filter((f) => f.endsWith(".json"))
            .sort();
        for (const file of files) {
            const task = await readJson(path.join(tasksDir, repoDir, file));
            errors.push(...validateTask(task, repos));
            if (task.repo !== repoDir)
                errors.push(
                    `task ${task.id}: lies under tasks/${repoDir} but names repo ${task.repo}`,
                );
            if (`${task.id}.json` !== file)
                errors.push(
                    `task ${task.id}: file ${repoDir}/${file} must be named ${task.id}.json`,
                );
            tasks.push(task);
        }
    }
    const seen = new Set();
    for (const t of tasks) {
        if (seen.has(t.id)) errors.push(`task ${t.id}: duplicate id`);
        seen.add(t.id);
    }
    if (errors.length > 0)
        throw new Error(`invalid corpus ${dir}:\n${errors.join("\n")}`);
    return { repos, tasks };
}

/** A fingerprint of everything a run's results depend on: the repos and every task, order-independent. */
export function corpusHash({ repos, tasks }) {
    const sorted = [...tasks].sort((a, b) => a.id.localeCompare(b.id));
    return createHash("sha256")
        .update(JSON.stringify({ repos, tasks: sorted }))
        .digest("hex");
}
