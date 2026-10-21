/**
 * The compiler host and options a program is built with.
 *
 * `parseConfig` parses a tsconfig through recorded reads, and
 * `programConfig` adds the project's compiler defaults and the aliases its
 * bundler declares. `createRestrictedProgram` builds the program over a
 * host that reads only what the project rules allow and records every read,
 * resolving an import of build output to its source and an import of a
 * component to the component.
 */

import path from "node:path";
import ts from "typescript";
import { inVueProject, withBundlerAliases } from "./bundler-aliases.js";
import { exceedsByteCap } from "./byte-caps.js";
import { componentResolver } from "./component-resolution.js";
import { rethrowStackOverflow } from "./errors.js";
import { probeRecorded, recordProbe, recordRefused } from "./input-reads.js";
import {
    allowedCompilerPath,
    COMPONENT_ALIAS,
    contains,
    defaultLibDirectory,
    excludedByProjectLayoutPath,
    normalize,
    offeredComponentPath,
    realpathNative,
    realSourcePath,
    relativeInside,
    SHEBANG_ALIAS_SUFFIX,
    validatedInside,
    walkPath,
} from "./project-paths.js";
import { readHashedSourceFile, readRecorded } from "./source-reading.js";

// Lets a tsconfig `include` match components, so they are checked under the
// options of the project that holds them.
const COMPONENT_FILE_EXTENSIONS = [".vue", ".svelte", ".astro"].map(
    (extension) => ({
        extension,
        isMixedContent: false,
        scriptKind: ts.ScriptKind.Deferred,
    }),
);

export function parseConfig(root, configPath, reads, exclusions) {
    const absolute = validatedInside(root, configPath);
    const host = {
        ...ts.sys,
        // A config and every config it extends or references decides the
        // program's files and options, so each read is recorded under its
        // walk's keys like any other: the hash of the raw bytes read, or null
        // for a read that failed. Discovery hashes a tsconfig as a project
        // unit, so the core checks these. Configs were never held to the
        // per-file source cap, and still are not: a config discovery skipped
        // as too large is not a unit, so its key is ignored.
        readFile: (file) =>
            allowedCompilerPath(root, file, exclusions)
                ? readRecorded(root, file, reads, Number.MAX_SAFE_INTEGER)
                : undefined,
        // An `extends` target is probed before it is read, and a probe that
        // finds nothing decides the options as surely as a read.
        fileExists: (file) =>
            allowedCompilerPath(root, file, exclusions) &&
            probeRecorded(
                reads,
                root,
                file,
                Number.MAX_SAFE_INTEGER,
                exclusions,
            ),
        readDirectory: (directory, extensions, excludes, includes, depth) => {
            if (!allowedCompilerPath(root, directory, exclusions)) return [];
            return ts.sys
                .readDirectory(directory, extensions, excludes, includes, depth)
                .filter((file) => allowedCompilerPath(root, file, exclusions));
        },
        onUnRecoverableConfigFileDiagnostic: () => {},
    };
    // A jsconfig.json defaults maxNodeModuleJsDepth to 2, which would read
    // the JavaScript of dependencies; the graph holds the project's own code.
    const parsed = ts.getParsedCommandLineOfConfigFile(
        absolute,
        { noEmit: true, maxNodeModuleJsDepth: 0 },
        host,
        undefined,
        undefined,
        COMPONENT_FILE_EXTENSIONS,
    );
    if (!parsed)
        throw new Error(`Unable to parse TypeScript config: ${configPath}`);
    const fileNames = new Set(
        parsed.fileNames.filter((file) =>
            allowedCompilerPath(root, file, exclusions),
        ),
    );
    // The files the config lists itself, before its references are merged
    // in: what decides which config describes a file (see scan()).
    const ownFileNames = [...fileNames];
    const pending = [...(parsed.projectReferences ?? [])];
    const visited = new Set([absolute]);
    // Where each referenced config emits, which outputSources() maps back.
    const referencedOutputs = [];
    while (pending.length > 0) {
        const reference = pending.pop();
        const referenceConfig = normalize(
            ts.resolveProjectReferencePath(reference),
        );
        if (
            !allowedCompilerPath(root, referenceConfig, exclusions) ||
            visited.has(referenceConfig)
        )
            continue;
        visited.add(referenceConfig);
        const referenced = ts.getParsedCommandLineOfConfigFile(
            referenceConfig,
            { noEmit: true, maxNodeModuleJsDepth: 0 },
            host,
            undefined,
            undefined,
            COMPONENT_FILE_EXTENSIONS,
        );
        if (!referenced) continue;
        for (const file of referenced.fileNames) {
            if (allowedCompilerPath(root, file, exclusions))
                fileNames.add(file);
        }
        referencedOutputs.push([referenceConfig, referenced.options]);
        pending.push(...(referenced.projectReferences ?? []));
    }
    parsed.fileNames = [...fileNames].map((file) => offeredComponentPath(file));
    parsed.ownFileNames = ownFileNames.map((file) =>
        offeredComponentPath(file),
    );
    parsed.referencedOutputs = referencedOutputs;
    // References are kept, and the host resolves a reference's build output
    // back to its source (see createRestrictedProgram), so an import of
    // `../lib/dist/index.js` or a package.json `imports` alias onto it lands
    // on lib/src without the project ever having been built.
    return parsed;
}

