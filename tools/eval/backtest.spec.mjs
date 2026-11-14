import { describe, expect, it } from "vitest";
import { conventionScore, countFor, extensionOf, goesWithScore, parseLog, pickCommits, render, summarise } from "./backtest.mjs";

describe("the creation brief backtest", () => {
    it("reads added and changed paths per commit from a name-status log", () => {
        const log = "\0aaa\n\nA\tsrc/New.php\nM\ttools/list.txt\nD\told.php\n\0bbb\n\nM\tREADME.md\n";
        expect(parseLog(log)).toEqual([
            { hash: "aaa", added: ["src/New.php"], changed: ["tools/list.txt", "old.php"] },
            { hash: "bbb", added: [], changed: ["README.md"] },
        ]);
    });

    it("replays only commits that add a scanned file and are not bulk changes, oldest first", () => {
        const bulk = Array.from({ length: 31 }, (_, i) => `src/F${i}.php`);
        const commits = [
            { hash: "new", added: ["src/A.php"], changed: [] },
            { hash: "docs", added: ["docs/a.md"], changed: [] },
            { hash: "bulk", added: bulk, changed: [] },
            { hash: "mid", added: ["lib/b.ts", "docs/b.md"], changed: [] },
            { hash: "old", added: ["x.rs"], changed: [] },
        ];
        expect(pickCommits(commits, 2).map((c) => c.hash)).toEqual(["mid", "new"]);
        expect(pickCommits(commits, 10).map((c) => c.hash)).toEqual(["old", "mid", "new"]);
    });

    it("takes the extension after the last dot, and none from a dotfile or a bare name", () => {
        expect([extensionOf("a/B.PHP"), extensionOf("a/b.spec.ts"), extensionOf("Makefile"), extensionOf("a/.env")]).toEqual(["php", "ts", "", ""]);
    });

    it("counts a convention's fact in a folder's facts, and nothing in a folder with none", () => {
        const facts = {
            kinds: { one_class: 6 },
            modifiers: { final: 5 },
            suffix: { value: "Service", files: 4 },
            injects: [{ type: "PDO", files: 3 }],
            uses: [{ type: "App\\Envelope", files: 2 }],
        };
        expect(countFor({ id: "kind", text: "one class per file" }, facts)).toBe(6);
        expect(countFor({ id: "modifier:final", text: "final" }, facts)).toBe(5);
        expect(countFor({ id: "modifier:readonly", text: "readonly" }, facts)).toBe(0);
        expect(countFor({ id: "suffix", text: "named *Service" }, facts)).toBe(4);
        expect(countFor({ id: "suffix", text: "named *Repository" }, facts)).toBe(0);
        expect(countFor({ id: "injects:PDO", text: "inject PDO" }, facts)).toBe(3);
        expect(countFor({ id: "uses:App\\Envelope", text: "use Envelope" }, facts)).toBe(2);
        expect(countFor({ id: "kind", text: "one class per file" }, null)).toBe(0);
    });

    it("scores conventions by how far the folder's counts moved, never past the files added", () => {
        const conventions = [
            { id: "kind", text: "one class per file" },
            { id: "modifier:final", text: "final" },
            { id: "lines", text: "median 10 lines, largest 20" },
        ];
        const before = { kinds: { one_class: 5 }, modifiers: { final: 5 } };
        const after = { kinds: { one_class: 7 }, modifiers: { final: 5 } };
        expect(conventionScore(conventions, before, after, 2)).toEqual([
            { id: "kind", text: "one class per file", shown: 2, followed: 2 },
            { id: "modifier:final", text: "final", shown: 2, followed: 0 },
        ]);
        // Another file changed in the same commit cannot make one new file count twice.
        expect(conventionScore(conventions, before, { kinds: { one_class: 9 }, modifiers: { final: 4 } }, 1).map((c) => c.followed)).toEqual([1, 0]);
    });

    it("scores what goes with a file against what the commit touched", () => {
        const brief = {
            path: "src/New.php",
            goes_with: [
                { kind: "test", path: "tests/NewTest.php" },
                { kind: "companion", path: "tools/list.txt" },
                { kind: "companion", path: "docs/api.md" },
            ],
        };
        const commit = { added: ["src/New.php", "tests/NewTest.php"], changed: ["tools/list.txt", "composer.json"] };
        expect(goesWithScore(brief, commit)).toEqual({ companions: 2, companionHits: 1, changedOthers: 2, changedOthersNamed: 1, test: 1, testHit: 1 });
        expect(goesWithScore({ path: "a.ts", goes_with: [] }, commit)).toEqual({ companions: 0, companionHits: 0, changedOthers: 2, changedOthersNamed: 0, test: 0, testHit: 0 });
    });

    it("sums a replay and gives shares only where there is something to divide by", () => {
        const rows = [
            { basis: true, borrowed: false, companions: 2, companionHits: 1, changedOthers: 4, changedOthersNamed: 1, test: 1, testHit: 1, conventions: [{ id: "modifier:final", shown: 1, followed: 1 }, { id: "kind", shown: 1, followed: 0 }] },
            { basis: true, borrowed: true, companions: 0, companionHits: 0, changedOthers: 0, changedOthersNamed: 0, test: 0, testHit: 0, conventions: [{ id: "modifier:readonly", shown: 2, followed: 1 }] },
            { basis: false, borrowed: false, companions: 0, companionHits: 0, changedOthers: 0, changedOthersNamed: 0, test: 0, testHit: 0, conventions: [] },
        ];
        const summary = summarise(rows);
        expect(summary).toMatchObject({ files: 3, briefed: 2, borrowed: 1, empty: 1 });
        expect(summary.test).toEqual({ predicted: 1, hit: 1, precision: 100 });
        expect(summary.companions).toEqual({ predicted: 2, hit: 1, precision: 50, otherFilesChanged: 4, recall: 25 });
        expect(summary.conventions).toEqual({ kind: { shown: 1, followed: 0, followedShare: 0 }, modifier: { shown: 3, followed: 2, followedShare: 66.7 } });
        expect(summarise([]).test.precision).toBeNull();
        expect(render("repo", summary)).toContain("convention modifier: shown for 3 new files, followed by 2 (66.7%)");
        expect(render("repo", summarise([]))).toContain("(n/a)");
    });
});
