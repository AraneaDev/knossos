/**
 * The TypeScript scanner's request orchestration: it discovers the configs a
 * request needs, builds or reuses each program, emits one contribution per
 * requested file, and attributes every read to the files it was made for.
 * What a file's facts are is the fact collector's concern
 * (language-fact-collector.js, run per file by file-facts.js); which programs
 * stay built between requests is program-cache.js's; how the scan reads the
 * tree is the project-paths, source-reading and input-reads modules'.
 */
import path from "node:path";
import { callArgumentPairs } from "./call-argument-sites.js";
import { isStackOverflow } from "./errors.js";
import { BUILT_IN_EXCLUSIONS, Exclusions } from "./exclusions.js";
import {
    configFilesForScan,
    configOwners,
    fallbackConfig,
    fallbackGroups,
    fallbackMembers,
    fallbackProgramKey,
} from "./fallback-groups.js";
import { collectFile, OWNER_KEY_PREFIX } from "./file-facts.js";
import {
    InputReadRecorder,
    ReadAttribution,
    recordUnreadSourceFiles,
} from "./input-reads.js";
import {
    anchoredAt,
    programDiagnostics,
    programWideCarrier,
} from "./program-diagnostics.js";
import { ProgramCache } from "./program-cache.js";
import {
    createRestrictedProgram,
    outputSources,
    parseConfig,
    programConfig,
} from "./program-host.js";
import {
    belowNodeModules,
    normalize,
    relativeInside,
    sourceFilesFrom,
    validateRoot,
} from "./project-paths.js";
import {
    maxFileBytesFrom,
    readableAsItself,
    validateRequestedFiles,
} from "./request-validation.js";
import { parsedContentHashes } from "./source-caches.js";

/**
 * Whether a config's program must be built for this request.
 *
 * It is when a requested file is among the config's own root files, the
 * files its `files`, `include` and `exclude` name, whichever config owns
 * that file: the program's environment follows every root file, so an edit
 * that imports a global script into a file two configs both root changes
 * what every file of both programs sees, and the core learns that only from
 * the environment of each program built. It also is when a requested file
 * not yet emitted is a root file of no config at all: such a file is emitted
 * by the first config's program that reaches it through imports, so each
 * config's program is built in turn until one holds it, or the fallback
 * program of the file's own group takes it (see #scanFallback).
 */
function needsProgram(request, requested, configPath, parsed) {
    const rootNames = new Set(
        (parsed.ownFileNames ?? parsed.fileNames).map((fileName) =>
            relativeInside(request.root, fileName),
        ),
    );
    return requested.some((relative) => {
        const key = normalize(relative);
        if (rootNames.has(key)) return true;
        if (request.emitted.has(key)) return false;
        const owner = request.owners.get(key);
        return owner === undefined || owner === configPath;
    });
}

/**
 * Whether a program's reads for a file are the file's own contribution's:
 * only when a config lists the file and the program being built is that
 * config's program. A file no config lists is emitted by whichever config's
 * program reaches it first, or else by the fallback program of its own group,
 * so no program can claim its reads, the fallback program of its own group
 * included; nor can any program claim the reads of a file the core never
 * discovered.
 *
 * @param {Map<string, string>} owners file to the config that describes it
 * @returns {(relative: string, programKey: string) => boolean}
 */
function programOwnership(owners) {
    return (relative, programKey) =>
        owners.has(relative) && owners.get(relative) === programKey;
}

/**
 * Whether the program being built describes a file: one no other config
 * lists and, in a fallback program, one of its own group. Only such a file's
 * calls type a parameter of another, and only such a file's parameters are
 * typed: a file another config lists is described by that config's program.
 *
 * @returns {(relative: string) => boolean}
 */
function programMembers(request, fallback) {
    const owners = fallback ? new Map() : request.owners;
    return (relative) =>
        !(owners.has(relative) && owners.get(relative) !== request.owner) &&
        (!fallback ||
            request.fallbackGroupOf.get(relative) ===
                request.fallbackDirectory);
}

/**
 * Which files of a program it does not emit, whether it was built or failed:
 * a file outside the project or under node_modules, one the request did not
 * name, one already emitted, one another config lists (its own program
 * describes it under the options the project really uses) and, in a fallback
 * program, one of another fallback group, which its own group's program
 * emits (see #scanFallback). A failed program marks only the files it would
 * have emitted, so a file left to another program is answered by that one.
 *
 * @param {Map<string, string>} owners the config owners this program defers
 *   to: the request's for a config's program, none for a fallback program
 * @returns {(relative: string | null) => boolean}
 */
