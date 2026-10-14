#!/usr/bin/env node
/**
 * Dev-only test double for tools/eval/annotations.php: appends what it was
 * asked to FAKE_NOTES_LOG and, for export, writes FAKE_NOTES_ROWS (or `[]`)
 * to the file. Export and import print FAKE_NOTES_WRITTEN (or 0), as the
 * model notes a first pass leaves are the ones its second pass is handed.
 * FAKE_NOTES_FAIL names a command that fails the way a locked database does.
 */
import { appendFileSync, writeFileSync } from "node:fs";

const [command, db, root, file] = process.argv.slice(2);
if (process.env.FAKE_NOTES_LOG)
    appendFileSync(
        process.env.FAKE_NOTES_LOG,
        `${JSON.stringify({ command, db, root, file })}\n`,
    );
if (process.env.FAKE_NOTES_FAIL === command) {
    process.stderr.write("PDOException: database is locked\n");
    process.exit(1);
}
if (command === "export")
    writeFileSync(file, process.env.FAKE_NOTES_ROWS ?? "[]");
process.stdout.write(
    `${command === "clear" ? "0" : (process.env.FAKE_NOTES_WRITTEN ?? "0")}\n`,
);
