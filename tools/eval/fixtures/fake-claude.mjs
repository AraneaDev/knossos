#!/usr/bin/env node
/**
 * Dev-only test double for `claude -p --output-format stream-json`: reads the
 * prompt from stdin and prints a plausible transcript shaped by its flags and
 * the FAKE_* variables. FAKE_LOG appends what it was started with.
 */
import { appendFileSync } from "node:fs";

const argv = process.argv.slice(2);
let prompt = "";
for await (const chunk of process.stdin) prompt += chunk;
if (process.env.FAKE_LOG) {
    appendFileSync(
        process.env.FAKE_LOG,
        `${JSON.stringify({ argv, cwd: process.cwd(), home: process.env.HOME, prompt })}\n`,
    );
}
if (process.env.FAKE_SLEEP_MS)
    await new Promise((resolve) =>
        setTimeout(resolve, Number(process.env.FAKE_SLEEP_MS)),
    );
const emit = (event) => process.stdout.write(`${JSON.stringify(event)}\n`);
const has = (flag) => argv.includes(flag);
emit({
    type: "system",
    subtype: "init",
    tools: ["Read", "Bash", "Skill"],
    mcp_servers: has("--mcp-config")
        ? [{ name: "knossos", status: "connected" }]
        : [],
    plugins: has("--plugin-dir")
        ? [{ name: "knossos", path: argv[argv.indexOf("--plugin-dir") + 1] }]
        : [],
    model: "fake",
});
if (has("--mcp-config")) {
    emit({
        type: "assistant",
        message: {
            content: [
                {
                    type: "tool_use",
                    id: "t1",
                    name: "mcp__knossos__list_usages",
                    input: {},
                },
            ],
        },
    });
    emit({
        type: "user",
        message: {
            content: [
                {
                    type: "tool_result",
                    tool_use_id: "t1",
                    is_error: false,
                    content: "ok",
                },
            ],
        },
    });
}
emit({
    type: "rate_limit_event",
    rate_limit_info: {
        status: process.env.FAKE_LIMIT_STATUS ?? "allowed",
        unifiedWindows: {
            five_hour: {
                utilization: Number(process.env.FAKE_UTILIZATION ?? "0.1"),
            },
        },
    },
});
const answer = process.env.FAKE_ANSWER ?? '["App\\\\Billing::summarise"]';
const text = `Done.\n\n\`\`\`answer\n${answer}\n\`\`\``;
emit({ type: "assistant", message: { content: [{ type: "text", text }] } });
emit({
    type: "result",
    subtype: process.env.FAKE_RESULT_SUBTYPE ?? "success",
    is_error: process.env.FAKE_RESULT_SUBTYPE !== undefined,
    num_turns: 2,
    duration_ms: 1234,
    total_cost_usd: 0.01,
    terminal_reason: "completed",
    usage: {
        input_tokens: 10,
        output_tokens: 20,
        cache_read_input_tokens: 0,
        cache_creation_input_tokens: 0,
    },
    result: text,
});
