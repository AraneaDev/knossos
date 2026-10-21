/**
 * Owns deterministic node de-duplication and the edge list for a single source
 * file. Edges are reported once per site: the core collapses repeated edges
 * (see docs/reference/scanner-protocol-v1.md, "Repeated edges").
 *
 * A component's virtual source can end in declarations its framework implies
 * (SvelteKit's `$props`, a generic component's type parameters, Astro's
 * global), typed like the component's own code but written nowhere in it.
 * `own.end` is the offset where the file's own text ends: a node declared past
 * it is no fact of the file, an edge to one is dropped, an edge from one is
 * the module's (`own.moduleId`), and every evidence line stays within the
 * file's own lines.
 */

import { componentSources } from "./source-caches.js";

export class FactAccumulator {
    constructor(sourceFile, relative, evidence, own = {}) {
        this.sourceFile = sourceFile;
        this.relative = relative;
        this.evidence = evidence;
        this.nodesById = new Map();
        this.edgeList = [];
        this.ownEnd = own.end ?? Infinity;
        this.moduleId = own.moduleId;
        this.lastLine =
            own.end === undefined
                ? Infinity
                : sourceFile.getLineAndCharacterOfPosition(own.end).line + 1;
        // The ids of the declarations past the file's own text.
        this.appended = new Set();
    }

    /**
     * The accumulator for `sourceFile`, bounded to a component's own text
     * when the file is one, with past-the-end edges given to `moduleId`.
     */
    static forSource(sourceFile, relative, evidence, moduleId) {
        const end = componentSources.get(sourceFile)?.sourceLength;
        return new FactAccumulator(
            sourceFile,
            relative,
            evidence,
            end === undefined ? {} : { end, moduleId },
        );
    }

    get nodes() {
        return [...this.nodesById.values()];
    }

    get edges() {
        return this.edgeList.filter(
            (edge) => !this.appended.has(edge.target),
        );
    }

    /** Where a syntax node stands, never past the file's own last line. */
    located(node) {
        const location = this.evidence(this.sourceFile, this.relative, node);
        if (this.lastLine === Infinity) return location;
        return {
            ...location,
            start_line: Math.min(location.start_line, this.lastLine),
            end_line: Math.min(location.end_line, this.lastLine),
        };
    }

    /**
     * Add a node unless one has its id; false when it is a declaration past
     * the file's own text, which is no fact.
     */
    addNode(
        id,
        kind,
        canonicalName,
        displayName,
        node,
        attributes = {},
        origin = "ast",
    ) {
        if (this.nodesById.has(id)) return true;
        // The module itself starts past its own text when that text is blank.
        if (
            this.ownEnd !== Infinity &&
            node !== this.sourceFile &&
            node.getStart(this.sourceFile) >= this.ownEnd
        ) {
            this.appended.add(id);
            return false;
        }
        this.nodesById.set(id, {
            local_id: id,
            kind,
            canonical_name: canonicalName,
            display_name: displayName,
            origin,
            confidence: "certain",
            evidence: this.located(node),
            attributes,
        });
        return true;
    }

    addEdge(
        kind,
        source,
        target,
        node,
        attributes = {},
        origin = "ast",
        confidence = "certain",
    ) {
        const location = this.located(node);
        if (this.appended.has(source)) source = this.moduleId;
        this.edgeList.push({
            kind,
            source,
            target,
            origin,
            confidence,
            evidence: location,
            attributes,
        });
    }
}