// The defaults TypeScript 5.x applied to options a config leaves unset, where
// 6.0 changed them: every `@types` package rather than none, non-strict, and no
// check on side-effect imports.
const TYPESCRIPT_5_DEFAULTS = Object.freeze({
    types: ["*"],
    strict: false,
    noUncheckedSideEffectImports: false,
});

/**
 * The parsed config with the defaults of the project's own TypeScript under it.
 *
 * The worker bundles 6.x. A project built with 5.x never opted into 6.0's new
 * defaults, and checking it under them reported every `process`, `Buffer` and
 * implicit `any` its own `tsc` accepts. An option the config sets explicitly
 * still wins, and a project that names no TypeScript keeps the bundled defaults.
 *
 * `versions` maps a manifest's directory (`""` for the root) to the major
 * version its `typescript` dependency declares. The core reads those from the
 * manifests discovery already hashed, so deciding this reads nothing here.
 * The nearest manifest at or above the config's directory answers.
 */
function withProjectCompilerDefaults(root, directory, parsed, versions) {
    const major = projectTypeScriptMajor(root, directory, versions);
    if (major === null || major >= 6) return parsed;
    return {
        ...parsed,
        options: { ...TYPESCRIPT_5_DEFAULTS, ...parsed.options },
    };
}

/**
 * The parsed config a program is built from, for the project at `directory`:
 * see the two functions it applies.
 */
export function programConfig(request, directory, parsed) {
    const { root, versions } = request;
    return {
        ...withBundlerAliases(
            request,
            directory,
            withProjectCompilerDefaults(root, directory, parsed, versions),
        ),
        vueProject: inVueProject(root, directory, request.vueProjects),
        outputSources: request.outputSources ?? [],
    };
}

function projectTypeScriptMajor(root, directory, versions) {
    if (versions === null || typeof versions !== "object") return null;
    let relative = relativeInside(root, directory);
    while (relative !== null) {
        const key = relative === "." ? "" : relative;
        const major = versions[key];
        if (Number.isInteger(major)) return major;
        if (key === "") return null;
        const parent = path.posix.dirname(key);
        relative = parent === "." ? "" : parent;
    }
    return null;
}

/**
 * The program's files, with the installed `svelte` package's declarations
 * when any of them is a Svelte component. The runes (`$state`, `$derived`,
 * `$props`) are globals that package declares, and svelte-check gives every
 * component those declarations; without them each rune returned `any`, and
 * every callback on what it returned reported an implicitly `any` parameter.
 * Resolved as the components would import `svelte`, so `paths` and the
 * package's own `types` entry decide the file, and nothing is added when it
 * is not installed.
 */
