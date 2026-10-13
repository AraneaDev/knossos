import * as fsp from "node:fs/promises";
import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { afterAll, describe, expect, it, vi } from "vitest";
import { removeTemp, tmp } from "./fixtures/tmp.mjs";
import {
    compareCodePoints,
    corpusHash,
    corpusHashFor,
    legacyCorpusHash,
    loadCorpus,
    TIERS,
    tierOf,
    validateRepo,
    validateTask,
} from "./corpus.mjs";

vi.mock("node:fs/promises", async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, readdir: vi.fn(actual.readdir) };
});

afterAll(removeTemp);

const SHA = "a".repeat(40);
const repos = {
    demo: {
        url: "https://example.test/demo.git",
        commit: SHA,
        language: "php",
    },
};
const task = {
    id: "demo-callers-total",
    repo: "demo",
    kind: "question",
    category: "callers",
    subject: "Invoice::total",
    prompt: "Which methods call Invoice::total()?",
    answer: { unit: "symbol", expected: ["App\\Billing::summarise"] },
    verified: "2026-10-13",
};

async function corpusDir(tasks, repoMap = repos) {
    const dir = await tmp("eval-corpus-");
    await writeFile(
        path.join(dir, "repos.json"),
        JSON.stringify({ repos: repoMap }),
    );
    for (const t of tasks) {
        await mkdir(path.join(dir, "tasks", t.repo), { recursive: true });
        await writeFile(
            path.join(dir, "tasks", t.repo, `${t.id}.json`),
            JSON.stringify(t),
        );
    }
    return dir;
}

describe("validateRepo", () => {
    it("accepts a pinned repo", () => {
        expect(validateRepo("demo", repos.demo)).toEqual([]);
    });
    it("rejects a short commit and an unknown language", () => {
        const errors = validateRepo("demo", {
            url: "x",
            commit: "abc",
            language: "go",
        });
        expect(errors).toHaveLength(2);
    });
});

describe("validateTask", () => {
    it("accepts a complete question", () => {
        expect(validateTask(task, repos)).toEqual([]);
    });
    it("rejects edit tasks, an unknown repo and an empty key", () => {
        const errors = validateTask(
            {
                ...task,
                kind: "edit",
                repo: "nope",
                answer: { unit: "symbol", expected: [] },
            },
            repos,
        );
        expect(errors.join("\n")).toMatch(/only question/);
        expect(errors.join("\n")).toMatch(/unknown repo nope/);
        expect(errors.join("\n")).toMatch(/non-empty list/);
    });
});

describe("loadCorpus", () => {
    it("loads repos and tasks", async () => {
        const corpus = await loadCorpus(await corpusDir([task]));
        expect(corpus.tasks.map((t) => t.id)).toEqual(["demo-callers-total"]);
    });
    it("reports every problem at once, with the file name", async () => {
        const dir = await corpusDir([
            task,
            { ...task, id: "Bad Id", verified: "2026-02-30", kind: "edit" },
        ]);
        const message = await loadCorpus(dir).then(
            () => "",
            (e) => e.message,
        );
        expect(message).toContain(`invalid corpus ${dir}:`);
        expect(message).toMatch(/task Bad Id: id must be lower-case/);
        expect(message).toMatch(/task Bad Id: kind edit is not supported/);
        expect(message).toMatch(/task Bad Id: verified must be a real/);
    });
    it("collects a missing repos.json and tasks dir under one error", async () => {
        const dir = await tmp("eval-corpus-");
        const message = await loadCorpus(dir).then(
            () => "",
            (e) => e.message,
        );
        expect(message).toContain(`invalid corpus ${dir}:`);
        expect(message).toContain(path.join(dir, "repos.json"));
        expect(message).toContain(path.join(dir, "tasks"));
    });
    it("names a malformed file and a non-object task", async () => {
        const dir = await corpusDir([task]);
        await writeFile(path.join(dir, "tasks", "demo", "broken.json"), "{");
        await writeFile(path.join(dir, "tasks", "demo", "list.json"), "[1]");
        await writeFile(path.join(dir, "tasks", "demo", "null.json"), "null");
        const message = await loadCorpus(dir).then(
            () => "",
            (e) => e.message,
        );
        expect(message).toContain(
            path.join(dir, "tasks", "demo", "broken.json"),
        );
        expect(message).toMatch(/list\.json: a task must be a JSON object/);
        expect(message).toMatch(/null\.json: a task must be a JSON object/);
    });
    it("rejects a repos.json without a repos object", async () => {
        const dir = await corpusDir([]);
        await writeFile(path.join(dir, "repos.json"), "{}");
        await expect(loadCorpus(dir)).rejects.toThrow(/must hold a "repos"/);
    });
    it("rejects a task filed under another repo's directory", async () => {
        const dir = await corpusDir([task]);
        await mkdir(path.join(dir, "tasks", "other"), { recursive: true });
        await writeFile(
            path.join(dir, "tasks", "other", `${task.id}-2.json`),
            JSON.stringify({ ...task, id: `${task.id}-2` }),
        );
        await expect(loadCorpus(dir)).rejects.toThrow(
            /lies under tasks\/other/,
        );
    });
});

describe("validateTask verified date", () => {
    const check = (verified) =>
        validateTask(
            { ...task, verified },
            repos,
            new Date("2026-10-13T12:00:00Z"),
        ).join("\n");
    it("rejects a date that does not exist", () => {
        expect(check("2026-02-30")).toMatch(/real calendar date/);
        expect(check("2026-13-01")).toMatch(/real calendar date/);
    });
    it("rejects a date in the future but not today", () => {
        expect(check("2026-10-13")).toBe("");
        expect(check("2026-10-14")).toBe("");
        expect(check("2026-10-15")).toMatch(/in the future/);
    });
});

