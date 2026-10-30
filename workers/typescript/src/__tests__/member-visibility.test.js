import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// A private or protected member cannot meet a public member of an interface,
// so the core must not read a class as fitting an interface by its name. The
// member says so on its node; a public one carries nothing extra.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scan(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-visibility-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const contributions = [];
    new TypeScriptScanner().scan(
        { limits: SCAN_LIMITS, root, files: Object.keys(files) },
        (c) => contributions.push(c),
    );
    return contributions.flatMap((c) => c.nodes);
}

describe("a class member's visibility", () => {
    it("is recorded when private or protected, and left out when public", () => {
        const nodes = scan({
            "src/store.ts":
                "export class Store {\n" +
                "    private hidden(): void {}\n" +
                "    protected guarded(): void {}\n" +
                "    shown(): void {}\n" +
                "    public open(): void {}\n" +
                "}\n",
        });
        const visibility = Object.fromEntries(
            nodes
                .filter((n) => n.kind === "method")
                .map((n) => [
                    n.canonical_name.slice(n.canonical_name.indexOf("::") + 2),
                    n.attributes.visibility,
                ]),
        );

        expect(visibility).toEqual({
            hidden: "private",
            guarded: "protected",
            shown: undefined,
            open: undefined,
        });
    });
});
