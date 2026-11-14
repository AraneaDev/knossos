import { describe, expect, it } from "vitest";
import { applyPattern, caseOf, conventionScore, countFor, dominant, extensionOf, goesWithScore, looksLikeTest, nameFacts, parseLog, pickCommits, render, renderWhatIf, stemOf, summarise, whatIf, wordsOf } from "./backtest.mjs";

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

    it("names how a stem is written and what words it holds", () => {
        expect(["new-thing", "new_thing", "thing", "NewThing", "newThing", "New-Thing"].map(caseOf)).toEqual(["kebab", "snake", "lower", "pascal", "camel", "other"]);
        expect(wordsOf("FooHTTPClient")).toEqual(["Foo", "HTTP", "Client"]);
        expect(wordsOf("annotate-actions")).toEqual(["annotate", "actions"]);
        expect(wordsOf("bar_service.spec")).toEqual(["bar", "service", "spec"]);
        expect([stemOf("a/b.spec.ts"), stemOf("Makefile"), stemOf("a/.env")]).toEqual(["b.spec", "Makefile", ".env"]);
    });

    it("finds the most common value, ties to the one that sorts first", () => {
        expect(dominant(["b", "a", "b", "c"])).toEqual({ value: "b", count: 2, of: 4 });
        expect(dominant(["b", "a"])).toEqual({ value: "a", count: 1, of: 2 });
        expect(dominant([])).toEqual({ value: null, count: 0, of: 0 });
    });

    it("reads a folder's naming from the files already there and checks a new one against it", () => {
        const siblings = ["hooks/mod/annotate-actions.ts", "hooks/mod/diff-actions.ts", "hooks/mod/state.ts", "hooks/mod/port-type.ts"];
        const fits = nameFacts(siblings, "hooks/mod/notes-actions.ts");
        expect(fits.siblings).toBe(4);
        expect(fits.case).toMatchObject({ value: "kebab", count: 3, of: 4, followed: true });
        expect(fits.lastWord).toMatchObject({ value: "actions", count: 2, of: 4, followed: true });
        // A one-word name is written the same in a kebab folder; a PascalCase one is not.
        expect(nameFacts(siblings, "hooks/mod/watcher.ts").case.followed).toBe(true);
        expect(nameFacts(siblings, "hooks/mod/NewThing.ts").case.followed).toBe(false);
        expect(nameFacts(siblings, "hooks/mod/watcher.ts").lastWord.followed).toBe(false);
    });

    it("applies a stored test pattern and tells a test path by its name", () => {
        expect(applyPattern("tests/phpunit/Query/<name>Test.php", "src/Query/Foo.php")).toBe("tests/phpunit/Query/FooTest.php");
        expect(applyPattern("<dir>/<name>.spec.ts", "hooks/lib/notes.ts")).toBe("hooks/lib/notes.spec.ts");
        expect(applyPattern("<dir>/<name>.spec.ts", "notes.ts")).toBe("notes.spec.ts");
        expect(["src/a.spec.ts", "tests/A.php", "src/__tests__/a.ts", "src/FooTest.php", "pkg/test_a.py", "a_test.go", "src/contest.ts", "src/Foo.php"].map(looksLikeTest)).toEqual([true, true, true, true, true, true, false, false]);
    });

    it("shows what other bars would have shown and how often each would have been right", () => {
        const rows = [
            { raw: { testPattern: { files: 17, of: 27, hit: true }, companions: [{ commits: 6, of: 8, hit: true }, { commits: 5, of: 8, hit: false }], names: { siblings: 9, case: { count: 9, of: 9, followed: true }, lastWord: { count: 4, of: 9, followed: false } } }, shares: [{ id: "uses:X", files: 9, of: 10, shown: 2, followed: 1 }] },
            { raw: { testPattern: { files: 9, of: 10, hit: false }, companions: [{ commits: 3, of: 3, hit: true }], names: { siblings: 3, case: { count: 3, of: 3, followed: false }, lastWord: { count: 3, of: 3, followed: false } } }, shares: [] },
            { raw: { testPattern: null, companions: [], names: { siblings: 0, case: { count: 0, of: 0, followed: false }, lastWord: { count: 0, of: 0, followed: false } } } },
        ];
        const tables = whatIf(rows);
        expect(tables.expectedTest).toEqual([
            { bar: 50, shown: 2, right: 1, precision: 50 },
            { bar: 60, shown: 2, right: 1, precision: 50 },
            { bar: 70, shown: 1, right: 0, precision: 0 },
            { bar: 80, shown: 1, right: 0, precision: 0 },
        ]);
        expect(tables.goesWith.map((r) => [r.bar, r.shown, r.right])).toEqual([[60, 3, 2], [70, 2, 2], [80, 1, 1], [90, 1, 1], [100, 1, 1]]);
        expect(tables.uses.map((r) => [r.bar, r.shown, r.right])).toEqual([[80, 2, 1], [90, 2, 1], [100, 0, 0]]);
        // A folder of three files is below the floor and says nothing, whatever its share.
        expect(tables.nameCase.map((r) => [r.bar, r.shown, r.right])).toEqual([[60, 1, 1], [80, 1, 1], [90, 1, 1], [100, 1, 1]]);
        expect(tables.lastWord.map((r) => [r.bar, r.shown])).toEqual([[40, 1], [60, 0], [80, 0]]);
        expect(renderWhatIf("repo", tables)).toContain("expectedTest: at 50% shown 2, right 1 (50%)");
    });
});
