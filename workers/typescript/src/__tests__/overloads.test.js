import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// A declaration kept under one id carries only its first signature, so an
// overloaded function or method says it has more than one.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scan(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-overloads-")),
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

describe("an overloaded declaration", () => {
    it("is marked overloaded, and a single signature is not", () => {
        const nodes = scan({
            "src/store.ts":
                "export class Store {\n" +
                "    find(id: string): string;\n" +
                "    find(id: string, deep: boolean): string;\n" +
                "    find(id: string, deep?: boolean): string { return id; }\n" +
                "    once(id: string): string { return id; }\n" +
                "}\n",
        });
        const attributes = Object.fromEntries(
            nodes
                .filter((node) => node.kind === "method")
                .map((node) => [node.display_name, node.attributes]),
        );

        expect(attributes.find.overloaded).toBe(true);
        expect(attributes.once.overloaded).toBeUndefined();
    });
});
