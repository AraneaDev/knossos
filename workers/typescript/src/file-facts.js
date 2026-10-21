/**
 * One requested file's facts: the fact collector walked over the file's
 * syntax with what the program answers about it, as the file's contribution.
 */
import ts from "typescript";
import { callSitesInto, destructuresUntyped } from "./call-argument-sites.js";
import { inRootFile } from "./input-reads.js";
import { TypeScriptLanguageFactCollector } from "./language-fact-collector.js";
import { relativeInside } from "./project-paths.js";

// Every contribution's owner key is this prefix and the file's project path.
export const OWNER_KEY_PREFIX = "knossos.typescript:file:";

/**
 * One requested file's contribution, without its hash or reads.
 *
 * Collection is isolated per file: a single adversarial or minified file can
 * overflow the visitor recursion (RangeError), and one bad file must degrade
 * to a diagnostic, not discard the facts of every other file in the request.
 *
 * @param {ScanRequest} request
 * @param {{tracker: {checker: ts.TypeChecker}, probes: string[], belongs: (relative: string) => boolean}} reads the
 *   checker that notes declaration files, where the paths the file's facts
 *   asked about beyond its imports are listed, and which files the program
 *   describes (see programMembers)
 * @param {{own: object[], programWide: object[]}} diagnostics the compiler's
 *   diagnostics on this file, and the program-wide ones it carries
 */
export function collectFile(
    { root, exclusions },
    program,
    sourceFile,
    { tracker, probes, belongs },
    diagnostics,
) {
    const relative = relativeInside(root, sourceFile.fileName);
    try {
        const collector = new FactCollector(root, sourceFile, tracker.checker, {
            options: program.getCompilerOptions(),
            exclusions,
            sourceFileAt: (fileName) => {
                probes.push(fileName);
                return program.getSourceFile(fileName);
            },
            fileExists: (fileName) => {
                probes.push(fileName);
                return inRootFile(root, fileName);
            },
            // Whether the compiler resolved an import's specifier to any
            // file, a dependency's included. An internal compiler method.
            resolved: (specifier) =>
                program.getResolvedModuleFromModuleSpecifier(
                    specifier,
                    sourceFile,
                )?.resolvedModule !== undefined,
            // The calls of other files that hand this file's functions an
            // object literal, and the read each such file becomes.
            callSitesInto: () =>
                destructuresUntyped(sourceFile)
                    ? callSitesInto(
                          program,
                          root,
                          exclusions,
                          sourceFile.fileName,
                          belongs,
                      )
                    : [],
            readCaller: (callerFile) => tracker.files.add(callerFile),
        });
        collector.collect();
        return {
            owner_key: `${OWNER_KEY_PREFIX}${relative}`,
            nodes: collector.nodes,
            edges: collector.edges,
            diagnostics: [...diagnostics.own, ...diagnostics.programWide],
        };
    } catch (error) {
        return {
            owner_key: `${OWNER_KEY_PREFIX}${relative}`,
            nodes: [],
            edges: [],
            diagnostics: [
                {
                    severity: "error",
                    code: "TS_INTERNAL_ERROR",
                    message:
                        error instanceof Error ? error.message : String(error),
                    evidence: { path: relative, start_line: 1, end_line: 1 },
                },
                ...diagnostics.programWide,
            ],
        };
    }
}

class FactCollector {
    constructor(root, sourceFile, checker, project = {}) {
        this.language = new TypeScriptLanguageFactCollector(
            root,
            sourceFile,
            checker,
            project,
        );
    }

    get nodes() {
        return this.language.nodes;
    }

    get edges() {
        return this.language.edges;
    }

    collect() {
        this.language.initialize();
        this.visit(this.language.sourceFile);
        this.language.finish();
    }

    visit(node) {
        const pushed = this.language.enter(node);
        this.language.handle(node);
        ts.forEachChild(node, (child) => this.visit(child));
        this.language.leave(pushed);
    }
}
