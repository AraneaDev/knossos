/**
 * Dev-only. The eval's arms: what each session may see and use, as Claude
 * Code flags, and the check that it saw nothing more.
 */
export const BASE_ARMS = ["baseline", "tools", "full"];

const READ_ONLY_BASH = [
    "rg",
    "grep",
    "find",
    "ls",
    "cat",
    "head",
    "tail",
    "wc",
    "sed -n",
    "git log",
    "git grep",
    "git show",
].map((c) => `Bash(${c} *)`);
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
        ...(minus ? ["--disallowedTools", `mcp__knossos__${minus}`] : []),
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
    const { base } = parseArm(arm);
    const reasons = [];
    const servers = record.init.mcpServers.map((s) => s.name);
    const wantServers = base === "baseline" ? [] : ["knossos"];
    if (!same(servers, wantServers))
        reasons.push(
            `mcp servers ${JSON.stringify(servers)}, expected ${JSON.stringify(wantServers)}`,
        );
    for (const s of record.init.mcpServers) {
        if (
            s.name === "knossos" &&
            s.status !== null &&
            s.status !== "connected"
        )
            reasons.push(`knossos server ${s.status}`);
    }
    const wantPlugins = base === "full" ? ["knossos"] : [];
    if (!same(record.init.plugins, wantPlugins)) {
        reasons.push(
            `plugins ${JSON.stringify(record.init.plugins)}, expected ${JSON.stringify(wantPlugins)}`,
        );
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
