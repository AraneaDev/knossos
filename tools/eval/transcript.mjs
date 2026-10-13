/**
 * Dev-only. Reads a Claude Code stream-json transcript into what the eval
 * scores: what the session could see, which tools it called, what it cost,
 * and how close the five-hour window is to its limit.
 */
const PREFIX = "mcp__knossos__";
const SEARCH = /(^|[\s;&|(])(rg|grep|egrep|git\s+grep)\s/;

export const isKnossosTool = (name) => name.startsWith(PREFIX);

function readInit(event) {
    return {
        tools: event.tools ?? [],
        mcpServers: (event.mcp_servers ?? []).map((s) =>
            typeof s === "string"
                ? { name: s, status: null }
                : { name: s.name, status: s.status ?? null },
        ),
        plugins: (event.plugins ?? [])
            .filter((p) => typeof p === "string" || p?.path !== "builtin")
            .map((p) => (typeof p === "string" ? p : p?.name))
            .filter((name) => typeof name === "string"),
        model: event.model ?? null,
    };
}

function readResult(event) {
    const u = event.usage ?? {};
    return {
        isError: event.is_error === true,
        subtype: event.subtype ?? null,
        terminalReason: event.terminal_reason ?? null,
        apiErrorStatus: event.api_error_status ?? null,
        costUsd: event.total_cost_usd ?? 0,
        turns: event.num_turns ?? 0,
        durationMs: event.duration_ms ?? null,
        inputTokens: u.input_tokens ?? 0,
        outputTokens: u.output_tokens ?? 0,
        cacheReadTokens: u.cache_read_input_tokens ?? 0,
        cacheCreationTokens: u.cache_creation_input_tokens ?? 0,
        text: typeof event.result === "string" ? event.result : null,
    };
}

export function parseTranscript(lines) {
    const record = {
        init: null,
        toolCalls: [],
        texts: [],
        result: null,
        rateLimit: null,
        unparsed: 0,
    };
    const calls = new Map();
    for (const raw of lines) {
        if (raw.trim() === "") continue;
        let event;
        try {
            event = JSON.parse(raw);
        } catch {
            record.unparsed += 1;
            continue;
        }
        const content = Array.isArray(event.message?.content)
            ? event.message.content
            : [];
        if (event.type === "system" && event.subtype === "init")
            record.init = readInit(event);
        else if (event.type === "assistant") {
            for (const block of content) {
                if (block.type === "tool_use") {
                    const call = {
                        id: block.id,
                        name: block.name,
                        input: block.input ?? {},
                        isError: false,
                    };
                    record.toolCalls.push(call);
                    calls.set(block.id, call);
                } else if (block.type === "text") record.texts.push(block.text);
            }
        } else if (event.type === "user") {
            for (const block of content) {
                if (block.type === "tool_result" && block.is_error === true) {
                    const call = calls.get(block.tool_use_id);
                    if (call) call.isError = true;
                }
            }
        } else if (event.type === "rate_limit_event") {
            const info = event.rate_limit_info ?? {};
            record.rateLimit = {
                status: info.status ?? null,
                fiveHourUtilization:
                    info.unifiedWindows?.five_hour?.utilization ?? null,
            };
        } else if (event.type === "result") record.result = readResult(event);
    }
    return record;
}

/** The session's final answer: the result text only, else the last assistant text, never an earlier one. */
export const finalText = (record) =>
    record.result?.text || (record.texts.at(-1) ?? "");

export function knossosCounts(record) {
    const calls = {};
    const errors = {};
    for (const c of record.toolCalls.filter((c) => isKnossosTool(c.name))) {
        const name = c.name.slice(PREFIX.length);
        calls[name] = (calls[name] ?? 0) + 1;
        if (c.isError) errors[name] = (errors[name] ?? 0) + 1;
    }
    return { calls, errors };
}

/** Text searches for the task's subject after Knossos already answered: a sign the answer was not trusted or not usable. */
export function grepAfterGraph(record, subject) {
    const tokens = subject.split(/[^A-Za-z0-9_]+/).filter((t) => t.length >= 3);
    const first = record.toolCalls.findIndex((c) => isKnossosTool(c.name));
    if (first === -1) return 0;
    return record.toolCalls.slice(first + 1).filter((c) => {
        let text = null;
        if (c.name === "Grep") text = String(c.input.pattern ?? "");
        else if (
            c.name === "Bash" &&
            SEARCH.test(String(c.input.command ?? ""))
        )
            text = String(c.input.command);
        return text !== null && tokens.some((t) => text.includes(t));
    }).length;
}
