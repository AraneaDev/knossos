import { describe, expect, it } from "vitest";
import { ablationArm, armArgv, contamination, parseArm } from "./arms.mjs";

const opts = {
    model: "sonnet",
    budgetUsd: 1,
    mcpConfigPath: "/e/mcp.json",
    pluginDir: "/k/.plugin",
};
const after = (argv, flag) => argv[argv.indexOf(flag) + 1];
const record = (servers, plugins, toolCalls = []) => ({
    init: { tools: [], mcpServers: servers, plugins, model: "m" },
    toolCalls,
});
const knossos = [{ name: "knossos", status: "connected" }];

describe("parseArm", () => {
    it("reads base and ablation arms", () => {
        expect(parseArm("tools")).toEqual({ base: "tools", minus: null });
        expect(parseArm(ablationArm("list_usages"))).toEqual({
            base: "full",
            minus: "list_usages",
        });
        expect(() => parseArm("other")).toThrow(/unknown arm/);
    });
});

describe("armArgv", () => {
    it("gives the baseline no MCP server and no plugin", () => {
        const argv = armArgv("baseline", opts);
        expect(argv).toContain("--strict-mcp-config");
        expect(argv).not.toContain("--mcp-config");
        expect(argv).not.toContain("--plugin-dir");
        expect(argv.join(" ")).not.toMatch(/mcp__knossos/);
    });
    it("gives tools the server only, and full the plugin too", () => {
        expect(after(armArgv("tools", opts), "--mcp-config")).toBe(
            "/e/mcp.json",
        );
        expect(armArgv("tools", opts)).not.toContain("--plugin-dir");
        expect(after(armArgv("full", opts), "--plugin-dir")).toBe("/k/.plugin");
    });
    it("denies one tool in an ablation arm", () => {
        expect(
            after(
                armArgv(ablationArm("list_usages"), opts),
                "--disallowedTools",
            ),
        ).toBe("mcp__knossos__list_usages");
    });
    it("runs headless, without prompts and with a fixed read-only tool set in every arm", () => {
        const allowed = (argv) =>
            argv.slice(argv.indexOf("--allowedTools") + 1);
        const readOnly = [
            "Read",
            "Skill",
            ...[
                "rg",
                "grep",
                "ls",
                "cat",
                "head",
                "tail",
                "wc",
                "git log",
                "git grep",
                "git show",
            ].map((c) => `Bash(${c} *)`),
        ];
        const expected = {
            baseline: readOnly,
            tools: [...readOnly, "mcp__knossos__*"],
            full: [...readOnly, "mcp__knossos__*"],
        };
        for (const arm of ["baseline", "tools", "full"]) {
            const argv = armArgv(arm, opts);
            expect(argv[0]).toBe("-p");
            expect(after(argv, "--permission-mode")).toBe("dontAsk");
            expect(after(argv, "--output-format")).toBe("stream-json");
            expect(after(argv, "--tools")).toBe("Read,Bash,Skill");
            expect(allowed(argv)).toEqual(expected[arm]);
            expect(
                allowed(argv).filter((e) => /Edit|Write|find|sed/.test(e)),
            ).toEqual([]);
        }
    });
});

describe("contamination", () => {
    it("passes a clean session in each arm", () => {
        expect(contamination(record([], []), "baseline")).toEqual([]);
        expect(contamination(record(knossos, []), "tools")).toEqual([]);
        expect(contamination(record(knossos, ["knossos"]), "full")).toEqual([]);
    });
    it("flags a user plugin or MCP server that leaked in", () => {
        expect(
            contamination(record([], ["superpowers"]), "baseline"),
        ).toHaveLength(1);
        expect(
            contamination(
                record(
                    [...knossos, { name: "context7", status: "connected" }],
                    ["knossos"],
                ),
                "full",
            ),
        ).toHaveLength(1);
    });
    it("flags a knossos server that did not connect", () => {
        expect(
            contamination(
                record([{ name: "knossos", status: "failed" }], []),
                "tools",
            )[0],
        ).toMatch(/failed/);
    });
    it("flags the baseline running the knossos CLI, but not a path that contains the word", () => {
        const viaCli = [
            {
                name: "Bash",
                input: { command: "php bin/knossos list-usages . X" },
            },
        ];
        const viaPath = [
            {
                name: "Bash",
                input: { command: "rg total /root/.knossos-eval/repos/demo" },
            },
        ];
        expect(contamination(record([], [], viaCli), "baseline")).toHaveLength(
            1,
        );
        expect(contamination(record([], [], viaPath), "baseline")).toEqual([]);
    });
    it("treats a missing init event as contaminated", () => {
        expect(contamination({ init: null, toolCalls: [] }, "tools")).toEqual([
            "no init event",
        ]);
    });
});

describe("contamination of an ablation arm", () => {
    const arm = ablationArm("list_usages");
    const plugin = ["knossos"];
    it("passes when the ablated tool was neither offered nor called", () => {
        const r = record(knossos, plugin, [
            { name: "mcp__knossos__impact_analysis", input: {} },
        ]);
        r.init.tools = ["Read", "mcp__knossos__impact_analysis"];
        expect(contamination(r, arm)).toEqual([]);
    });
    it("flags the ablated tool when the session was offered it", () => {
        const r = record(knossos, plugin);
        r.init.tools = ["Read", "mcp__knossos__list_usages"];
        expect(contamination(r, arm)).toEqual([
            "ablated tool mcp__knossos__list_usages was offered",
        ]);
    });
    it("flags the ablated tool when the session called it", () => {
        const r = record(knossos, plugin, [
            { name: "mcp__knossos__list_usages", input: {} },
        ]);
        expect(contamination(r, arm)).toEqual([
            "ablated tool mcp__knossos__list_usages was called",
        ]);
    });
});
