/**
 * The key a location goes under in a request's `input_hashes`, worked out
 * once per location.
 */

import path from "node:path";
import {
    contains,
    defaultLibDirectory,
    normalize,
    realSourcePath,
    relativeInside,
} from "./project-paths.js";

/**
 * The key a read of `absolute` goes under in `input_hashes`, or null for a read
 * the core cannot track: the root itself, anything outside it, or the default
 * library, which is the worker's own and never the project's, even under a
 * root that happens to contain the worker.
 *
 * A path below node_modules is keyed like any other. Discovery never hashes
 * one, so the core re-reads it when the scan commits: declarations resolved
 * through a dependency decide the facts of every file importing it.
 */
export function inputHashKey(root, absolute) {
    const relative = relativeInside(root, absolute);
    if (
        relative === null ||
        relative === "" ||
        contains(defaultLibDirectory(), absolute)
    ) {
        return null;
    }
    return relative;
}

/**
 * The real absolute path and key of each location one request asks about.
 *
 * The compiler shares one list of failed lookups between every file importing
 * the same module and appends each importing directory's lookups to it, so a
 * project with a folder per file names the same few thousand locations once
 * for every file. Each is worked out once and remembered for the request,
 * which is as long as an answer holds: the next request starts afresh.
 */
export class LocationKeys {
    #known = new Map();

    /** @param {string} root the project root the keys are relative to */
    constructor(root) {
        this.root = root;
    }

    /**
     * @param {string} location a path as the compiler or a host names it
     * @returns {{absolute: string, key: string | null}}
     */
    of(location) {
        let known = this.#known.get(location);
        if (known === undefined) {
            const absolute = realSourcePath(normalize(path.resolve(location)));
            known = { absolute, key: inputHashKey(this.root, absolute) };
            this.#known.set(location, known);
        }
        return known;
    }
}
