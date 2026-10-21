/**
 * The programs the scanner keeps between requests, for incremental reuse,
 * bounded so a project with many configs never holds them all at once.
 */

// Each retained ts.Program holds its own parsed default library and type
// checker (~100-120MB). A repo with many tsconfigs builds one program per
// config within a single scan; retaining them all at once exhausts the
// worker's --max-old-space-size cap. Bound the cache so peak live programs
// stays small while still allowing incremental reuse across scans in watch
// mode. A program is only needed until its files are emitted, so evicting the
// least-recently-used program never affects correctness — an evicted config is
// simply rebuilt from scratch on its next scan.
const MAX_CACHED_PROGRAMS = 2;

export class ProgramCache {
    /**
     * @param {() => void} collectGarbage runs a full collection once a
     *   released program should be freed (see #collectReleased)
     */
    constructor(collectGarbage) {
        this.programs = new Map();
        this.collectGarbage = collectGarbage;
        // Reset per request; see store.
        this.builtThisRequest = 0;
        // Set when a program leaves the cache; see #collectReleased.
        this.releasedSinceCollection = false;
    }

    /** How many programs the cache holds. */
    get size() {
        return this.programs.size;
    }

    /** Start counting the programs a new request builds. */
    beginRequest() {
        this.builtThisRequest = 0;
    }

    /** The program last built under `key`, if the cache still holds it. */
    get(key) {
        return this.programs.get(key);
    }

    /**
     * Make room for the program about to be built under `key`, and free what
     * that released.
     */
    reserve(key) {
        this.#reserveSlot(key);
        this.#collectReleased();
    }

    // Free a slot before the next program is built. A program is constructed
    // while the cache still holds its entries, so inserting first and evicting
    // after leaves MAX_CACHED_PROGRAMS + 1 programs resident at the peak — the
    // overshoot the cap exists to prevent, and enough to OOM the worker's
    // 512 MB heap on a project with three tsconfigs over the same sources.
    //
    // The entry for `key` is never evicted: that program is handed back to
    // createRestrictedProgram for incremental reuse, so dropping it would
    // trade memory for a full rebuild of the very config being scanned.
    #reserveSlot(key) {
        const limit = Math.max(0, MAX_CACHED_PROGRAMS - 1);
        for (const oldest of [...this.programs.keys()]) {
            if (this.programs.size <= limit) break;
            if (oldest === key) continue;
            this.programs.delete(oldest);
            this.releasedSinceCollection = true;
        }
    }

    // A released program is only garbage until V8 collects it, and under the
    // worker's 2 GB heap cap V8 has no reason to: on a project with nine
    // programs per request the worker grew to 1.7 GB resident with under
    // 0.5 GB live, and a host memory guard (earlyoom) SIGTERMed it as the
    // largest process on the machine. Collecting before the next build keeps
    // the process near its live set, about two programs: on that project the
    // peak fell to 1.2 GB with no measurable change in scan time. Only when
    // something was released, so a request within the cache's bound pays
    // nothing.
    #collectReleased() {
        if (!this.releasedSinceCollection) return;
        this.releasedSinceCollection = false;
        this.collectGarbage();
    }

    /**
     * Insert a program as most-recently-used, or release the cache entirely
     * once this request has outgrown it.
     *
     * The cache can only ever hit when a request builds no more programs than
     * it holds. Above that it is evicted down to the last programs built,
     * while the next request starts again at the first config, so it never
     * hits: measured on a project with six programs per request, reuse was
     * zero across six consecutive scans of an unchanged tree. Each retained
     * program carries its own default library and type checker, so that was
     * ~100-120MB apiece held back from a heap whose peak was already 1.35GB,
     * bought nothing, and made the scan likelier to die of heap exhaustion.
     *
     * So a request that stays within the cap keeps its programs, and one that
     * outgrows it drops them rather than paying to hold what it cannot use.
     */
    store(key, program) {
        this.builtThisRequest += 1;
        if (this.builtThisRequest > MAX_CACHED_PROGRAMS) {
            this.programs.clear();
            this.releasedSinceCollection = true;
            return;
        }
        this.programs.delete(key);
        this.programs.set(key, program);
        while (this.programs.size > MAX_CACHED_PROGRAMS) {
            const oldest = this.programs.keys().next().value;
            this.programs.delete(oldest);
            this.releasedSinceCollection = true;
        }
    }
}
