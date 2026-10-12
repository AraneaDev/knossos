import { describe, expect, it } from "vitest";
import {
    finalText,
    grepAfterGraph,
    knossosCounts,
    parseTranscript,
} from "./transcript.mjs";

const line = (e) => JSON.stringify(e);
const init = {
    type: "system",
    subtype: "init",
    tools: ["Read", "Bash", "Skill"],
    mcp_servers: [{ name: "knossos", status: "connected" }],
    plugins: [
        { name: "knossos", path: "/x/.plugin" },
        { name: "cc-plugin-telemetry", path: "builtin" },
    ],
    model: "claude-sonnet-5-5",
};
const toolUse = (id, name, input) => ({
    type: "assistant",
    message: { content: [{ type: "tool_use", id, name, input }] },
});
const toolResult = (id, isError) => ({
    type: "user",
    message: {
        content: [
            {
                type: "tool_result",
                tool_use_id: id,
                is_error: isError,
                content: "x",
            },
        ],
    },
});
const result = {
    type: "result",
    subtype: "success",
    is_error: false,
    num_turns: 3,
    duration_ms: 9564,
    total_cost_usd: 0.0063,
    terminal_reason: "completed",
    usage: {
        input_tokens: 6,
        output_tokens: 1246,
        cache_read_input_tokens: 73661,
        cache_creation_input_tokens: 24937,
    },
    result: '```answer\n["A::x"]\n```',
};
const rate = (utilization, status = "allowed") => ({
    type: "rate_limit_event",
    rate_limit_info: { status, unifiedWindows: { five_hour: { utilization } } },
});

const lines = [
    line({ type: "system", subtype: "hook_started" }),
    line(init),
    line(
        toolUse("t1", "mcp__knossos__list_usages", {
            symbol: "Invoice::total",
        }),
    ),
    line(toolResult("t1", false)),
    line(toolUse("t2", "mcp__knossos__impact_analysis", {})),
    line(toolResult("t2", true)),
    line(toolUse("t3", "Bash", { command: "rg -n 'total(' app" })),
    line(toolResult("t3", false)),
    line(rate(0.4)),
    line({
        type: "assistant",
        message: { content: [{ type: "text", text: "Looking." }] },
    }),
    "not json",
    line(result),
];

describe("parseTranscript", () => {
    const record = parseTranscript(lines);
    it("reads the init event without builtin plugins", () => {
        expect(record.init.plugins).toEqual(["knossos"]);
        expect(record.init.mcpServers).toEqual([
            { name: "knossos", status: "connected" },
        ]);
    });
    it("pairs tool calls with their results", () => {
        expect(record.toolCalls.map((c) => [c.name, c.isError])).toEqual([
            ["mcp__knossos__list_usages", false],
            ["mcp__knossos__impact_analysis", true],
            ["Bash", false],
        ]);
    });
    it("reads cost, turns, tokens and the rate limit", () => {
        expect(record.result).toMatchObject({
            costUsd: 0.0063,
            turns: 3,
            durationMs: 9564,
            outputTokens: 1246,
        });
        expect(record.rateLimit).toEqual({
            status: "allowed",
            fiveHourUtilization: 0.4,
        });
    });
    it("counts lines it could not parse instead of failing", () => {
        expect(record.unparsed).toBe(1);
    });
    it("tolerates a transcript cut off before the result", () => {
        const cut = parseTranscript(lines.slice(0, 3));
        expect(cut.result).toBeNull();
        expect(cut.toolCalls).toHaveLength(1);
    });
    it("keeps the last rate limit event", () => {
        expect(
            parseTranscript([line(rate(0.2)), line(rate(0.9, "rejected"))])
                .rateLimit,
        ).toEqual({
            status: "rejected",
            fiveHourUtilization: 0.9,
        });
    });
});

describe("finalText", () => {
    it("puts the result text last so its answer block wins", () => {
        expect(finalText(parseTranscript(lines)).endsWith(result.result)).toBe(
            true,
        );
    });
});

describe("knossosCounts", () => {
    it("counts calls and errors per tool without the prefix", () => {
        expect(knossosCounts(parseTranscript(lines))).toEqual({
            calls: { list_usages: 1, impact_analysis: 1 },
            errors: { impact_analysis: 1 },
        });
    });
});

describe("grepAfterGraph", () => {
    it("counts searches for the subject after the first knossos call", () => {
        expect(grepAfterGraph(parseTranscript(lines), "Invoice::total")).toBe(
            1,
        );
    });
    it("ignores searches before any knossos call and unrelated searches", () => {
        const early = [
            line(toolUse("a", "Bash", { command: "rg total" })),
            line(toolUse("b", "mcp__knossos__find_component", {})),
            line(toolUse("c", "Bash", { command: "grep -r unrelated ." })),
        ];
        expect(grepAfterGraph(parseTranscript(early), "Invoice::total")).toBe(
            0,
        );
    });
    it("counts the Grep tool too", () => {
        const g = [
            line(toolUse("b", "mcp__knossos__find_component", {})),
            line(toolUse("c", "Grep", { pattern: "Invoice" })),
        ];
        expect(grepAfterGraph(parseTranscript(g), "Invoice::total")).toBe(1);
    });
});
