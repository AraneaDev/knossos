/**
 * The reads of one request's contributions, each path and hash stated once.
 *
 * Every file of a program reads much the same manifests and declarations, so
 * a contribution's `reads` map repeats the same paths and 64-character hashes
 * for file after file. For a core that accepts it, a path and its hash travel
 * once, as a row of the request's read table, and a contribution names its
 * reads by row number.
 */

// Serialized bytes of rows one `scan/read_table` notification carries at
// most, well under the core's line cap.
const PART_BYTES = 500_000;

// What a row adds to its frame beside the path: brackets, commas and a hash.
const ROW_OVERHEAD_BYTES = 72;

export class ReadTable {
    // Path to the hash last sent for it and the row that carries it.
    #rows = new Map();
    #size = 0;

    /**
     * The rows a `reads` map refers to, and the rows not sent before, in the
     * parts to send ahead of the contribution.
     *
     * A path read again with another value gets a new row: a row says what
     * was read when the contribution naming it was made, as the map did.
     *
     * @param {Record<string, string | null>} reads
     * @returns {{refs: number[], parts: Array<Array<[string, string | null]>>}}
     */
    refer(reads) {
        const refs = [];
        const parts = [];
        let part = [];
        let bytes = 0;
        for (const [path, hash] of Object.entries(reads)) {
            let row = this.#rows.get(path);
            if (row === undefined || row.hash !== hash) {
                row = { hash, index: this.#size++ };
                this.#rows.set(path, row);
                const size =
                    Buffer.byteLength(JSON.stringify(path)) +
                    ROW_OVERHEAD_BYTES;
                if (part.length > 0 && bytes + size > PART_BYTES) {
                    parts.push(part);
                    part = [];
                    bytes = 0;
                }
                part.push([path, hash]);
                bytes += size;
            }
            refs.push(row.index);
        }
        if (part.length > 0) parts.push(part);
        return { refs, parts };
    }
}