function withSvelteRunes(fileNames, options, host) {
    // Offered under their `X.svelte.ts` alias (see COMPONENT_ALIAS).
    const component = fileNames.find(
        (name) => COMPONENT_ALIAS.exec(name)?.[1].toLowerCase() === "svelte",
    );
    if (component === undefined) return fileNames;
    const resolved = ts.resolveModuleName(
        "svelte",
        component,
        options,
        host,
    ).resolvedModule;
    return resolved?.extension === ts.Extension.Dts &&
        !fileNames.includes(resolved.resolvedFileName)
        ? [...fileNames, resolved.resolvedFileName]
        : fileNames;
}

export function createRestrictedProgram(
    root,
    parsed,
    oldProgram,
    maxFileBytes,
    reads,
    exclusions,
) {
    // Architecture scanning only needs diagnostics for the project's own
    // sources, not for the internals of declaration files. Type-checking the
    // full .d.ts closure of heavy dependencies (e.g. vitest, @types/node pulled
    // in by test files) dominates both time and memory and can exhaust the
    // worker heap on real projects. skipLibCheck/skipDefaultLibCheck skip only
    // the .d.ts-internal checks; diagnostics reported on the user's .ts files
    // are unchanged. Measured on a 94-file target: OOM (>512MB) -> ~2.9s/292MB.
    const options = {
        ...parsed.options,
        skipLibCheck: true,
        skipDefaultLibCheck: true,
        traceResolution: false,
    };
    const host = ts.createCompilerHost(options, true);
    // Resolution tracing writes through the system host to stdout, which is
    // the frame channel, so a single trace line makes the reply invalid JSON
    // and the whole language is dropped. A referenced project's own options
    // are not overridden above, so the host's trace is silenced as well.
    host.trace = () => {};
    // What an editor does: a referenced project's outputs stand for its
    // sources, so nothing has to be built before it can be analysed.
    host.useSourceOfProjectReferenceRedirect = () => true;
    host.getSourceFile = (fileName, languageVersion) =>
        restrictedGetSourceFile(
            root,
            reads,
            maxFileBytes,
            exclusions,
            fileName,
            languageVersion,
        );
    // Module resolution decides an import's target by these answers, so an
    // in-root answer is recorded (recordProbe).
    host.fileExists = (file) =>
        probeRecorded(
            reads,
            root,
            realSourcePath(file),
            maxFileBytes,
            exclusions,
        );
    host.realpath = (file) =>
        recordingRealpath(host, root, reads, maxFileBytes, file);
    // A stat-then-read (exceedsByteCap followed by ts.sys.readFile) leaves a
    // window between the two where the file can grow past the cap, making
    // the read that follows unbounded. readBounded closes it: it reads at
    // most one byte past the cap itself, so the size actually read is what
    // is checked, not a size observed earlier.
    //
    // Module resolution reads package.json files through here, and their
    // fields decide an import's target, so every in-root read is recorded
    // under its walk's keys like any other: the hash of the bytes read, or null
    // for a read that failed. A path refused here was first answered by
    // host.fileExists, which recorded its walk. Discovery hashes package.json
    // as a project unit, and the core checks the entry against that hash.
    host.readFile = (file) =>
        allowedCompilerPath(root, file, exclusions)
            ? readRecorded(root, file, reads, maxFileBytes)
            : undefined;
    const cache = ts.createModuleResolutionCache(
        host.getCurrentDirectory(),
        host.getCanonicalFileName,
        options,
    );
    // Shared with the compiler's own lookups (type references, package.json
    // formats), so a manifest is read once, not once per cache.
    host.getModuleResolutionCache = () => cache;
    host.resolveModuleNameLiterals = componentResolver(
        host,
        cache,
        [
            ...new Set([
                ...(parsed.vueProject === true ? [".vue"] : []),
                ...(parsed.componentExtensions ?? []),
            ]),
        ],
        parsed.lateModuleRoots ?? [],
        parsed.outputSources ?? [],
        (file) => allowedCompilerPath(root, file, exclusions),
    );
    return ts.createProgram({
        rootNames: withSvelteRunes(parsed.fileNames, options, host),
        options,
        projectReferences: parsed.projectReferences,
        host,
        oldProgram,
    });
}

