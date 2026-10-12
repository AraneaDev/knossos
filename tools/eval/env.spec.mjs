import { execFile } from "node:child_process";
import { mkdtemp, readFile, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { promisify } from "node:util";
import { describe, expect, it } from "vitest";
import {
    ensureClone,
    evalEnv,
    evalPaths,
    resetWorkspace,
    seedConfig,
    writeMcpConfig,
} from "./env.mjs";

const git = promisify(execFile);
const tmp = (p) => mkdtemp(path.join(os.tmpdir(), p));

async function originRepo() {
    const dir = await tmp("eval-origin-");
    await git("git", ["init", "-q", dir]);
    await writeFile(path.join(dir, "a.txt"), "one\n");
    await git("git", [
        "-C",
        dir,
        "-c",
        "user.name=t",
        "-c",
        "user.email=t@t",
        "add",
        ".",
    ]);
    await git("git", [
        "-C",
        dir,
        "-c",
        "user.name=t",
        "-c",
        "user.email=t@t",
        "commit",
        "-qm",
        "one",
    ]);
    const { stdout } = await git("git", ["-C", dir, "rev-parse", "HEAD"]);
    return { dir, commit: stdout.trim() };
}

describe("evalEnv", () => {
    it("isolates config, home and data, and drops inherited CLAUDE variables", async () => {
        const paths = evalPaths(await tmp("eval-root-"));
        const env = evalEnv(paths, {
            base: {
                PATH: "/bin",
                CLAUDECODE: "1",
                CLAUDE_CODE_ENTRYPOINT: "cli",
            },
        });
        expect(env.CLAUDE_CONFIG_DIR).toBe(paths.config);
        expect(env.HOME).toBe(paths.home);
        expect(env.KNOSSOS_DATA_DIR).toBe(paths.data);
        expect(env.CLAUDECODE).toBeUndefined();
        expect(env.CLAUDE_CODE_ENTRYPOINT).toBeUndefined();
        expect(env.PATH).toBe("/bin");
    });
    it("refuses a root inside the real ~/.knossos", () => {
        const paths = evalPaths(path.join(os.homedir(), ".knossos", "eval"));
        expect(() => evalEnv(paths)).toThrow(/refusing/);
    });
});

describe("seedConfig and writeMcpConfig", () => {
    it("trusts the workspaces, lists them as roots and points the server at the eval data", async () => {
        const paths = evalPaths(await tmp("eval-root-"));
        await seedConfig(paths, ["/w/demo"], {
            realHome: await tmp("eval-nohome-"),
        });
        const claude = JSON.parse(
            await readFile(path.join(paths.config, ".claude.json"), "utf8"),
        );
        expect(claude.projects["/w/demo"].hasTrustDialogAccepted).toBe(true);
        expect(
            JSON.parse(
                await readFile(path.join(paths.data, "roots.json"), "utf8"),
            ).roots,
        ).toEqual(["/w/demo"]);
        const mcp = JSON.parse(
            await readFile(await writeMcpConfig(paths, "/k"), "utf8"),
        );
        expect(mcp.mcpServers.knossos.command).toBe("/k/tools/mcp-serve");
        expect(mcp.mcpServers.knossos.env.KNOSSOS_DATA_DIR).toBe(paths.data);
    });
});

describe("workspaces", () => {
    it("clones at the pinned commit and resets edits and new files", async () => {
        const origin = await originRepo();
        const paths = evalPaths(await tmp("eval-root-"));
        const dir = await ensureClone(
            "demo",
            { url: origin.dir, commit: origin.commit },
            paths,
        );
        await writeFile(path.join(dir, "a.txt"), "changed\n");
        await writeFile(path.join(dir, "new.txt"), "x\n");
        await resetWorkspace(dir, origin.commit);
        expect(await readFile(path.join(dir, "a.txt"), "utf8")).toBe("one\n");
        await expect(readFile(path.join(dir, "new.txt"))).rejects.toThrow();
        expect(
            await ensureClone(
                "demo",
                { url: origin.dir, commit: origin.commit },
                paths,
            ),
        ).toBe(dir);
    });
});
