/**
 * Module resolution for a restricted program: the compiler's own, with an
 * import of build output read as its source and an import of a component
 * landing on the component.
 */

import path from "node:path";
import ts from "typescript";
import { buildOutputSources } from "./build-output-sources.js";
import { componentAliasSuffix, componentDialect } from "./component-source.js";
import { COMPONENT_ALIAS_MARK, isRegularFile } from "./project-paths.js";

/**
 * Module resolution as the compiler does it, with two corrections for
 * components.
 *
 * An import that names a component (`./Card.vue`) means the component even
 * when a real `Card.vue.ts` sits beside it, which the compiler would try
 * first. And a relative or path-mapped specifier with no extension that
 * resolves to nothing is retried with each of `retried`: `.vue` in a Vue
 * project (from the manifests), as webpack and Vue CLI list it in
 * `resolve.extensions`, and any component extension a bundler config lists
 * there itself. The retry probes paths that are then recorded, so it stays
 * off elsewhere. A bare specifier nothing else resolves is then tried under
 * each of `lateRoots`, the module roots webpack lists after node_modules. The resolution mode follows a project reference's own
 * options, as the compiler's default loader does.
 */
export function componentResolver(
    host,
    cache,
    retried,
    lateRoots,
    outputs,
    readable,
) {
    const sourceOfBuildOutput = buildOutputSources(outputs, host);
    return (
        literals,
        containingFile,
        redirectedReference,
        compilerOptions,
        containingSourceFile,
    ) =>
        literals.map((literal) => {
            const resolve = (name) =>
                ts.resolveModuleName(
                    name,
                    containingFile,
                    compilerOptions,
                    host,
                    cache,
                    redirectedReference,
                    ts.getModeForUsageLocation(
                        containingSourceFile,
                        literal,
                        redirectedReference?.commandLine.options ??
                            compilerOptions,
                    ),
                );
            // The specifier as the compiler resolves it, then retried with
            // each extension of `retried` when it names no extension.
            const settle = (name) => {
                const direct = resolve(name);
                // Resolved to build output is resolved to nothing: it is
                // never read (see allowedCompilerPath).
                const resolved = componentTarget(
                    name,
                    direct.resolvedModule === undefined ||
                        !readable(direct.resolvedModule.resolvedFileName)
                        ? (sourceOfBuildOutput(direct) ?? direct)
                        : direct,
                );
                if (
                    retried.length === 0 ||
                    resolved.resolvedModule !== undefined ||
                    !name.includes("/") ||
                    path.posix.extname(name) !== ""
                )
                    return resolved;
                // Every attempt probed, and a file at any of those paths
                // would change the answer, so the answer carries every miss.
                let missed = resolved;
                for (const extension of retried) {
                    const component = resolve(`${name}${extension}`);
                    if (component.resolvedModule !== undefined)
                        return withFailedLookups(component, missed);
                    missed = withFailedLookups(missed, component);
                }
                return missed;
            };
            const settled = settle(literal.text);
            // A bare specifier no package answers, under each root webpack
            // tries after node_modules.
            if (
                settled.resolvedModule !== undefined ||
                lateRoots.length === 0 ||
                /^\.{0,2}\//.test(literal.text) ||
                path.isAbsolute(literal.text)
            )
                return settled;
            let missed = settled;
            for (const root of lateRoots) {
                const attempt = settle(`${root}/${literal.text}`);
                if (attempt.resolvedModule !== undefined)
                    return withFailedLookups(attempt, missed);
                missed = withFailedLookups(missed, attempt);
            }
            return missed;
        });
}

/** A resolution that also names another attempt's failed lookups. */
function withFailedLookups(resolution, attempt) {
    return {
        ...resolution,
        failedLookupLocations: [
            ...(resolution.failedLookupLocations ?? []),
            ...(attempt.failedLookupLocations ?? []),
        ],
    };
}

/**
 * A resolution of a component specifier that landed on a real `X.vue.ts`
 * beside `X.vue`, redirected to the component's own alias; anything else as
 * it is.
 */
function componentTarget(specifier, resolved) {
    const dialect = componentDialect(specifier);
    const file = resolved.resolvedModule?.resolvedFileName;
    if (dialect === null || file === undefined) return resolved;
    const suffix = componentAliasSuffix(dialect);
    const component = file.slice(0, -suffix.length);
    if (
        !file.endsWith(suffix) ||
        componentDialect(component) !== dialect ||
        !isRegularFile(file) ||
        !isRegularFile(component)
    )
        return resolved;
    return {
        ...resolved,
        resolvedModule: {
            ...resolved.resolvedModule,
            resolvedFileName: `${component}${COMPONENT_ALIAS_MARK}${suffix}`,
        },
    };
}
