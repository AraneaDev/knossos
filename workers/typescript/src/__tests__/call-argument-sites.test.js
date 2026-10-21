import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import ts from "typescript";

import { callArgumentPairs } from "../call-argument-sites.js";

// Which caller and callee files a call handing an object literal links.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function pairs(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-call-arguments-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const program = ts.createProgram(
        Object.keys(files).map((path) => join(root, path)),
        {
            allowJs: true,
            checkJs: false,
            module: ts.ModuleKind.CommonJS,
            noEmit: true,
        },
    );
    // The scanner binds the program (setting parent pointers) before it
    // asks for the pairs.
    program.getTypeChecker();
    return callArgumentPairs(
        program,
        root,
        { excludes: () => false },
        () => true,
    ).map((pair) => pair.replace("\0", " -> "));
}

describe("callArgumentPairs", () => {
    it("skips a called module whose only declaration is its file", () => {
        expect(
            pairs({
                "a.js": "module.exports = function ({ tape }) { tape.stop(); };\n",
                "lib.js": "exports.y = 1;\n",
                "b.js": [
                    "const lib = require('./lib.js');",
                    "const a = require('./a.js');",
                    "function go() { lib({ tape: 1 }); a({ tape: 2 }); }",
                    "",
                ].join("\n"),
            }),
        ).toEqual(["b.js -> a.js"]);
    });
});
