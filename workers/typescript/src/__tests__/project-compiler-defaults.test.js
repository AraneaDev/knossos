import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// TypeScript 6 changed defaults a project built with 5.x never opted into:
// `types` went from every `@types` package to none, `strict` went from false to
// true. The worker bundles 6.x, so a 5.x project whose tsconfig left those
// unset was checked under rules its own compiler never applies, and every
// `process`, `Buffer` and implicit `any` came back as an error that `tsc`
// does not report. The project's own TypeScript decides which defaults apply:
// the core reads each manifest's declared range and sends the majors, keyed by
// the manifest's directory.

const created = [];

function fixture(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-defaults-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        const absolute = join(root, path);
        fs.mkdirSync(dirname(absolute), { recursive: true });
        fs.writeFileSync(absolute, contents);
    }
    return root;
}

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

const SOURCE =
    "export function run(value) {\n    return fooGlobal + value;\n}\n";

function tree(extra) {
    return fixture({
        "tsconfig.json":
            '{"compilerOptions":{"noEmit":true},"include":["src"]}',
        "src/a.ts": SOURCE,
        "node_modules/@types/foo/package.json":
            '{"name":"@types/foo","types":"index.d.ts"}',
        "node_modules/@types/foo/index.d.ts":
            "declare const fooGlobal: number;\n",
        ...extra,
    });
}

function scan(root, versions, files = ["src/a.ts"]) {
    const contributions = [];
    const result = new TypeScriptScanner().scan(
        { limits: SCAN_LIMITS, root, files, typescript_versions: versions },
        (c) => contributions.push(c),
    );
    const codes = contributions
        .flatMap((c) => c.diagnostics)
        .map((d) => d.code)
        .sort();
    return { codes, hashes: result.input_hashes };
}

describe("compiler defaults follow the project's own TypeScript", () => {
    it("applies 5.x defaults to a project on 5.x", () => {
        const root = tree({});

        const { codes, hashes } = scan(root, { "": 5 });

        // `fooGlobal` comes from an automatically included `@types` package,
        // and `value` is an implicit any that non-strict 5.x accepts.
        expect(codes).toEqual([]);
        // Decided from the request alone: nothing new is read to decide it.
        expect(Object.keys(hashes)).not.toContain("package.json");
    });

    it("keeps 6.x defaults for a project on 6.x", () => {
        expect(scan(tree({}), { "": 6 }).codes).toEqual(["TS2304", "TS7006"]);
    });

    it("keeps the bundled defaults when no manifest names TypeScript", () => {
        expect(scan(tree({}), {}).codes).toEqual(["TS2304", "TS7006"]);
        expect(scan(tree({}), undefined).codes).toEqual(["TS2304", "TS7006"]);
    });

    it("lets an explicit tsconfig setting win over either default", () => {
        const root = tree({
            "tsconfig.json":
                '{"compilerOptions":{"noEmit":true,"strict":true,"types":[]},"include":["src"]}',
        });

        expect(scan(root, { "": 5 }).codes).toEqual(["TS2304", "TS7006"]);
    });

    it("loads implicit type libraries only from @types at or below the root", () => {
        // `tsc` walks every node_modules/@types from the config's directory
        // upward. One above the scan root cannot be read, and listing its
        // packages anyway reported each as a missing type definition file.
        const outer = fixture({
            "node_modules/@types/gensync/index.d.ts":
                "declare const gensyncGlobal: number;\n",
        });
        const root = join(outer, "project");
        fs.mkdirSync(join(root, "frontend/src"), { recursive: true });
        fs.writeFileSync(
            join(root, "frontend/tsconfig.node.json"),
            '{"compilerOptions":{"composite":true,"skipLibCheck":true,"module":"ESNext","moduleResolution":"bundler"},"include":["vite.config.ts"]}',
        );
        fs.writeFileSync(
            join(root, "frontend/vite.config.ts"),
            "export const port: number = fooGlobal;\n",
        );
        // The root's own @types still counts, from the config's directory up.
        fs.mkdirSync(join(root, "node_modules/@types/foo"), {
            recursive: true,
        });
        fs.writeFileSync(
            join(root, "node_modules/@types/foo/index.d.ts"),
            "declare const fooGlobal: number;\n",
        );

        const contributions = [];
        new TypeScriptScanner().scan(
            {
                limits: SCAN_LIMITS,
                root,
                files: ["frontend/vite.config.ts"],
                config_files: ["frontend/tsconfig.node.json"],
                typescript_versions: { frontend: 5 },
            },
            (c) => contributions.push(c),
        );

        expect(contributions.flatMap((c) => c.diagnostics)).toEqual([]);
    });

    it("still resolves a types entry naming a package's subpath", () => {
        const root = fixture({
            "frontend/tsconfig.json":
                '{"compilerOptions":{"types":["vitest/globals"],"module":"ESNext","moduleResolution":"bundler"},"include":["src"]}',
            "frontend/src/a.test.ts": "describe('runs', () => {});\n",
            "frontend/node_modules/vitest/package.json":
                '{"name":"vitest","exports":{"./globals":{"types":"./globals.d.ts"}}}',
            "frontend/node_modules/vitest/globals.d.ts":
                "declare global {\n    function describe(name: string, body: () => void): void;\n}\nexport {};\n",
        });

        const contributions = [];
        new TypeScriptScanner().scan(
            {
                limits: SCAN_LIMITS,
                root,
                files: ["frontend/src/a.test.ts"],
                config_files: ["frontend/tsconfig.json"],
            },
            (c) => contributions.push(c),
        );

        expect(contributions.flatMap((c) => c.diagnostics)).toEqual([]);
    });

    it("takes the nearest manifest at or above the config's directory", () => {
        const root = tree({
            "packages/app/tsconfig.json":
                '{"compilerOptions":{"noEmit":true,"typeRoots":["../../node_modules/@types"]},"include":["src"]}',
            "packages/app/src/b.ts": SOURCE,
        });

        expect(
            scan(root, { "": 6, "packages/app": 5 }, ["packages/app/src/b.ts"])
                .codes,
        ).toEqual([]);
        expect(
            scan(root, { "": 5, packages: 6 }, ["packages/app/src/b.ts"]).codes,
        ).toEqual(["TS2304", "TS7006"]);
    });
});
