import { execFile } from "node:child_process";
import { mkdir, readFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";
import { afterAll, describe, expect, it } from "vitest";
import { removeTemp, tmp } from "./fixtures/tmp.mjs";

afterAll(removeTemp);

const run = promisify(execFile);
const REPO = fileURLToPath(new URL("../..", import.meta.url));
const HELPER = path.join(REPO, "tools", "eval", "annotations.php");

/** A graph database migrated to the real schema, holding one project at `root` with the given notes. */
async function database(root, notes) {
    const db = path.join(await tmp("eval-annotations-"), "knossos.sqlite");
    const php = `
        require ${JSON.stringify(path.join(REPO, "vendor", "autoload.php"))};
        $pdo = Knossos\\Store\\SqliteConnection::open(${JSON.stringify(db)});
        (new Knossos\\Store\\MigrationRunner($pdo, ${JSON.stringify(path.join(REPO, "migrations"))}))->migrate();
        $pdo->exec("INSERT INTO projects(id, name, root_realpath, created_at, updated_at) VALUES ('p', 'P', ${JSON.stringify(root).replace(/"/g, "'")}, 't', 't')");
        $insert = $pdo->prepare("INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at) VALUES ('p', ?, 'note', ?, ?, 't', 't')");
        foreach (${JSON.stringify(notes).replace(/"/g, "'")} as [$name, $value, $author]) { $insert->execute([$name, $value, $author]); }
    `;
    await run("php", ["-r", php]);
    return db;
}

const values = async (db) =>
    (
        await run("php", [
            "-r",
            `$pdo = new PDO('sqlite:${db}'); echo json_encode($pdo->query('SELECT canonical_name, value, author FROM annotations ORDER BY canonical_name, author')->fetchAll(PDO::FETCH_NUM));`,
        ])
    ).stdout;

describe("annotations.php", () => {
    it("exports a project's notes, counting the model's, then clears and restores them", async () => {
        const root = await tmp("eval-root-");
        await mkdir(root, { recursive: true });
        const db = await database(root, [
            ["App\\A", "found by an agent", "model"],
            ["App\\B", "the team says so", "user"],
        ]);
        const file = path.join(path.dirname(db), "notes.json");

        expect(
            (await run(HELPER, ["export", db, root, file])).stdout.trim(),
        ).toBe("1");
        expect(JSON.parse(await readFile(file, "utf8"))).toHaveLength(2);

        await run(HELPER, ["clear", db, root]);
        expect(JSON.parse(await values(db))).toEqual([]);

        await run(HELPER, ["import", db, root, file]);
        expect(JSON.parse(await values(db))).toEqual([
            ["App\\A", "found by an agent", "model"],
            ["App\\B", "the team says so", "user"],
        ]);
    });

    it("treats a repository no scan registered as one with no notes", async () => {
        const db = await database("/nowhere", []);
        const root = await tmp("eval-unscanned-");
        expect((await run(HELPER, ["clear", db, root])).stdout.trim()).toBe(
            "0",
        );
    });

    it("refuses an unknown command", async () => {
        const failed = await run(HELPER, ["wipe", "x.sqlite", "/x"]).catch(
            (e) => e,
        );
        expect(failed.code).toBe(2);
        expect(failed.stderr).toContain("usage: annotations.php");
    });
});