/** Parse a file for the restricted program, refusing what the scan may not read and recording what it reads. */
function restrictedGetSourceFile(
    root,
    reads,
    maxFileBytes,
    exclusions,
    fileName,
    languageVersion,
) {
    // A refused path is left out of the program, so the facts of every file
    // that imports or includes it are computed as if it did not exist. It
    // is recorded as a failed read under the key a read of it would have
    // gone under (walkPath, inputKeyLocation). A stable layout never trips
    // over that null: discovery reports neither a symlink nor an over-cap
    // file, and the core verifies an undiscovered key at commit, where a
    // null is valid only for a path that is absent, not a regular file, a
    // link, or over the cap, which is what a refusal names. A discovered
    // file that became one mid-scan fails verification against discovery.
    const absolute = normalize(path.resolve(realSourcePath(fileName)));
    reads.observe("load", absolute);
    const refused = () => {
        recordRefused(reads, root, absolute, maxFileBytes);
        return undefined;
    };
    // Refused for where it sits, which no state of the file can change,
    // so there is no read to report. Recording it as a failed read named a
    // readable regular file as unreadable, and the core's commit-time
    // re-read aborted every scan whose source reaches into its own build
    // output, such as a `bin/` script importing `../dist/index.js`.
    if (excludedByProjectLayoutPath(root, absolute, exclusions))
        return undefined;
    if (!allowedCompilerPath(root, fileName, exclusions)) return refused();
    // The per-file byte cap is enforced on requested files, but the program
    // also pulls in import-reachable and included sources. Guard those too so
    // one giant generated file (e.g. a multi-MB bundled `.d.ts`) is never
    // fully parsed, bounding peak memory.
    if (exceedsByteCap(fileName, maxFileBytes)) return refused();
    // Read here rather than through the default host, which reads via
    // ts.sys.readFile and so never exposes the bytes it decoded. A shebang
    // alias is read, and recorded, under the script's real path.
    return readHashedSourceFile(
        root,
        realSourcePath(fileName),
        fileName,
        languageVersion,
        fileName.endsWith(SHEBANG_ALIAS_SUFFIX) ? ts.ScriptKind.JS : undefined,
        reads,
        maxFileBytes,
    );
}

/**
 * Resolve a path for the restricted program, recording the walk through any link on it.
 *
 * Resolution realpaths a package's files before the host is asked for
 * them, so a link on that path is walked here, where its name is still
 * known, rather than lost behind the resolved name getSourceFile sees. The
 * walk follows the resolution: a tree that changed in between gives the
 * walk a location other than the name resolution returned, and that answer
 * is recorded as absent, since no single state of the tree describes it.
 */
function recordingRealpath(host, root, reads, maxFileBytes, file) {
    // An alias exists nowhere on disk: its realpath is the real file's,
    // under the same alias, and the walk is of the real file.
    const original = realSourcePath(normalize(file));
    if (original !== normalize(file))
        return `${host.realpath(original)}${normalize(file).slice(original.length)}`;
    let real;
    try {
        real = realpathNative(file);
    } catch (error) {
        rethrowStackOverflow(error);
        real = normalize(file);
    }
    const absolute = normalize(path.resolve(file));
    if (
        contains(root, absolute) &&
        !contains(defaultLibDirectory(), absolute)
    ) {
        const walked = walkPath(absolute);
        const present =
            (walked.kind === "file" || walked.kind === "directory") &&
            walked.location === real;
        recordProbe(reads, root, walked, present, maxFileBytes);
    }
    return real;
}

/**
 * Where each config in the scan, and each config one references, emits
 * (`outDir`) and what it emits from (`rootDir`, else its own directory).
 */
export function outputSources(root, parsedConfigs) {
    const configs = parsedConfigs.flatMap(([configPath, parsed]) => [
        [path.join(root, configPath), parsed.options],
        ...(parsed.referencedOutputs ?? []),
    ]);
    return configs
        .flatMap(([configFile, options]) =>
            options.outDir === undefined
                ? []
                : [
                      {
                          outDir: normalize(options.outDir),
                          rootDir: normalize(
                              options.rootDir ?? path.dirname(configFile),
                          ),
                      },
                  ],
        )
        .sort((a, b) => b.outDir.length - a.outDir.length);
}
