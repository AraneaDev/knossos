/**
 * Which program describes each file of a scan request: the config whose own
 * file list includes it, or else the fallback program of the group it sits
 * in, under the resolution options of the config beside that group.
 */

import path from "node:path";
import ts from "typescript";
import { normalize, offeredPath, relativeInside } from "./project-paths.js";

/**
 * Which config describes each file: the first whose own file list includes it.
 *
 * A program reaches far more than that (a solution config's references, an
 * import across a package boundary), and a file emitted by whichever program
 * reached it first was checked under options its project never uses.
 */
export function configOwners(root, parsedConfigs) {
    const owners = new Map();
    for (const [configPath, parsed] of parsedConfigs) {
        for (const fileName of parsed.ownFileNames ?? parsed.fileNames) {
            const relative = relativeInside(root, fileName);
            if (relative !== null && !owners.has(relative))
                owners.set(relative, configPath);
        }
    }
    return owners;
}

/**
 * Files no config's program emitted, grouped by the package they sit in: the
 * nearest config's directory, or the nearest package.json's where that is
 * nearer (a nested package with no tsconfig of its own), else the root. A
 * group takes a config's options only from a config at or below its package;
 * a nested package is not governed by a parent's tsconfig.
 *
 * @returns {Map<string, {files: string[], parsed: object | undefined}>} directory => group
 */
export function fallbackGroups(
    root,
    remaining,
    parsedConfigs,
    packageDirectories,
) {
    const configs = parsedConfigs.map(([configPath, parsed]) => ({
        directory: path.dirname(path.join(root, configPath)),
        parsed,
    }));
    const packages = packageDirectories.map((directory) =>
        path.join(root, directory),
    );
    const nearest = (directories, absolute) =>
        directories
            .filter((directory) => absolute.startsWith(directory + path.sep))
            .sort((a, b) => b.length - a.length)[0];
    const groups = new Map();
    for (const relative of remaining) {
        const absolute = path.join(root, relative);
        const configDirectory = nearest(
            configs.map(({ directory }) => directory),
            absolute,
        );
        const packageDirectory = nearest(packages, absolute);
        const governed =
            configDirectory !== undefined &&
            (packageDirectory === undefined ||
                configDirectory.length >= packageDirectory.length);
        const directory = governed
            ? configDirectory
            : (packageDirectory ?? root);
        const group = groups.get(directory) ?? {
            files: [],
            parsed: governed
                ? configs.find((config) => config.directory === directory)
                      ?.parsed
                : undefined,
        };
        group.files.push(relative);
        groups.set(directory, group);
    }
    return groups;
}

// What a file outside a config's `include` takes from it: how to resolve
// and parse, as its test runner or bundler reads it. Not how strictly to
// check, which the config applies to its own files only, and not its build
// layout (`rootDir`, `composite`), which such a file would break. Nor its
// `module`/`moduleResolution`: a test runner resolves as a bundler does,
// and a legacy `node` resolution loses the package `imports` field and, under
// the bundled compiler, the `@types` a test reads (`Buffer`, `process`). Nor
// the environment (`lib`, `target`, `types`): a test runs in its runner's,
// not in the one the config describes for its own files.
const RESOLUTION_OPTIONS = [
    "baseUrl",
    // A deprecated option inherited above is reported again for the fallback
    // unless the config's own remedy for that deprecation comes with it.
    "ignoreDeprecations",
    "paths",
    // Where `paths` resolve from when no `baseUrl` is set: the config's own
    // directory, which TypeScript records here and nowhere else.
    "pathsBasePath",
    "moduleSuffixes",
    "customConditions",
    "resolvePackageJsonExports",
    "resolvePackageJsonImports",
    "resolveJsonModule",
    "allowImportingTsExtensions",
    "allowArbitraryExtensions",
    "esModuleInterop",
    "allowSyntheticDefaultImports",
    "jsx",
    "jsxFactory",
    "jsxFragmentFactory",
    "jsxImportSource",
    "experimentalDecorators",
    "emitDecoratorMetadata",
    "useDefineForClassFields",
];

const FALLBACK_OPTIONS = {
    allowJs: true,
    checkJs: false,
    noEmit: true,
    target: ts.ScriptTarget.Latest,
    module: ts.ModuleKind.ESNext,
    moduleResolution: ts.ModuleResolutionKind.Bundler,
    jsx: ts.JsxEmit.Preserve,
};

/**
 * The options and root files of a fallback program: the files no config's
 * program emitted, under the resolution options of the config beside them.
 */
export function fallbackConfig(root, remaining, config, directory = root) {
    const configOptions = config?.options;
    const inherited = {};
    for (const option of RESOLUTION_OPTIONS)
        if (configOptions?.[option] !== undefined)
            inherited[option] = configOptions[option];
    return {
        options: {
            ...FALLBACK_OPTIONS,
            ...inherited,
            // Where installed types are looked up from: the group's own
            // package, not the directory the worker runs in.
            configFilePath: path.join(directory, "tsconfig.json"),
        },
        // An extensionless script, or one whose extension is not in lower
        // case, only ever reaches the fallback program: no tsconfig `include`
        // matches either name.
        fileNames: remaining.map((relative) =>
            offeredPath(path.join(root, relative)),
        ),
        // A referenced project's outputs stand for its sources here as in
        // the config's own program (`#shared/*` naming its `dist`).
        projectReferences: config?.projectReferences,
    };
}

/**
 * Every discovered file no config lists, the project's own declaration files
 * among them, grouped as the fallback reads them: the whole group is a
 * fallback program's root list.
 *
 * @returns {Map<string, {files: string[], parsed: object | undefined}>} directory => group
 */
export function fallbackMembers(
    root,
    sourceFiles,
    owners,
    parsedConfigs,
    packageDirectories,
) {
    return fallbackGroups(
        root,
        sourceFiles.filter((relative) => !owners.has(normalize(relative))),
        parsedConfigs,
        packageDirectories,
    );
}

/** The program key of the fallback program for a group's directory. */
export function fallbackProgramKey(root, directory) {
    return `fallback:${relativeInside(root, directory) || "."}`;
}
