import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

const created = [];

function fixture(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-inject-")),
    );
    created.push(root);
    for (const [relative, contents] of Object.entries(files)) {
        const absolute = join(root, relative);
        fs.mkdirSync(dirname(absolute), { recursive: true });
        fs.writeFileSync(absolute, contents);
    }
    return root;
}

afterEach(() => {
    while (created.length > 0)
        fs.rmSync(created.pop(), { recursive: true, force: true });
});

const TSCONFIG =
    '{"compilerOptions":{"module":"esnext","moduleResolution":"bundler","strict":true},"include":["src"]}';
const SERVICES = [
    "export class Api {}",
    "export class Store {}",
    "export class Logger {}",
    "export class Unused {}",
    'export const API_URL = "x";',
    "",
].join("\n");

/** The `injects` edges of one file, as `source -> target`, in source order. */
function injects(files, file) {
    const root = fixture({ "tsconfig.json": TSCONFIG, ...files });
    const contributions = [];
    new TypeScriptScanner().scan(
        { limits: SCAN_LIMITS, root, files: Object.keys(files) },
        (contribution) => contributions.push(contribution),
    );
    const contribution = contributions.find((item) =>
        item.owner_key.endsWith(`:${file}`),
    );
    return contribution.edges
        .filter((edge) => edge.kind === "injects")
        .map(
            (edge) =>
                `${edge.source.split("#").pop()} -> ${edge.target.replace(/^.*?:/, "")}`,
        );
}

describe("Angular inject()", () => {
    it("records a class asked for in a property initialiser or the constructor body", () => {
        const edges = injects(
            {
                "src/services.ts": SERVICES,
                "src/panel.ts": [
                    'import { inject } from "@angular/core";',
                    'import { Api, Store, Logger } from "./services";',
                    "export class Panel {",
                    "    private api = inject(Api);",
                    "    readonly store = inject(Store, { optional: true });",
                    "    private log;",
                    "    constructor() {",
                    "        this.log = inject(Logger);",
                    "    }",
                    "}",
                    "",
                ].join("\n"),
            },
            "src/panel.ts",
        );

        expect(edges).toEqual([
            "Panel -> class:src/services.ts#Api",
            "Panel -> class:src/services.ts#Store",
            "Panel -> class:src/services.ts#Logger",
        ]);
    });

    it("knows inject under another local name and beside constructor injection", () => {
        const edges = injects(
            {
                "src/services.ts": SERVICES,
                "src/panel.ts": [
                    'import { inject as ask } from "@angular/core";',
                    'import { Api, Store } from "./services";',
                    "export class Panel {",
                    "    private api = ask(Api);",
                    "    constructor(private store: Store) {}",
                    "}",
                    "",
                ].join("\n"),
            },
            "src/panel.ts",
        );

        expect(edges.sort()).toEqual([
            "Panel -> class:src/services.ts#Api",
            "Panel -> class:src/services.ts#Store",
        ]);
    });

    it("leaves out a constant token, another package's inject and a nested function's", () => {
        const edges = injects(
            {
                "src/services.ts": SERVICES,
                "src/own.ts":
                    "export function inject<T>(token: T): T { return token; }\n",
                "src/panel.ts": [
                    'import { inject } from "@angular/core";',
                    'import { inject as own } from "./own";',
                    'import { Api, Unused, API_URL } from "./services";',
                    "export class Panel {",
                    "    private url = inject(API_URL);",
                    "    private mine = own(Unused);",
                    "    private later = () => inject(Unused);",
                    "    load() { return inject(Unused); }",
                    "    private api = inject(Api);",
                    "}",
                    "",
                ].join("\n"),
            },
            "src/panel.ts",
        );

        expect(edges).toEqual(["Panel -> class:src/services.ts#Api"]);
    });
});
