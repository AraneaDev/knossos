import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { CATEGORIES, loadCorpus, tierOf } from "./corpus.mjs";

const DIR = fileURLToPath(new URL("./corpus", import.meta.url));

/** The categories a hard question may use: the shapes one grep does not answer. */
const HARD_CATEGORIES = ["impact", "flow", "callers"];

describe("the committed corpus", () => {
    it("has a valid, balanced easy tier", async () => {
        const easy = (await loadCorpus(DIR)).tasks.filter(
            (t) => tierOf(t) === "easy",
        );
        expect(easy.length).toBeGreaterThanOrEqual(25);
        expect(easy.length).toBeLessThanOrEqual(35);
        for (const category of CATEGORIES) {
            expect(
                easy.filter((t) => t.category === category).length,
            ).toBeGreaterThanOrEqual(4);
        }
    });

    it("keeps the hard tier to the shapes one grep does not answer", async () => {
        const hard = (await loadCorpus(DIR)).tasks.filter(
            (t) => tierOf(t) === "hard",
        );
        expect(hard.length).toBeLessThanOrEqual(40);
        for (const t of hard) expect(HARD_CATEGORIES).toContain(t.category);
    });
});