function emissionSkipped(request, owners, fallback) {
    const { requestedSet, emitted, owner } = request;
    return (relative) =>
        relative === null ||
        belowNodeModules(relative) ||
        !requestedSet.has(relative) ||
        emitted.has(relative) ||
        (owners.has(relative) && owners.get(relative) !== owner) ||
        (fallback &&
            request.fallbackGroupOf.get(relative) !==
                request.fallbackDirectory);
}

/**
 * The checker, noting the source file of every declaration behind a symbol,
 * type or signature it answers with, so a requested file is attributed the
 * files its facts were resolved against. `files` is replaced per file.
 */
function declarationTracker(checker) {
    const tracker = { files: new Set() };
    const wrapped = new Map();
    tracker.checker = new Proxy(checker, {
        get(target, property) {
            const value = target[property];
            if (typeof value !== "function") return value;
            let wrapper = wrapped.get(property);
            if (wrapper === undefined) {
                wrapper = (...args) => {
                    const answer = value.apply(target, args);
                    noteDeclarationFiles(answer, tracker.files);
                    return answer;
                };
                wrapped.set(property, wrapper);
            }
            return wrapper;
        },
    });
    return tracker;
}

/** Add the files declaring what a checker answer names. */
function noteDeclarationFiles(answer, files) {
    if (answer === null || typeof answer !== "object") return;
    if (Array.isArray(answer)) {
        for (const item of answer) noteDeclarationFiles(item, files);
        return;
    }
    const symbols = [
        answer.escapedName === undefined ? undefined : answer,
        answer.symbol,
        answer.aliasSymbol,
    ];
    const declarations = symbols.flatMap(
        (symbol) => symbol?.declarations ?? [],
    );
    if (answer.declaration !== undefined) declarations.push(answer.declaration);
    for (const declaration of declarations) {
        // A synthesized declaration hangs off no file.
        const file = declaration?.getSourceFile?.();
        if (file !== undefined) files.add(file);
    }
}

/**
 * The state one scan request carries through its programs: what it read, what
 * it emitted, and which program owns each file.
 *
 * @typedef {object} ScanRequest
 * @property {string} root
 * @property {Exclusions} exclusions
 * @property {number} maxFileBytes
 * @property {InputReadRecorder} reads
 * @property {ReadAttribution} attribution
 * @property {Set<string>} requestedSet
 * @property {Set<string>} emitted
 * @property {(contribution: object) => void} emit
 * @property {unknown} versions
 * @property {unknown[]} vueProjects
 * @property {string[]} sourceFiles
 * @property {string[]} packageDirectories
 * @property {Map<string, string>} owners file to the config that describes it
 * @property {*} outputSources
 * @property {Map<string, {files: string[], parsed: object | undefined}>} fallbackMembers
 * @property {(relative: string, program: string) => boolean} ownedByProgram
 * @property {Map<string, string>} [fallbackGroupOf]
 * @property {string} [fallbackDirectory]
 * @property {boolean} [fallbackOrphaned] whether the project has a config at all, so the fallback program's files are left out of every TypeScript project
 * @property {string} [owner] the config whose program is being built
 * @property {string} [program] the key of the program being built
 */

/**
 * Emit a contribution for each requested file refused before any program is
 * built, first, so a file this worker cannot read still gets its own
 * contribution: raising it to the request would discard the facts every other
 * file in the batch contributes.
 *
 * @param {Array<{relative: string, message: string, failedRead?: boolean, refusalHash?: string|null}>} rejected
 * @param {InputReadRecorder} reads
 * @param {(contribution: object) => void} emit
 */
function emitRejections(rejected, reads, emit) {
    for (const rejection of rejected) {
        if (rejection.failedRead) reads.record(rejection.relative, null);
        // Refused on what the file says rather than on its name, so what
        // was read is evidence: a stable tree matches the hash, a script
        // swapped and restored around the probe does not.
        else if (rejection.refusalHash !== undefined)
            reads.record(rejection.relative, rejection.refusalHash);
        emit(unscannableContribution(rejection.relative, rejection.message));
    }
}

/**
 * The state a request carries through its programs, complete before the first
 * is built; only the fields naming the program being built change after.
 *
 * @param {Array<[string, object]>} parsedConfigs config path and its parsed config
 * @returns {ScanRequest}
 */
