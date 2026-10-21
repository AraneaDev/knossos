import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// A NestJS route is recognised whatever form its decorators are imported in,
// and the application's global prefix is reported for the core to put in
// front of every route.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scan(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-nest-routes-")),
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
    return contributions.flatMap((c) => c.nodes);
}

describe("NestJS routes", () => {
    it("are read from decorators of a namespace import", () => {
        const nodes = scan({
            "src/users.controller.ts": [
                'import * as nest from "@nestjs/common";',
                '@nest.Controller("users")',
                "export class UsersController {",
                '    @nest.Get(":id") show(): void {}',
                "    @nest.Post() create(): void {}",
                "}",
                "",
            ].join("\n"),
        });
        const routes = nodes
            .filter((n) => n.kind === "route")
            .map((n) => n.canonical_name)
            .sort();
        expect(routes).toEqual([
            "GET /users/:id => src/users.controller.ts#UsersController::show",
            "POST /users => src/users.controller.ts#UsersController::create",
        ]);
        const roles = nodes.find(
            (n) =>
                n.canonical_name === "src/users.controller.ts#UsersController",
        ).attributes.nestjs_roles;
        expect(roles).toContain("nestjs.controller");
    });

    it("report the global prefix the application sets", () => {
        const nodes = scan({
            "src/main.ts": [
                'import { NestFactory } from "@nestjs/core";',
                "declare const AppModule: object;",
                "async function bootstrap(): Promise<void> {",
                "    const app = await NestFactory.create(AppModule);",
                '    app.setGlobalPrefix("api", { exclude: ["health", { path: "metrics", method: 0 }] });',
                "}",
                "",
            ].join("\n"),
        });
        const setting = nodes.find((n) => n.kind === "setting");
        expect(setting?.canonical_name).toBe("src/main.ts#setGlobalPrefix");
        expect(setting?.attributes).toMatchObject({
            framework: "nestjs",
            global_prefix: "api",
            exclude: ["health", "metrics"],
        });
    });

    it("leave a prefix that is not a literal unread", () => {
        const nodes = scan({
            "src/main.ts": [
                'import { NestFactory } from "@nestjs/core";',
                "declare const AppModule: object;",
                "declare const prefix: string;",
                "async function bootstrap(): Promise<void> {",
                "    const app = await NestFactory.create(AppModule);",
                "    app.setGlobalPrefix(prefix);",
                "}",
                "",
            ].join("\n"),
        });
        const setting = nodes.find((n) => n.kind === "setting");
        expect(setting?.attributes).toMatchObject({
            framework: "nestjs",
            global_prefix: null,
        });
    });
});
