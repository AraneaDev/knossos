import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { MAX_INFERRED_TARGETS } from "../table-dispatch.js";

// A method picked out of a table by a computed key, and a method called on a
// destructured parameter that only the call site types.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scan(files) {
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
    return contributions;
}

function edges(files) {
    return scan(files)
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

// `count` factories, each returning an object with its own `go`, and a file
// that hands `run` one of each.
function callers(count) {
    const factories = Array.from(
        { length: count },
        (_, i) => `export function make${i}() { return { go() {} }; }\n`,
    ).join("");
    const calls = Array.from(
        { length: count },
        (_, i) => `    run({ it: make${i}() });\n`,
    ).join("");
    return {
        "src/make.js": factories,
        "src/run.js": "export function run({ it }) { it.go(); }\n",
        "src/main.js": `import { run } from './run.js';\nimport { ${Array.from({ length: count }, (_, i) => `make${i}`).join(", ")} } from './make.js';\nexport function main() {\n${calls}}\n`,
    };
}

describe("a method call on a destructured parameter, from another file", () => {
    it("reaches the member of the object a call in another file hands it", () => {
        expect(edges(callers(1))).toEqual([
            "calls probable ts:function:src/run.js#run -> ts:method:src/make.js#make0.{object}@1:34::go",
        ]);
    });

    it("reads the file whose call says what the binding holds", () => {
        const run = scan(callers(1)).find(
            (c) => c.owner_key === "knossos.typescript:file:src/run.js",
        );
        expect(Object.keys(run.reads)).toEqual(
            expect.arrayContaining(["src/main.js", "src/make.js"]),
        );
    });

    it(`reaches up to ${MAX_INFERRED_TARGETS} distinct members of one call`, () => {
        const found = edges(callers(MAX_INFERRED_TARGETS));
        expect(found).toHaveLength(MAX_INFERRED_TARGETS);
        expect(
            found.every((edge) =>
                edge.startsWith(
                    "calls probable ts:function:src/run.js#run -> ts:method:src/make.js#make",
                ),
            ),
        ).toBe(true);
    });

    it("infers nothing past the cap, and names the call unresolved", () => {
        const files = callers(MAX_INFERRED_TARGETS + 1);
        expect(edges(files)).toEqual([]);
        const module = scan(files)
            .find((c) => c.owner_key === "knossos.typescript:file:src/run.js")
            .nodes.find((node) => node.kind === "module");
        expect(module.attributes.unresolved_member_calls).toEqual(["go"]);
    });
});
