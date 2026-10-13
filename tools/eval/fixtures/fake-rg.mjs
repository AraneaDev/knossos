#!/usr/bin/env node
/**
 * Dev-only test double for `rg -n --no-heading -w <word> .`: prints the
 * matching lines of the files directly in the working directory, exits 1 when
 * nothing matches and 2 when the directory cannot be read, as rg does.
 */
import { readFileSync, readdirSync } from "node:fs";

const word = process.argv.at(-2);
let files;
try {
    files = readdirSync(".", { withFileTypes: true }).filter((e) => e.isFile());
} catch (error) {
    process.stderr.write(`rg: ${error.message}\n`);
    process.exit(2);
}
const pattern = new RegExp(`\\b${word}\\b`);
const hits = files.flatMap((f) =>
    readFileSync(f.name, "utf8")
        .split("\n")
        .flatMap((line, i) =>
            pattern.test(line) ? [`./${f.name}:${i + 1}:${line}`] : [],
        ),
);
if (hits.length === 0) process.exit(1);
process.stdout.write(`${hits.join("\n")}\n`);
