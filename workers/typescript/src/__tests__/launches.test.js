import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// A process a file starts runs a file of the project: the code naming it
// launches that file, whichever language the file is in.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function launched(source) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-launches-")),
    );
    created.push(root);
    fs.mkdirSync(dirname(join(root, "src/runner.js")), { recursive: true });
    fs.writeFileSync(join(root, "src/runner.js"), source);
    const contributions = [];
    new TypeScriptScanner().scan({ root, files: ["src/runner.js"] }, (c) =>
        contributions.push(c),
    );
    return contributions
        .flatMap((c) => c.edges)
        .filter((e) => e.kind === "launches");
}

describe("launches", () => {
    it("are read from the argv a process is started with", () => {
        const edges = launched(
            [
                'import { spawn, execFile, fork } from "node:child_process";',
                'import path from "node:path";',
                "export function start(root) {",
                '    spawn(process.execPath, ["--expose-gc", path.join(root, "workers/run.js")]);',
                '    execFile("python3", ["-I", "tools/run.py"]);',
                '    fork(path.join(__dirname, "child.js"));',
                '    const argv = [`${root}/bin/tool`, "--quiet"];',
                '    spawn("node", ["--version"]);',
                '    const labels = ["--flag", "not/an/argv.txt"];',
                "}",
                "",
            ].join("\n"),
        );
        expect(
            edges.map((e) => [e.source, e.target, e.confidence]).sort(),
        ).toEqual([
            [
                "ts:function:src/runner.js#start",
                "ts:launched_file:bin/tool",
                "probable",
            ],
            [
                "ts:function:src/runner.js#start",
                "ts:launched_file:child.js",
                "probable",
            ],
            [
                "ts:function:src/runner.js#start",
                "ts:launched_file:tools/run.py",
                "probable",
            ],
            [
                "ts:function:src/runner.js#start",
                "ts:launched_file:workers/run.js",
                "probable",
            ],
        ]);
        expect(edges.every((e) => e.attributes.speculative === true)).toBe(
            true,
        );
    });
});
