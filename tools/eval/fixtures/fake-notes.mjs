#!/usr/bin/env node
/**
 * Dev-only test double for tools/eval/annotations.php: appends what it was
 * asked to FAKE_NOTES_LOG and, for export, writes FAKE_NOTES_ROWS (or `[]`)
 * to the file and prints FAKE_NOTES_WRITTEN (or 0).
 */
import { appendFileSync, writeFileSync } from "node:fs";

const [command, db, root, file] = process.argv.slice(2);
if (process.env.FAKE_NOTES_LOG)
    appendFileSync(
        process.env.FAKE_NOTES_LOG,
        `${JSON.stringify({ command, db, root, file })}\n`,
    );
if (command === "export")
    writeFileSync(file, process.env.FAKE_NOTES_ROWS ?? "[]");
process.stdout.write(
    `${command === "export" ? (process.env.FAKE_NOTES_WRITTEN ?? "0") : "0"}\n`,
);
