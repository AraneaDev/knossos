/**
 * Dev-only. The eval's answer contract: the agent ends with a fenced `answer`
 * block holding a JSON array, scored against the task's key on symbols or
 * files, never on line numbers.
 */
import path from "node:path";

export const ANSWER_INSTRUCTION = [
    "",
    "",
    "When you are done, end your reply with a fenced code block tagged `answer` that holds a JSON array of strings: your complete answer, one entry per item.",
    "The block must be valid JSON, so write each backslash in a name twice.",
    "Name each entry as the question asks. Where the question does not say, name symbols by their fully qualified name (for example Namespace\\\\Class::method or package.module.Class.method) and files by their path relative to the repository root.",
].join("\n");

const BLOCK = /```answer[ \t]*\r?\n([\s\S]*?)```/g;

const isStrings = (value) =>
    Array.isArray(value) && value.every((v) => typeof v === "string");
// eslint-disable-next-line no-control-regex
const CONTROL = /[\u0000-\u001f]/;

/** Doubles every lone backslash, leaving `\\` pairs and `\"` alone. */
const doubleLoneBackslashes = (raw) =>
    raw.replace(/\\[\\"]|\\/g, (m) => (m.length === 2 ? m : "\\\\"));

export function extractAnswer(text) {
    if (typeof text !== "string") return null;
    const blocks = [...text.matchAll(BLOCK)];
    if (blocks.length === 0) return null;
    const raw = blocks.at(-1)[1];
    try {
        const value = JSON.parse(raw);
        if (!isStrings(value)) return null;
        if (!value.some((v) => CONTROL.test(v))) return value;
    } catch {
        // fall through to the lenient retry
    }
    // An unescaped PHP namespace is invalid JSON, or parses `\n` as a newline; retry once with lone backslashes doubled.
    try {
        const value = JSON.parse(doubleLoneBackslashes(raw));
        return isStrings(value) ? value : null;
    } catch {
        return null;
    }
}

function normaliseFile(entry, workspace) {
    let s = entry.trim().replaceAll("\\", "/");
    if (workspace && path.isAbsolute(s)) {
        const rel = path.relative(workspace, s);
        if (rel !== ".." && !rel.startsWith("../") && !path.isAbsolute(rel))
            s = rel;
    }
    return s
        .replace(/:\d+(:\d+)?$/, "")
        .replace(/^\.\//, "")
        .replace(/^\/+/, "")
        .replace(/\/+$/, "");
}

const SEPARATOR = /::|->|[.\\/#]/;
// File extensions and path or crate roots: they say where a symbol lives, never which one it is.
const NOISE = new Set([
    "php",
    "ts",
    "tsx",
    "js",
    "jsx",
    "mjs",
    "py",
    "rs",
    "crate",
    "self",
    "src",
]);

/** A symbol's name segments, so `src/a/b.py::f`, `a.b.f` and `crate::a::f` compare segment by segment. */
export function symbolSegments(entry, language) {
    let text = entry
        .trim()
        .replace(/\s+/g, "")
        .replace(/\([^()]*\)$/, "");
    if (language === "php") text = text.toLowerCase();
    return text.split(SEPARATOR).filter((s) => s !== "" && !NOISE.has(s));
}

export function normalise(entry, { unit, language, workspace }) {
    return unit === "file"
        ? normaliseFile(entry, workspace)
        : symbolSegments(entry, language).join("::");
}

const isSuffix = (short, long) =>
    short.length <= long.length &&
    short.every((s, i) => s === long[long.length - short.length + i]);

/** How many trailing segments two symbols share when one names the other, else 0. */
function overlap(want, got) {
    if (isSuffix(want, got)) return want.length;
    if (got.length >= 2 && isSuffix(got, want)) return got.length;
    return 0;
}

/** Distinct entries by normalised key, empty keys dropped, each keeping its first spelling. */
function distinct(entries, options) {
    const byKey = new Map();
    for (const entry of entries) {
        const key = normalise(entry, options);
        if (key !== "" && !byKey.has(key)) byKey.set(key, entry);
    }
    return [...byKey].map(([key, entry]) => ({
        key,
        entry,
        segments: key.split("::"),
    }));
}

/**
 * Pairs answer entries with expected ones, each used at most once. Files
 * match on their normalised path. A symbol matches when one's segments end
 * the other's: an answer may add a namespace or drop one down to two
 * segments, and a bare name only matches a bare key. Longest overlap pairs
 * first, so one answer never stands in for two keys.
 */
export function matchEntries(expected, actual, options) {
    const want = distinct(expected, options);
    const got = distinct(actual, options);
    const candidates = [];
    for (const [i, w] of want.entries()) {
        for (const [j, g] of got.entries()) {
            const n =
                options.unit === "file"
                    ? Number(w.key === g.key)
                    : overlap(w.segments, g.segments);
            if (n > 0) candidates.push({ i, j, n });
        }
    }
    candidates.sort((a, b) => b.n - a.n);
    const usedWant = new Set();
    const usedGot = new Set();
    for (const { i, j } of candidates) {
        if (usedWant.has(i) || usedGot.has(j)) continue;
        usedWant.add(i);
        usedGot.add(j);
    }
    return {
        expected: want.length,
        answered: got.length,
        hits: usedWant.size,
        missing: want.filter((_, i) => !usedWant.has(i)).map((w) => w.entry),
        extra: got.filter((_, j) => !usedGot.has(j)).map((g) => g.entry),
    };
}

/** Precision over distinct answer entries, recall over distinct expected ones. */
export function scoreAnswer(expected, actual, options) {
    if (actual === null)
        return { status: "no-answer", precision: 0, recall: 0, f1: 0 };
    const m = matchEntries(expected, actual, options);
    const precision = m.answered === 0 ? 0 : m.hits / m.answered;
    const recall = m.expected === 0 ? 0 : m.hits / m.expected;
    const f1 =
        precision + recall === 0
            ? 0
            : (2 * precision * recall) / (precision + recall);
    return { status: "scored", precision, recall, f1 };
}
