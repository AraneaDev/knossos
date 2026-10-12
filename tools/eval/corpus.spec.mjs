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
        const dir = await corpusDir([task, { ...task, id: "Bad Id" }]);
        await expect(loadCorpus(dir)).rejects.toThrow(/Bad Id/);
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

describe("corpusHash", () => {
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
