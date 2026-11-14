/**
 * An import of another config's build output, read as the source that output
 * is built from.
 */

import ts from "typescript";

const BUILD_SOURCE_EXTENSIONS = new Map([
    [".ts", ts.Extension.Ts],
    [".tsx", ts.Extension.Tsx],
    [".mts", ts.Extension.Mts],
    [".cts", ts.Extension.Cts],
]);

/**
 * The source a location in a known `outDir` is built from, as a resolution
 * carrying `result`'s failed lookups; undefined when the location lies in no
 * output directory or the source is not there.
 */
function sourceAt(location, result, outputs, host) {
    // Every output directory holding it, the nearest first: a config
    // emitting to `dist/shared` beside one emitting to `dist`.
    for (const output of outputs) {
        if (!location.startsWith(output.outDir + "/")) continue;
        const stem = location
            .slice(output.outDir.length + 1)
            .replace(/\.(?:d\.)?[cm]?[jt]sx?$/, "");
        for (const [suffix, extension] of BUILD_SOURCE_EXTENSIONS) {
            const candidate = `${output.rootDir}/${stem}${suffix}`;
            if (host.fileExists(candidate))
                return {
                    resolvedModule: {
                        resolvedFileName: candidate,
                        extension,
                        isExternalLibraryImport: false,
                    },
                    failedLookupLocations: result.failedLookupLocations,
                };
        }
    }
    return undefined;
}

/**
 * For one program's resolver: a module that resolved to, or only failed to
 * find, another config's build output, which is never read (see
 * allowedCompilerPath), as the source that output is built from. `#shared/x`
 * naming `dist/shared/x.js` is that config's `x.ts`, the file its build keeps
 * the output in step with. The returned function answers undefined when no
 * failed lookup lies in a known `outDir` or the source is not there.
 *
 * The compiler shares one list of failed lookups between every file importing
 * the same module and appends to it, so the list is as long as the number of
 * importing directories and is handed over once per import. The lookups
 * already found to name no source are not checked again: only what was
 * appended since. What is remembered lives as long as this resolver, one
 * program's build over one host, so a file that appears later is seen by the
 * next build.
 *
 * @param {Array<{outDir: string, rootDir: string}>} outputs
 * @param {{fileExists: (file: string) => boolean}} host
 */
export function buildOutputSources(outputs, host) {
    // A failed lookup list to how many of its leading entries name no source.
    const checked = new WeakMap();
    return (result) => {
        if (outputs.length === 0) return undefined;
        if (result.resolvedModule !== undefined) {
            const found = sourceAt(
                result.resolvedModule.resolvedFileName,
                result,
                outputs,
                host,
            );
            if (found !== undefined) return found;
        }
        const failed = result.failedLookupLocations;
        if (failed === undefined) return undefined;
        let found;
        let index = checked.get(failed) ?? 0;
        for (; index < failed.length; index++) {
            found = sourceAt(failed[index], result, outputs, host);
            if (found !== undefined) break;
        }
        // Everything before `index` names no source; a lookup that does is
        // answered again the next time the list is handed over.
        checked.set(failed, index);
        return found;
    };
}
