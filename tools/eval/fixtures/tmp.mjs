/** Dev-only. Temp directories for the specs, all removed again by `removeTemp` in an afterAll hook. */
import { mkdtemp, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";

const made = [];

export async function tmp(prefix) {
    const dir = await mkdtemp(path.join(os.tmpdir(), prefix));
    made.push(dir);
    return dir;
}

export async function removeTemp() {
    await Promise.all(
        made.splice(0).map((dir) => rm(dir, { recursive: true, force: true })),
    );
}
