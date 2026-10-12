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
    "Name symbols by their fully qualified name (for example Namespace\\\\Class::method or package.module.Class.method) and files by their path relative to the repository root.",
].join("\n");

const BLOCK = /```answer[ \t]*\r?\n([\s\S]*?)```/g;

export function extractAnswer(text) {
    if (typeof text !== "string") return null;
    const blocks = [...text.matchAll(BLOCK)];
    if (blocks.length === 0) return null;
    try {
        const value = JSON.parse(blocks.at(-1)[1]);
        return Array.isArray(value) && value.every((v) => typeof v === "string")
            ? value
            : null;
    } catch {
        return null;
    }
}

function normaliseFile(entry, workspace) {
    let s = entry.trim().replaceAll("\\", "/");
    if (workspace && path.isAbsolute(s)) {
        const rel = path.relative(workspace, s);
        if (!rel.startsWith("..")) s = rel;
    }
    return s
        .replace(/:\d+(:\d+)?$/, "")
        .replace(/^\.\//, "")
        .replace(/^\/+/, "")
        .replace(/\/+$/, "");
}

/** The last two segments of a symbol (Class::method), so a namespace the agent leaves out or adds does not decide the match. */
function normaliseSymbol(entry, language) {
    const unified = entry
        .trim()
        .replace(/\(.*\)$/, "")
        .replace(/\s+/g, "")
        .replaceAll("->", "::")
        .replaceAll("#", "::")
        .replaceAll(".", "::")
        .replaceAll("\\", "::");
    const key = unified.split("::").filter(Boolean).slice(-2).join("::");
    return language === "php" ? key.toLowerCase() : key;
}

export function normalise(entry, { unit, language, workspace }) {
    return unit === "file"
        ? normaliseFile(entry, workspace)
        : normaliseSymbol(entry, language);
}

export function scoreAnswer(expected, actual, options) {
    if (actual === null)
        return { status: "no-answer", precision: 0, recall: 0, f1: 0 };
    const want = new Set(expected.map((e) => normalise(e, options)));
    const got = new Set(
        actual.map((a) => normalise(a, options)).filter((a) => a !== ""),
    );
    const hits = [...got].filter((g) => want.has(g)).length;
    const precision = got.size === 0 ? 0 : hits / got.size;
    const recall = hits / want.size;
    const f1 =
        precision + recall === 0
            ? 0
            : (2 * precision * recall) / (precision + recall);
    return { status: "scored", precision, recall, f1 };
}