describe("corpusHash", () => {
    it("ignores object key order and compares ids by code point", () => {
        const reordered = {
            ...task,
            answer: { expected: task.answer.expected, unit: "symbol" },
        };
        const one = { ...task, id: "B" };
        const two = { ...task, id: "a" };
        expect(corpusHash({ repos, tasks: [task] })).toBe(
            corpusHash({ repos, tasks: [reordered] }),
        );
        expect(corpusHash({ repos, tasks: [one, two] })).toBe(
            corpusHash({ repos, tasks: [two, one] }),
        );
    });
    it("changes when a task changes and ignores task order", () => {
        const a = corpusHash({ repos, tasks: [task, { ...task, id: "b" }] });
        const b = corpusHash({ repos, tasks: [{ ...task, id: "b" }, task] });
        const c = corpusHash({
            repos,
            tasks: [
                { ...task, prompt: "changed" },
                { ...task, id: "b" },
            ],
        });
        expect(a).toBe(b);
        expect(a).not.toBe(c);
    });
});

describe("compareCodePoints", () => {
    it("orders by code point, not by UTF-16 code unit", () => {
        // U+1F600 is the surrogate pair D83D DE00, which sorts before U+FFFF by code unit.
        expect(compareCodePoints("\u{1F600}", "\uFFFF")).toBe(1);
        expect(compareCodePoints("\uFFFF", "\u{1F600}")).toBe(-1);
        expect(compareCodePoints("a", "a")).toBe(0);
        expect(compareCodePoints("a", "ab")).toBeLessThan(0);
        expect(compareCodePoints("B", "a")).toBeLessThan(0);
    });
    it("makes the hash independent of task order for astral ids", () => {
        const a = { ...task, id: "\u{1F600}" };
        const b = { ...task, id: "\uFFFF" };
        expect(corpusHash({ repos, tasks: [a, b] })).toBe(
            corpusHash({ repos, tasks: [b, a] }),
        );
    });
});

describe("hash versions", () => {
    const LEGACY =
        "e35c159bb0972450f10a9157cd49e9db16a094c02c6bbcd5baec8db7712f555d";
    const loadLegacy = () =>
        loadCorpus(
            fileURLToPath(new URL("./fixtures/legacy-corpus", import.meta.url)),
        );
    it("reproduces the hash a pre-version run stored", async () => {
        // The meta.json of the pilot run 20261012-231441-sonnet stored LEGACY for exactly this corpus.
        const corpus = await loadLegacy();
        expect(legacyCorpusHash(corpus)).toBe(LEGACY);
        expect(corpusHashFor(corpus, { corpusHash: LEGACY })).toBe(LEGACY);
        expect(corpusHash(corpus)).not.toBe(LEGACY);
    });
    it("uses the current scheme once the meta says version 2", async () => {
        const corpus = await loadLegacy();
        expect(corpusHashFor(corpus, { corpusHashVersion: 2 })).toBe(
            corpusHash(corpus),
        );
    });
    it("ignores excludesSubjectFile in the legacy hash only", () => {
        const flagged = {
            repos,
            tasks: [
                {
                    ...task,
                    answer: { ...task.answer, excludesSubjectFile: true },
                },
            ],
        };
        expect(legacyCorpusHash(flagged)).toBe(
            legacyCorpusHash({ repos, tasks: [task] }),
        );
        expect(corpusHash(flagged)).not.toBe(
            corpusHash({ repos, tasks: [task] }),
        );
    });
});

describe("excludesSubjectFile", () => {
    const file = { ...task, answer: { unit: "file", expected: ["a.php"] } };
    it("accepts a boolean on a file answer", () => {
        expect(
            validateTask(
                {
                    ...file,
                    answer: { ...file.answer, excludesSubjectFile: true },
                },
                repos,
            ),
        ).toEqual([]);
    });
    it("rejects a non-boolean and a flag on a symbol answer", () => {
        const bad = validateTask(
            { ...file, answer: { ...file.answer, excludesSubjectFile: "yes" } },
            repos,
        );
        expect(bad.join()).toMatch(/must be true or false/);
        const symbol = validateTask(
            { ...task, answer: { ...task.answer, excludesSubjectFile: true } },
            repos,
        );
        expect(symbol.join()).toMatch(/only applies to file answers/);
    });
});

describe("loadCorpus with an unreadable task directory", () => {
    it("collects the failure instead of throwing a raw error", async () => {
        const dir = await corpusDir([task]);
        await mkdir(path.join(dir, "tasks", "bad"), { recursive: true });
        const real = fsp.readdir.getMockImplementation();
        fsp.readdir.mockImplementation((p, ...rest) =>
            String(p).endsWith(path.join("tasks", "bad"))
                ? Promise.reject(new Error("EACCES: permission denied"))
                : real(p, ...rest),
        );
        try {
            const message = await loadCorpus(dir).then(
                () => "",
                (e) => e.message,
            );
            expect(message).toContain(`invalid corpus ${dir}:`);
            expect(message).toContain(path.join(dir, "tasks", "bad"));
            expect(message).toContain("EACCES");
        } finally {
            fsp.readdir.mockImplementation(real);
        }
    });
});

describe("task tier", () => {
    it("defaults to easy and accepts easy or hard", () => {
        expect(tierOf({})).toBe("easy");
        expect(tierOf({ tier: "hard" })).toBe("hard");
        for (const tier of TIERS)
            expect(validateTask({ ...task, tier }, repos)).toEqual([]);
    });

    it("rejects any other value", () => {
        expect(validateTask({ ...task, tier: "medium" }, repos)).toEqual([
            `task ${task.id}: tier must be one of easy, hard`,
        ]);
    });
});
