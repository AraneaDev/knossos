import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// A module node lists every name it exports and a class or interface node
// every member it offers, so the core can tell a name that is gone from one
// the graph has no node for (`export const`, aliases, accessors, parameter
// properties). A list that cannot be complete says so.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

/** The attributes of every module, class and interface node, by display name. */
function scan(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-names-")),
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
    return Object.fromEntries(
        contributions
            .flatMap((c) => c.nodes)
            .filter((node) =>
                ["module", "class", "interface"].includes(node.kind),
            )
            .map((node) => [node.display_name, node.attributes]),
    );
}

describe("a module's export list", () => {
    it("covers constants, aliases, default and re-exports", () => {
        const nodes = scan({
            "src/config.ts":
                "export const API_URL = 'x';\nexport let count = 1;\n" +
                "export function helper() {}\nexport { helper as h };\n" +
                "export default class {}\nexport * from './other';\n",
            "src/other.ts": "export const other = 1;\n",
        });

        expect(nodes["config.ts"].exports).toEqual([
            "API_URL",
            "count",
            "default",
            "h",
            "helper",
            "other",
        ]);
        expect(nodes["config.ts"].exports_open).toBeUndefined();
    });

    it("says it is open after an export * the program cannot resolve", () => {
        const nodes = scan({
            "src/a.ts": "export const a = 1;\nexport * from 'missing-pkg';\n",
            "src/b.ts": "const b = 1;\nexport = b;\n",
        });

        expect(nodes["a.ts"].exports_open).toBe(true);
        expect(nodes["b.ts"].exports_open).toBe(true);
    });

    it("is absent from a file that is not a module", () => {
        const nodes = scan({ "src/script.ts": "const x = 1;\n" });

        expect(nodes["script.ts"].exports).toBeUndefined();
    });
});

describe("a class's or interface's member list", () => {
    it("covers accessors, parameter properties, static and inherited members", () => {
        const nodes = scan({
            "src/cart.ts":
                "export class Base { base() {} }\n" +
                "export class Cart extends Base {\n" +
                "    static make() {}\n    private items = [];\n" +
                "    constructor(private repo: string) { super(); }\n" +
                "    get total() { return 1; }\n}\n" +
                "interface Named { name: string }\n" +
                "export interface Tagged extends Named { tag(): void }\n",
        });

        expect(nodes.Cart.member_names).toEqual([
            "base",
            "items",
            "make",
            "repo",
            "total",
        ]);
        expect(nodes.Tagged.member_names).toEqual(["name", "tag"]);
        expect(nodes.Cart.member_names_open).toBeUndefined();
    });

    it("says it is open when a base type cannot be read or any name is a member", () => {
        const nodes = scan({
            "src/bad.ts":
                "import { Gone } from './gone';\n" +
                "export class Bad extends Gone { m() {} }\n" +
                "export interface Bag { [key: string]: number }\n",
        });

        expect(nodes.Bad.member_names_open).toBe(true);
        expect(nodes.Bag.member_names_open).toBe(true);
    });
});
