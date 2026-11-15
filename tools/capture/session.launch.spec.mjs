import { describe, expect, it } from "vitest";
import { THEMES } from "./frame.mjs";
import { launchArgv } from "./session.mjs";

const session = {
    name: "knossos-capture-1-abcd",
    socket: "knossos-capture-1-abcd",
    cols: 160,
    rows: 48,
};

describe("launchArgv", () => {
    it("sets the window's background before the session starts, so tmux answers the background question as a terminal does", () => {
        const line = launchArgv(session, {
            env: {},
            project: "/p",
            pluginDir: "/q",
            claudeArgs: [],
            background: THEMES.dark.background,
        }).join(" ");
        expect(line).toContain(
            `; set-option -gw window-style bg=${THEMES.dark.background} ; new-session`,
        );
        expect(line.endsWith("-c /p claude --plugin-dir /q")).toBe(true);
    });
    it("runs the command it is given instead of Claude Code", () => {
        const argv = launchArgv(session, {
            env: {},
            project: "/p",
            pluginDir: "/q",
            claudeArgs: ["--x"],
            command: ["/r/bin/knossos"],
        });
        expect(argv.slice(-3)).toEqual(["-c", "/p", "/r/bin/knossos"]);
        expect(argv).not.toContain("claude");
        expect(argv.join(" ")).not.toContain("window-style");
    });
});
