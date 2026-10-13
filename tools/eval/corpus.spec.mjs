import { mkdtemp, mkdir, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { describe, expect, it } from "vitest";
import {
    corpusHash,
    loadCorpus,
    validateRepo,
    validateTask,
} from "./corpus.mjs";

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
    const dir = await mkdtemp(path.join(os.tmpdir(), "eval-corpus-"));
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
        const dir = await mkdtemp(path.join(os.tmpdir(), "eval-corpus-"));
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