function buildRequest(
    params,
    { root, exclusions, reads, requested, parsedConfigs, emit },
) {
    const maxFileBytes = maxFileBytesFrom(params.limits);
    const sourceFiles = sourceFilesFrom(params.source_files);
    const packageDirectories = Array.isArray(params.package_directories)
        ? params.package_directories.filter(
              (directory) => typeof directory === "string",
          )
        : [];
    const owners = configOwners(root, parsedConfigs);
    return {
        root,
        exclusions,
        maxFileBytes,
        reads,
        attribution: new ReadAttribution(
            root,
            exclusions,
            reads,
            maxFileBytes,
            new Set(sourceFiles),
        ),
        requestedSet: new Set(requested.map((file) => normalize(file))),
        emitted: new Set(),
        emit,
        versions: params.typescript_versions,
        vueProjects: Array.isArray(params.vue_projects)
            ? params.vue_projects
            : [],
        sourceFiles,
        packageDirectories,
        owners,
        outputSources: outputSources(root, parsedConfigs),
        fallbackMembers: fallbackMembers(
            root,
            sourceFiles,
            owners,
            parsedConfigs,
            packageDirectories,
        ),
        ownedByProgram: programOwnership(owners),
    };
}

/**
 * Backstop: the PHP side requires exactly one contribution per requested file
 * and treats a gap as a hard error. A file can still be absent from every
 * program — it grew past the byte cap after validateRequestedFiles stat'd it,
 * or a symlinked path resolved outside the root — so name the gap here rather
 * than letting it surface as "scanner omitted".
 *
 * @param {ScanRequest} request
 * @param {string[]} requested the accepted requested files
 */
function emitUncoveredFiles(request, requested) {
    const { root, maxFileBytes, reads, emitted, emit } = request;
    for (const relative of requested) {
        const key = normalize(relative);
        if (emitted.has(key)) continue;
        // A file the host was asked for and could not read was recorded
        // there. One the compiler never asked for is recorded here only if
        // it is no longer readable as itself: a stable file the compiler
        // simply leaves out (as it did `FOO.TS` before offeredPath) must
        // not fail every scan.
        if (!readableAsItself(root, key, maxFileBytes)) {
            reads.record(key, null);
        }
        emit(
            unscannableContribution(
                key,
                "File was not included in any TypeScript program (it may have changed size or moved since discovery).",
            ),
        );
        emitted.add(key);
    }
}

/**
 * Performs bounded compiler-backed scanning without executing target modules.
 * Instances retain TypeScript programs for incremental reuse.
 */
export class TypeScriptScanner {
    /**
     * @param {{observeHostPath?: (stage: "load"|"read", absolute: string) => void, collectGarbage?: () => void}} [options]
     *   `observeHostPath` is a test seam, handed to each request's recorder.
     *   `collectGarbage` runs a full collection; it defaults to the `gc` that
     *   `--expose-gc` provides, and does nothing when the flag was not given.
     */
    constructor({ observeHostPath, collectGarbage } = {}) {
        this.programCache = new ProgramCache(
            collectGarbage ?? (() => globalThis.gc?.()),
        );
        this.observeHostPath = observeHostPath;
    }

