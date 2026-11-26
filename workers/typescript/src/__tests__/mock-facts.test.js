import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// What a mock template of a class or interface needs beyond the signature:
// whether a method is `async` (an unannotated one still returns a Promise)
// and the type parameters of the type itself, which are out of scope where
// the mock is written.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scan(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-mock-facts-")),
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
            .map((n) => [n.canonical_name, n.attributes]),
    );
}

describe("the facts a mock template reads", () => {
    it("marks an async function or method, and only that", () => {
        const nodes = scan({
            "src/svc.ts":
                "export class Svc {\n" +
                "    async late(id: string) { return 1; }\n" +
                "    plain(): number { return 0; }\n" +
                "    arrow = async (x: number): Promise<number> => x;\n" +
                "}\n" +
                "export async function load() {}\n" +
                "export function sync() {}\n",
        });

        expect(nodes["src/svc.ts#Svc::late"].async).toBe(true);
        expect(nodes["src/svc.ts#Svc::arrow"].async).toBe(true);
        expect(nodes["src/svc.ts#load"].async).toBe(true);
        expect(nodes["src/svc.ts#Svc::plain"]).not.toHaveProperty("async");
        expect(nodes["src/svc.ts#sync"]).not.toHaveProperty("async");
    });

    it("keeps a class's or interface's type parameters as written", () => {
        const nodes = scan({
            "src/box.ts":
                "export class Box<T, K extends string = 'id'> { get(): T { throw new Error(); } }\n" +
                "export interface Pair<A> { first: A }\n" +
                "export class Plain {}\n",
        });

        expect(nodes["src/box.ts#Box"].type_params).toEqual([
            "T",
            "K extends string = 'id'",
        ]);
        expect(nodes["src/box.ts#Pair"].type_params).toEqual(["A"]);
        expect(nodes["src/box.ts#Plain"]).not.toHaveProperty("type_params");
    });
});
