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

    it("gives up on an argument a spread comes before", () => {
        expect(
            edges({
                "src/a.js":
                    "function make() { return { go() {} }; }\nfunction run({ it: x }) { x.go(); }\nexport function main(xs) { run(...xs, { it: make() }); }\n",
                "src/b.js":
                    "import { make } from './c.js';\nexport function walk({ it }) { it.go(); }\n",
                "src/c.js": "export function make() { return { go() {} }; }\n",
                "src/d.js":
                    "import { walk } from './b.js';\nimport { make } from './c.js';\nexport function main(xs) { walk(...xs, { it: make() }); }\n",
            }),
        ).toEqual([]);
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

    it.each([8, 9, 40])(
        "reaches every distinct member %i call sites give one call",
        (count) => {
            const found = edges(callers(count));
            expect(found).toHaveLength(count);
            expect(
                found.every((edge) =>
                    edge.startsWith(
                        "calls probable ts:function:src/run.js#run -> ts:method:src/make.js#make",
                    ),
                ),
            ).toBe(true);
        },
    );

    it("follows an import renamed, a default import and a re-export", () => {
        const make = "export function make() { return { go() {} }; }\n";
        expect(
            edges({
                "src/make.js": make,
                "src/run.js":
                    "export function run({ it }) { it.go(); }\nexport default function walk({ it }) { it.go(); }\n",
                "src/index.js": "export { run as start } from './run.js';\n",
                "src/main.js":
                    "import { run as go } from './run.js';\nimport stroll from './run.js';\nimport { start } from './index.js';\nimport { make } from './make.js';\nexport function main() { go({ it: make() }); stroll({ it: make() }); }\nexport function other() { start({ it: make() }); }\n",
            }),
        ).toEqual([
            "calls probable ts:function:src/run.js#run -> ts:method:src/make.js#make.{object}@1:33::go",
            "calls probable ts:function:src/run.js#walk -> ts:method:src/make.js#make.{object}@1:33::go",
        ]);
    });

    it("leaves a typed callee to the checker", () => {
        const run = scan({
            "src/make.js": "export function make() { return { go() {} }; }\n",
            "src/run.js":
                "/** @param {{ it: { go(): void } }} context */\nexport function run({ it }) { it.go(); }\n",
            "src/main.js":
                "import { run } from './run.js';\nimport { make } from './make.js';\nrun({ it: make() });\n",
        }).find((c) => c.owner_key === "knossos.typescript:file:src/run.js");
        expect(run.edges.filter((e) => e.attributes.inferred_from)).toEqual([]);
        expect(Object.keys(run.reads)).not.toContain("src/main.js");
    });
});

describe("a method call on a destructured parameter, from a CommonJS file", () => {
    it.each([
        [
            "module.exports = run",
            "function run({ it }) { it.go(); }\nmodule.exports = run;\n",
            "const go = require('./run.js');",
            "ts:function:src/run.js#run",
        ],
        [
            "module.exports = function",
            "module.exports = function ({ it }) { it.go(); };\n",
            "const go = require('./run.js');",
            "ts:module:src/run.js",
        ],
        [
            "exports.go = function",
            "exports.go = function ({ it }) { it.go(); };\n",
            "const { go } = require('./run.js');",
            "ts:module:src/run.js",
        ],
        [
            "module.exports.go = run",
            "function run({ it }) { it.go(); }\nmodule.exports.go = run;\n",
            "const { go } = require('./run.js');",
            "ts:function:src/run.js#run",
        ],
    ])("follows a CommonJS export, %s", (_, run, binding, source) => {
        expect(
            edges({
                "src/make.js":
                    "function make() { return { go() {} }; }\nmodule.exports = { make };\n",
                "src/run.js": run,
                "src/main.js": `${binding}\nconst { make } = require('./make.js');\ngo({ it: make() });\n`,
            }),
        ).toEqual([
            `calls probable ${source} -> ts:method:src/make.js#make.{object}@1:26::go`,
        ]);
    });

    it("pairs a CommonJS caller with its callee in the program's environment", () => {
        const environment = (call) =>
            scan({
                "src/make.js":
                    "function make() { return { go() {} }; }\nmodule.exports = { make };\n",
                "src/run.js":
                    "module.exports = function ({ it }) { it.go(); };\n",
                "src/main.js": `const go = require('./run.js');\nconst { make } = require('./make.js');\n${call}\n`,
            }).find((c) => c.owner_key === "knossos.typescript:file:src/run.js")
                .environment;
        expect(environment("go({ it: make() });")).not.toBe(
            environment("go(make);"),
        );
    });
});