    /**
     * Stream deterministic owned contributions for the requested source files.
     *
     * `source_files` lists every file of the language the core discovered: a
     * program for files no config includes is rooted on the whole group such
     * a file sits in, and only a listed file's reads can be unattributed.
     * A contribution for a file no config lists carries `listed: false`,
     * since whichever program reaches it first emits it.
     *
     * The result's `reads` are what every file of the request shares;
     * `unattributed_reads` are what the programs' other discovered files read
     * in the program that describes them, confirmed by `input_hashes` and
     * owned by no contribution; `environments` maps every program the request
     * built to the digest of its global declarations.
     *
     * @param {{root: unknown, files: unknown, config_files?: unknown, limits?: unknown, typescript_versions?: unknown, source_files?: unknown, exclusions?: unknown}} params
     * @param {(contribution: object) => void} emit
     * @returns {{files_scanned: number, programs: number, programs_reused: number, input_hashes: Record<string, string|null>, reads: Record<string, string|null>, unattributed_reads: Record<string, string|null>, environments: Record<string, string>}}
     */
    scan(params, emit) {
        // Held until the request has finished reading: a later program can
        // read a path again and disagree, which turns its value null, and a
        // contribution's `reads` must carry the value `input_hashes` ends with.
        const contributions = [];
        const exclusions = new Exclusions(
            params.exclusions ?? BUILT_IN_EXCLUSIONS,
        );
        const { result, attribution } = this.#scanRequest(
            params,
            exclusions,
            (contribution) => contributions.push(contribution),
        );
        for (const contribution of contributions) {
            emit({
                ...contribution,
                ...(contribution.program === undefined
                    ? {}
                    : {
                          environment: attribution.environmentOf(
                              contribution.program,
                          ),
                      }),
                reads: attribution.readsOf(
                    contribution.owner_key.slice(OWNER_KEY_PREFIX.length),
                ),
            });
        }
        const requested = Array.isArray(params.files) ? params.files : [];
        return {
            ...result,
            reads: attribution.sharedReads(requested),
            unattributed_reads: attribution.unattributedReads(requested),
            environments: attribution.environments(),
        };
    }

    /**
     * Scan one request, handing each contribution to `emit` without its
     * `reads`, which `attribution` gives once every read is in.
     *
     * @returns {{result: {files_scanned: number, programs: number, programs_reused: number, input_hashes: Record<string, string|null>}, attribution: ReadAttribution}}
     */
    #scanRequest(params, exclusions, emit) {
        const root = validateRoot(params.root);
        const { accepted: requested, rejected } = validateRequestedFiles(
            root,
            params.files,
            params.limits,
        );
        const reads = new InputReadRecorder(this.observeHostPath);
        emitRejections(rejected, reads, emit);
        const parsedConfigs = configFilesForScan(
            root,
            params.config_files,
            exclusions,
        ).map((configPath) => [
            configPath,
            parseConfig(root, configPath, reads, exclusions),
        ]);
        const request = buildRequest(params, {
            root,
            exclusions,
            reads,
            requested,
            parsedConfigs,
            emit,
        });
        let programs = 0;
        let programsReused = 0;
        this.programCache.beginRequest();
        const tally = (outcome) => {
            if (outcome === undefined) return;
            ++programs;
            if (outcome.reused) ++programsReused;
        };

        this.#scanConfigPrograms(parsedConfigs, requested, request, tally);
        const remaining = requested.filter(
            (relative) => !request.emitted.has(normalize(relative)),
        );
        if (remaining.length > 0) {
            this.#scanFallback(root, remaining, parsedConfigs, request, tally);
        }
        emitUncoveredFiles(request, requested);

        return {
            result: {
                files_scanned: request.emitted.size + rejected.length,
                programs,
                programs_reused: programsReused,
                input_hashes: reads.toResult(),
            },
            attribution: request.attribution,
        };
    }

    /**
     * Build each config's program the request needs (see needsProgram) and
     * emit the requested files it covers, in config order.
     *
     * A program emits a requested file another config includes only from
     * that config's own program, so a program that includes none of the
     * requested files, while each of the files still to emit has a config of
     * its own, would emit nothing and only read files for nobody.
     *
     * @param {Array<[string, object]>} parsedConfigs config path and its parsed config
     * @param {string[]} requested the accepted requested files
     */
    #scanConfigPrograms(parsedConfigs, requested, request, tally) {
        const { root } = request;
        for (const [configPath, parsed] of parsedConfigs) {
            if (!needsProgram(request, requested, configPath, parsed)) continue;
            request.owner = configPath;
            request.program = configPath;
            tally(
                this.#scanProgram(
                    `${root}\0${configPath}`,
                    programConfig(
                        request,
                        path.dirname(path.join(root, configPath)),
                        parsed,
                    ),
                    request,
                ),
            );
        }
    }

    /**
     * Whatever no config's program emitted, owned or not, read under the
     * options of the config beside it: a package's tests are often outside
     * its tsconfig's `include`, and its test runner still resolves them
     * through that package's aliases and paths.
     *
     * The program's root files are every discovered file of the group that
     * no config lists, not only the files this request named: a test sees
     * the globals its setup file declares and the augmentations it imports
     * whether or not the setup was requested with it, and the program's
     * environment then does not follow the request. A declaration file no
     * config lists is rooted with its group the same way, so an importer
     * requested on its own still has an ambient `declare module` satisfied
     * from inside its program.
     *
     * A fallback program emits only the files of its own group. It can
     * reach a file of another group through an import, but that file is
     * emitted by its own group's program, which holds its whole group under
     * the options of the config beside it; otherwise which program emitted
     * it would follow the order the groups are built in and the imports of
     * files no read of its own records.
     */
    #scanFallback(root, remaining, parsedConfigs, request, tally) {
        request.owner = undefined;
        const members = request.fallbackMembers;
        const groups = fallbackGroups(
            root,
            remaining,
            parsedConfigs,
            request.packageDirectories,
        );
        request.fallbackOrphaned = parsedConfigs.length > 0;
        request.fallbackGroupOf = new Map();
        for (const source of [groups, members]) {
            for (const [directory, group] of source) {
                for (const relative of group.files) {
                    request.fallbackGroupOf.set(normalize(relative), directory);
                }
            }
        }
        for (const [directory, group] of groups) {
            // Sorted, so every batch of a request hands the compiler the same
            // root list and the program built for the first is reused by the
            // rest instead of being rebuilt for each.
            const files = [
                ...new Set([
                    ...group.files,
                    ...(members.get(directory)?.files ?? []),
                ]),
            ].sort((left, right) => (left < right ? -1 : left > right ? 1 : 0));
            request.program = fallbackProgramKey(root, directory);
            request.fallbackDirectory = directory;
            tally(
                this.#scanProgram(
                    `${directory}${FALLBACK_KEY}`,
                    programConfig(
                        request,
                        directory,
                        fallbackConfig(root, files, group.parsed, directory),
                    ),
                    request,
                ),
            );
        }
    }

    /**
     * Build one program and emit the requested files it covers.
     *
     * The compiler recurses once per import while it builds a program, so an
     * import chain deeper than the thread's stack throws a RangeError out of
     * `createProgram` (or out of the checker walking the same chain). That
     * costs the files of this one program, not the whole request: each gets a
     * facts-free contribution saying why, and the remaining configs and the
     * fallback still run. Whatever reads were recorded before the overflow stay
     * recorded. Any other error out of the build or the checker is contained
     * the same way, under TS_PROGRAM_FAILED, so one program the compiler cannot
     * handle never discards the facts of the others.
     *
     * @param {ScanRequest} request
     * @returns {{reused: boolean}|undefined} undefined when the program failed
     */
    #scanProgram(key, parsed, request) {
        const { root, maxFileBytes, reads, emitted, emit, exclusions } =
            request;
        this.programCache.reserve(key);
        const oldProgram = this.programCache.get(key);
        let program;
        try {
            program = createRestrictedProgram(
                root,
                parsed,
                oldProgram,
                maxFileBytes,
                reads,
                exclusions,
            );
            this.programCache.store(key, program);
            recordUnreadSourceFiles(root, program, reads, maxFileBytes);
            this.#emitProgram(
                program,
                request,
                key.endsWith(FALLBACK_KEY),
                parsed.bundlerDiagnostics ?? [],
            );
            request.attribution.program(program, request.program, (relative) =>
                request.ownedByProgram(relative, request.program),
            );
            request.attribution.callArguments(
                request.program,
                callArgumentPairs(
                    program,
                    root,
                    exclusions,
                    programMembers(request, key.endsWith(FALLBACK_KEY)),
                ),
            );
        } catch (error) {
            const overflowed = isStackOverflow(error);
            const covered = [
                ...parsed.fileNames,
                ...(program?.getSourceFiles() ?? []).map(
                    (file) => file.fileName,
                ),
            ];
            // The same files the program would have emitted: one another
            // config lists, or one of another fallback group, is answered
            // by its own program, which this failure says nothing about.
            const fallback = key.endsWith(FALLBACK_KEY);
            const skipped = emissionSkipped(
                request,
                fallback ? new Map() : request.owners,
                fallback,
            );
            for (const fileName of covered) {
                const relative = relativeInside(root, fileName);
                if (skipped(relative)) continue;
                emit(
                    overflowed
                        ? factFreeContribution(
                              relative,
                              "TS_PROGRAM_TOO_DEEP",
                              "The TypeScript compiler exceeded its stack building the program for this file's configuration (for example, an import chain too deep to follow), so its facts are omitted.",
                          )
                        : factFreeContribution(
                              relative,
                              "TS_PROGRAM_FAILED",
                              `The TypeScript compiler failed building or checking the program for this file's configuration, so its facts are omitted: ${error instanceof Error ? error.message : String(error)}`,
                          ),
                );
                emitted.add(relative);
            }
            return undefined;
        }
        return { reused: oldProgram !== undefined };
    }

    /** @param {ScanRequest} request */
    #emitProgram(program, request, fallback, configDiagnostics) {
        const { root, emitted, emit, maxFileBytes, owner } = request;
        // The fallback program emits whatever no config's program did, a
        // file some config lists included, so it is given no owners to defer
        // to; what it leaves to another program is a file of another group.
        // The request's own map is left as the configs filled it.
        const owners = fallback ? new Map() : request.owners;
        const tracker = declarationTracker(program.getTypeChecker());
        const skipped = emissionSkipped(request, owners, fallback);
        const belongs = programMembers(request, fallback);
        const { byFile: diagnosticsByFile, programLevel: compilerLevel } =
            programDiagnostics(
                program,
                root,
                maxFileBytes,
                fallback,
                fallback && request.fallbackOrphaned === true,
            );
        // A bundler config the program's options were read from counts as
        // its configuration: what could not be read of it applies to all.
        const programLevel = [...compilerLevel, ...configDiagnostics];

        // A diagnostic that names no file describes the whole program, so it
        // is reported once, on one fixed file of the program. The carrier is
        // chosen from the program itself, never from the request: a project is
        // sent in batches and an incremental scan sends only changed files, so
        // a carrier taken from each request repeats the diagnostic once per
        // batch and keeps every copy in the graph. Only a request that names
        // the carrier reports it.
        const carrier = programWideCarrier(program, root, owner, owners);
        const programWide = (relative) =>
            relative === carrier ? anchoredAt(programLevel, relative) : [];
        for (const sourceFile of program.getSourceFiles()) {
            const relative = relativeInside(root, sourceFile.fileName);
            if (skipped(relative)) continue;

            const redirect = sourceFile.redirectInfo;
            if (redirect !== undefined && !redirectReadsAgree(redirect)) {
                emit(
                    factFreeContribution(
                        relative,
                        "TS_REDIRECTED_SOURCE_UNVERIFIED",
                        "TypeScript resolved this file as a duplicate of another copy of the same package whose bytes differ, so its facts would describe the other copy.",
                    ),
                );
                emitted.add(relative);
                continue;
            }

            const probes = [];
            tracker.files = new Set();
            const contribution = collectFile(
                request,
                program,
                sourceFile,
                { tracker, probes, belongs },
                {
                    own: diagnosticsByFile.get(relative) ?? [],
                    programWide: programWide(relative),
                },
            );
            // Every SourceFile the restricted host creates has an entry. One
            // without would reach the core with facts but no hash, which it
            // refuses as a contract violation instead of trusting the read.
            // A redirect is a view of its target; with the reads agreeing, its
            // own bytes are what the facts describe.
            const contentHash = parsedContentHashes.get(
                redirect?.unredirected ?? sourceFile,
            );
            if (contentHash !== undefined) {
                contribution.content_hash = contentHash;
            }
            contribution.program = request.program;
            // A file no config lists is emitted by whichever program reaches
            // it first, so its facts follow other files' imports, which no
            // read of its own records; the core rebuilds it whenever a
            // program a config describes was rebuilt.
            if (!owners.has(relative)) contribution.listed = false;
            request.attribution.requested(
                program,
                sourceFile,
                relative,
                tracker.files,
                probes,
                request.program,
            );
            emit(contribution);
            emitted.add(relative);
        }
    }
}

