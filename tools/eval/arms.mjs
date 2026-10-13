/**
 * Dev-only. The eval's arms: what each session may see and use, as Claude
 * Code flags, and the check that it saw nothing more.
 */
export const BASE_ARMS = ["baseline", "tools", "full"];

// No `find` (its -delete and -exec act) and no `sed -n` (its w command writes): neither is read-only.
const READ_ONLY_BASH = [
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
].map((c) => `Bash(${c} *)`);
// Residual writers inside otherwise read-only commands. `--output` is matched by its prefix because
// git accepts unambiguous abbreviations; `rg --pre <cmd>` runs a program on every file. Deny rules win over allow rules.
export const DENIED_BASH = [
    "git log *--ou*",
    "git show *--ou*",
    "rg *--pre*",
].map((c) => `Bash(${c})`);
const KNOSSOS_CLI = /(^|[\s;&|(/])knossos(\s|$)/;

export function parseArm(arm) {
    if (BASE_ARMS.includes(arm)) return { base: arm, minus: null };
    const match = /^full-minus-([a-z_]+)$/.exec(arm);
    if (match) return { base: "full", minus: match[1] };
    throw new Error(`unknown arm ${arm}`);
}

export const ablationArm = (tool) => `full-minus-${tool}`;

/** Flags for one session; the prompt goes on stdin, so no variadic flag can swallow it. */
export function armArgv(arm, { model, budgetUsd, mcpConfigPath, pluginDir }) {
    const { base, minus } = parseArm(arm);
    const mcp = base !== "baseline";
    return [
        "-p",
        "--output-format",
        "stream-json",
        "--verbose",
        "--model",
        model,
        "--no-session-persistence",
        "--max-budget-usd",
        String(budgetUsd),
        "--permission-mode",
        "dontAsk",
        "--tools",
        "Read,Bash,Skill",
        "--strict-mcp-config",
        ...(mcp ? ["--mcp-config", mcpConfigPath] : []),
        ...(base === "full" ? ["--plugin-dir", pluginDir] : []),
        "--disallowedTools",
        ...(minus ? [`mcp__knossos__${minus}`] : []),
        ...DENIED_BASH,
        "--allowedTools",
        "Read",
        "Skill",
        ...READ_ONLY_BASH,
        ...(mcp ? ["mcp__knossos__*"] : []),
    ];
}

const same = (a, b) => [...a].sort().join(",") === [...b].sort().join(",");

export function contamination(record, arm) {
    if (record.init === null) return ["no init event"];
    const { base, minus } = parseArm(arm);
    const reasons = [];
    const servers = record.init.mcpServers.map((s) => s.name);
    const wantServers = base === "baseline" ? [] : ["knossos"];
    if (!same(servers, wantServers))
        reasons.push(
            `mcp servers ${JSON.stringify(servers)}, expected ${JSON.stringify(wantServers)}`,
        );
    for (const s of record.init.mcpServers) {
        // Only the server's own report counts: a missing status is not a connection.
        if (s.name === "knossos" && s.status !== "connected")
            reasons.push(`knossos server ${s.status ?? "has no status"}`);
    }
    const wantPlugins = base === "full" ? ["knossos"] : [];
    if (!same(record.init.plugins, wantPlugins)) {
        reasons.push(
            `plugins ${JSON.stringify(record.init.plugins)}, expected ${JSON.stringify(wantPlugins)}`,
        );
    }
    if (minus !== null) {
        const ablated = `mcp__knossos__${minus}`;
        if (record.init.tools.includes(ablated))
            reasons.push(`ablated tool ${ablated} was offered`);
        if (record.toolCalls.some((c) => c.name === ablated))
            reasons.push(`ablated tool ${ablated} was called`);
    }
    if (base === "baseline") {
        for (const c of record.toolCalls) {
            if (
                c.name.startsWith("mcp__knossos__") ||
                (c.name === "Bash" &&
                    KNOSSOS_CLI.test(String(c.input.command ?? "")))
            ) {
                reasons.push(`baseline reached knossos through ${c.name}`);
            }
        }
    }
    return reasons;
}
