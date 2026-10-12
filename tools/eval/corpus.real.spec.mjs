import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { CATEGORIES, loadCorpus } from "./corpus.mjs";

const DIR = fileURLToPath(new URL("./corpus", import.meta.url));

describe("the committed corpus", () => {
    it("is valid and balanced", async () => {
        const { tasks } = await loadCorpus(DIR);
        expect(tasks.length).toBeGreaterThanOrEqual(25);
        expect(tasks.length).toBeLessThanOrEqual(35);
        for (const category of CATEGORIES) {
            expect(
                tasks.filter((t) => t.category === category).length,
            ).toBeGreaterThanOrEqual(4);
        }
    });
});
