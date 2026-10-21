import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// A method picked out of a table by a computed key, and a method called on a
// destructured parameter that only the call site types.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function edges(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-tables-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const contributions = [];
    new TypeScriptScanner().scan({ root, files: Object.keys(files) }, (c) =>
        contributions.push(c),
    );
    return contributions
        .flatMap((c) => c.edges)
        .filter((e) => e.kind !== "contains" && e.target.includes("::"))
        .map((e) => `${e.kind} ${e.confidence} ${e.source} -> ${e.target}`)
        .sort();
}

function table(size) {
    const entries = Array.from(
        { length: size },
        (_, i) => `    m${i}() { return ${i}; },\n`,
    ).join("");
    return `const T = {\n${entries}};\nexport function pick(k) { return T[k](); }\n`;
}

describe("a lookup by a computed key", () => {
    it("references every function of a small table, as possible", () => {
        const found = edges({ "src/t.js": table(3) });
        expect(found).toEqual([
            "references possible ts:function:src/t.js#pick -> ts:method:src/t.js#T::m0",
            "references possible ts:function:src/t.js#pick -> ts:method:src/t.js#T::m1",
            "references possible ts:function:src/t.js#pick -> ts:method:src/t.js#T::m2",
        ]);
    });

    it("references every function of a large table too", () => {
        const found = edges({ "src/t.js": table(200) });
        expect(found).toHaveLength(200);
        expect(
            found.every((edge) =>
                edge.startsWith(
                    "references possible ts:function:src/t.js#pick -> ts:method:src/t.js#T::m",
                ),
            ),
        ).toBe(true);
    });
});

describe("a method call on a destructured parameter", () => {
    it("reaches the member of the object a call in the file hands it", () => {
        expect(
            edges({
                "src/a.js":
                    "function make() { return { go() {} }; }\nfunction run({ it: x }) { x.go(); }\nexport function main() { run({ it: make() }); }\n",
            }),
        ).toEqual([
            "calls probable ts:function:src/a.js#run -> ts:method:src/a.js#make.{object}@1:26::go",
        ]);
    });

    it("leaves a binding the call does not give alone", () => {
        expect(
            edges({
                "src/a.js":
                    "function make() { return { go() {} }; }\nfunction run({ it, ...rest }) { it.go(); rest.go(); }\nexport function main(v) { run(v); run({ other: make() }); }\n",
            }),
        ).toEqual([]);
    });
});
