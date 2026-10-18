import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// A browser module cache-busts what it loads lazily with the page's query
// (`` import(`./draw.mjs${query}`) ``). The compiler binds no module to a
// template, so the module looked reached by nothing. The text before the
// first `${` names the file; when it does not end in one, the expression
// decides the file and nothing is resolved.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function dynamicImports(files, scanned) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-template-imports-")),
    );
    created.push(root);
    if (typeof files === "function") files = files(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const contributions = [];
    new TypeScriptScanner().scan({ root, files: scanned }, (c) =>
        contributions.push(c),
    );
    return contributions
        .flatMap((c) => c.edges)
        .filter((e) => e.kind === "imports" && e.attributes?.dynamic)
        .map((e) => e.target)
        .sort();
}

describe("a dynamic import of a template literal", () => {
    it("loads the file its head names, whatever follows", () => {
        expect(
            dynamicImports(
                {
                    "web/app.mjs": [
                        "const query = new URL(import.meta.url).search;",
                        "export const start = async () => {",
                        "    const draw = await import(`./draw.mjs${query}`);",
                        "    const lib = await import(`../lib/x.js${'#v1'}`);",
                        "    const plain = await import(`./plain.mjs`);",
                        "    return [draw, lib, plain];",
                        "};",
                        "",
                    ].join("\n"),
                    "web/draw.mjs": "export const drawFrame = () => 1;\n",
                    "web/plain.mjs": "export const p = 1;\n",
                    "lib/x.js": "export const x = 1;\n",
                },
                ["web/app.mjs", "web/draw.mjs", "web/plain.mjs", "lib/x.js"],
            ),
        ).toEqual([
            "ts:module:lib/x.js",
            "ts:module:web/draw.mjs",
            "ts:module:web/plain.mjs",
        ]);
    });

    it("loads nothing when the expression decides the file", () => {
        expect(
            dynamicImports(
                {
                    "web/app.mjs": [
                        "const base = '.';",
                        "const name = 'draw';",
                        "export const start = async () => [",
                        "    await import(`${base}/draw.mjs`),",
                        "    await import(`./${name}.mjs`),",
                        "    await import(`./dir/${name}.mjs`),",
                        "    await import(`./missing.mjs${base}`),",
                        "];",
                        "",
                    ].join("\n"),
                    "web/draw.mjs": "export const drawFrame = () => 1;\n",
                    "web/dir/draw.mjs": "export const drawFrame = () => 1;\n",
                },
                ["web/app.mjs", "web/draw.mjs", "web/dir/draw.mjs"],
            ),
        ).toEqual([]);
    });

    it("leaves a string literal resolved as before", () => {
        expect(
            dynamicImports(
                {
                    "web/app.mjs":
                        "export const start = () => import('./draw.mjs');\n",
                    "web/draw.mjs": "export const drawFrame = () => 1;\n",
                },
                ["web/app.mjs", "web/draw.mjs"],
            ),
        ).toEqual(["ts:module:web/draw.mjs"]);
    });
    it("drops a query or fragment written into the head", () => {
        expect(
            dynamicImports(
                {
                    "web/app.mjs": [
                        "const n = 2;",
                        "export const start = async () => [",
                        "    await import(`./draw.mjs?v=${n}`),",
                        "    await import(`./card.mjs#x${n}`),",
                        "];",
                        "",
                    ].join("\n"),
                    "web/draw.mjs": "export const drawFrame = () => 1;\n",
                    "web/card.mjs": "export const card = () => 1;\n",
                },
                ["web/app.mjs", "web/draw.mjs", "web/card.mjs"],
            ),
        ).toEqual(["ts:module:web/card.mjs", "ts:module:web/draw.mjs"]);
    });

    it("names only the exact file, and never an absolute path", () => {
        expect(
            dynamicImports(
                (root) => ({
                    "web/app.mjs": [
                        "const q = '';",
                        "export const start = async () => [",
                        "    await import(`./widget.v2${q}`),",
                        `    await import(\`${root}/web/draw.mjs\${q}\`),`,
                        "];",
                        "",
                    ].join("\n"),
                    "web/widget.v2.ts": "export const widget = 1;\n",
                    "web/draw.mjs": "export const drawFrame = () => 1;\n",
                }),
                ["web/app.mjs", "web/widget.v2.ts", "web/draw.mjs"],
            ),
        ).toEqual([]);
    });
});