/** How a fallback program's cache key ends: no tsconfig includes its files. */
const FALLBACK_KEY = "\0<fallback>";

/**
 * Whether a redirect SourceFile's facts provably come from its own path's bytes.
 *
 * TypeScript loads one copy of a package name@version and makes every further
 * copy a redirect to it, so a duplicate path's facts are computed from the
 * first copy. That is only a fact about the duplicate when the host read both
 * and the two reads hashed the same. Attaching the target's hash instead would
 * fail verification on every scan of a project whose copies differ.
 */
function redirectReadsAgree(redirect) {
    const own =
        redirect.unredirected === undefined
            ? undefined
            : parsedContentHashes.get(redirect.unredirected);
    const target = parsedContentHashes.get(redirect.redirectTarget);
    return own !== undefined && own === target;
}

// A contribution that carries nothing but the reason one file was skipped.
function unscannableContribution(relative, message) {
    return factFreeContribution(relative, "TS_UNSCANNABLE_FILE", message);
}

// A contribution with no facts, only a diagnostic saying why.
function factFreeContribution(relative, code, message) {
    return {
        owner_key: `${OWNER_KEY_PREFIX}${relative}`,
        nodes: [],
        edges: [],
        diagnostics: [
            {
                severity: "error",
                code,
                message,
                evidence: { path: relative, start_line: 1, end_line: 1 },
            },
        ],
    };
}
