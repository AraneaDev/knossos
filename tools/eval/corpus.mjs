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

const DAY_MS = 24 * 60 * 60 * 1000;

/** True when `value` is a real calendar date written YYYY-MM-DD. */
function isCalendarDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
    const date = new Date(`${value}T00:00:00Z`);
    return (
        !Number.isNaN(date.getTime()) && date.toISOString().startsWith(value)
    );
}

export function validateTask(task, repos, now = new Date()) {
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
    if (typeof task.verified !== "string" || !isCalendarDate(task.verified)) {
        fail("verified must be a real calendar date written YYYY-MM-DD");
    } else if (
        // One day of slack so a verifier ahead of UTC is not rejected.
        Date.parse(`${task.verified}T00:00:00Z`) >
        now.getTime() + DAY_MS
    ) {
        fail("verified must not be in the future");
    }
    return errors;
}

const isObject = (value) =>
    typeof value === "object" && value !== null && !Array.isArray(value);

/** Reads one JSON file, recording a problem in `errors` instead of throwing. */
async function readJson(file, errors) {
    try {
        return JSON.parse(await readFile(file, "utf8"));
    } catch (error) {
        errors.push(`${file}: ${error.message}`);
        return undefined;
    }
}

export async function loadCorpus(dir) {
    const errors = [];
    const reposFile = path.join(dir, "repos.json");
    const parsed = await readJson(reposFile, errors);
    let repos = {};
    if (parsed !== undefined) {
        if (isObject(parsed?.repos)) repos = parsed.repos;
        else errors.push(`${reposFile}: must hold a "repos" object`);
    }
    for (const [name, repo] of Object.entries(repos)) {
        if (isObject(repo)) errors.push(...validateRepo(name, repo));
        else errors.push(`repo ${name}: must be an object`);
    }
    const tasks = [];
    const tasksDir = path.join(dir, "tasks");
    let repoDirs = [];
    try {
        repoDirs = (await readdir(tasksDir, { withFileTypes: true }))
            .filter((e) => e.isDirectory())
            .map((e) => e.name)
            .sort();
    } catch (error) {
        errors.push(`${tasksDir}: ${error.message}`);
    }
    for (const repoDir of repoDirs) {
        const files = (await readdir(path.join(tasksDir, repoDir)))
            .filter((f) => f.endsWith(".json"))
            .sort();
        for (const file of files) {
            const where = path.join(tasksDir, repoDir, file);
            const task = await readJson(where, errors);
            if (task === undefined) continue;
            if (!isObject(task)) {
                errors.push(`${where}: a task must be a JSON object`);
                continue;
            }
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

/** JSON with object keys sorted recursively, so key order never changes a hash. */
export function canonicalJson(value) {
    if (Array.isArray(value)) return `[${value.map(canonicalJson).join(",")}]`;
    if (isObject(value)) {
        const keys = Object.keys(value).sort(compareCodePoints);
        return `{${keys
            .filter((k) => value[k] !== undefined)
            .map((k) => `${JSON.stringify(k)}:${canonicalJson(value[k])}`)
            .join(",")}}`;
    }
    return JSON.stringify(value) ?? "null";
}

/** Locale-independent string order. */
function compareCodePoints(a, b) {
    return a < b ? -1 : a > b ? 1 : 0;
}

/** A fingerprint of everything a run's results depend on: the repos and every task, order-independent. */
export function corpusHash({ repos, tasks }) {
    const sorted = [...tasks].sort((a, b) => compareCodePoints(a.id, b.id));
    return createHash("sha256")
        .update(canonicalJson({ repos, tasks: sorted }))
        .digest("hex");
}
