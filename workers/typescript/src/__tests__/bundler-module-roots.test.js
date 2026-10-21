import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { afterEach, describe, expect, it } from "vitest";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// The module roots and extensions a webpack config's `resolve` declares,
// read without running the config.

const created = [];

afterEach(() => {
    while (created.length > 0)
        fs.rmSync(created.pop(), { recursive: true, force: true });
});

function scan(files, requested = Object.keys(files), configFiles) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-module-roots-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const contributions = [];
    new TypeScriptScanner().scan(
        {
            limits: SCAN_LIMITS,
            root,
            files: requested,
            ...(configFiles ? { config_files: configFiles } : {}),
        },
        (c) => contributions.push(c),
    );
    return { contributions };
}

const imports = (contributions) =>
    contributions
        .flatMap((c) => c.edges)
        .filter((e) => e.kind === "imports")
        .map((e) => e.target);

const unread = (contributions) =>
    contributions
        .flatMap((c) => c.diagnostics)
        .filter((d) => d.code === "BUNDLER_CONFIG_UNREAD");

const project = (config) => ({
    ...config,
    "src/main.ts":
        "import Search from 'checkout/components/Search';\nimport { ref } from 'vue';\nexport default [Search, ref];\n",
    "src/checkout/components/Search.vue":
        "<template><div/></template>\n<script>\nexport default { name: 'Search' };\n</script>\n",
    "node_modules/vue/package.json": '{"name":"vue","types":"index.d.ts"}\n',
    "node_modules/vue/index.d.ts": "export declare function ref(): void;\n",
});
const mainCodes = (contributions) =>
    contributions
        .filter((c) => c.nodes.some((n) => n.id === "ts:module:src/main.ts"))
        .flatMap((c) => c.diagnostics.map((d) => d.code));

describe("a bundler's module roots", () => {
    it.each([
        ["path.resolve(__dirname, 'src')", "const path = require('path');\n"],
        ["'src'", ""],
    ])(
        "resolve imports under a root webpack lists as %s, packages still from node_modules",
        (root, preamble) => {
            const { contributions } = scan(
                project({
                    "webpack.config.js": `${preamble}module.exports = { resolve: { modules: [${root}, 'node_modules'], extensions: ['.js', '.vue'] } };\n`,
                }),
                ["src/main.ts", "src/checkout/components/Search.vue"],
            );

            expect(imports(contributions)).toContain(
                "ts:module:src/checkout/components/Search.vue",
            );
            expect(mainCodes(contributions)).not.toContain("TS2307");
        },
    );

    it("resolve imports under a root Vue CLI's chainWebpack adds", () => {
        const { contributions } = scan(
            project({
                "vue.config.js":
                    "const path = require('path');\nmodule.exports = { chainWebpack: (config) => { config.resolve.modules.add(path.resolve(__dirname, 'src')).add('node_modules'); config.resolve.extensions.add('.vue'); } };\n",
            }),
            ["src/main.ts", "src/checkout/components/Search.vue"],
        );

        expect(imports(contributions)).toContain(
            "ts:module:src/checkout/components/Search.vue",
        );
    });

    it.each([
        [
            "node_modules listed first",
            "module.exports = { resolve: { modules: ['node_modules', 'src'] } };\n",
        ],
        [
            "appended by chainWebpack",
            "module.exports = { chainWebpack: (config) => { config.resolve.modules.add('src'); } };\n",
        ],
    ])(
        "leave a package before a root %s, and find the rest under the root",
        (_, config) => {
            const name = _.startsWith("node")
                ? "webpack.config.js"
                : "vue.config.js";
            const { contributions } = scan(
                {
                    [name]: config,
                    "src/main.ts":
                        "import settings from 'config';\nimport { search } from 'checkout/search';\nexport default [settings, search];\n",
                    "src/config/index.ts": "export default 1;\n",
                    "src/checkout/search.ts": "export const search = 1;\n",
                    "node_modules/config/package.json":
                        '{"name":"config","types":"index.d.ts"}\n',
                    "node_modules/config/index.d.ts":
                        "declare const settings: number;\nexport default settings;\n",
                },
                [
                    "src/main.ts",
                    "src/config/index.ts",
                    "src/checkout/search.ts",
                ],
            );

            const targets = imports(contributions);
            expect(targets).toContain("ts:package:config");
            expect(targets).not.toContain("ts:module:src/config/index.ts");
            expect(targets).toContain("ts:module:src/checkout/search.ts");
        },
    );

    it("leave a tsconfig's own catch-all mapping in place", () => {
        const { contributions } = scan(
            {
                ...project({
                    "webpack.config.js":
                        "module.exports = { resolve: { modules: ['src', 'node_modules'], extensions: ['.vue'] } };\n",
                }),
                "tsconfig.json":
                    '{"compilerOptions":{"paths":{"*":["./lib/*"]}},"include":["src","lib"]}\n',
                "lib/checkout/components/Search.ts":
                    "export default function Search() {}\n",
            },
            ["src/main.ts", "lib/checkout/components/Search.ts"],
            ["tsconfig.json"],
        );

        expect(imports(contributions)).toContain(
            "ts:module:lib/checkout/components/Search.ts",
        );
    });
});

describe("a bundler config's values", () => {
    it("read a root path.resolve names against the config's directory", () => {
        const { contributions } = scan(
            project({
                "webpack.config.js":
                    "const path = require('path');\nmodule.exports = { resolve: { modules: [path.resolve('src'), 'node_modules'], extensions: ['.vue'] } };\n",
            }),
            ["src/main.ts", "src/checkout/components/Search.vue"],
        );

        expect(imports(contributions)).toContain(
            "ts:module:src/checkout/components/Search.vue",
        );
        expect(unread(contributions)).toEqual([]);
    });

    it("say nothing of extensions it cannot read, nor of a rule's own resolve", () => {
        const { contributions } = scan(
            project({
                "webpack.config.js":
                    "const base = ['.js'];\nmodule.exports = { resolve: { modules: ['src', 'node_modules'], extensions: [...base, '.vue', ext()] }, module: { rules: [{ test: /x/, resolve: { modules: rootsOf() } }] } };\n",
            }),
            ["src/main.ts", "src/checkout/components/Search.vue"],
        );

        expect(imports(contributions)).toContain(
            "ts:module:src/checkout/components/Search.vue",
        );
        expect(unread(contributions)).toEqual([]);
    });

    it("say which key could not be read without running the config", () => {
        const { contributions } = scan(
            project({
                "webpack.config.js":
                    "const roots = ['src'];\nmodule.exports = { resolve: { modules: roots, extensions: ['.js', ext()] } };\n",
            }),
            ["src/main.ts", "src/checkout/components/Search.vue"],
        );

        const found = unread(contributions);
        expect(found.map((d) => d.severity)).toEqual(["warning"]);
        expect(found[0].message).toMatch(
            /webpack\.config\.js.*resolve\.modules/,
        );
        expect(contributions.some((c) => c.nodes.length > 0)).toBe(true);
    });
});
