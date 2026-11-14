/**
 * The name a symbol declared outside the project goes by.
 */

import ts from "typescript";

/**
 * The name of an external symbol, or null when it has none worth a node.
 *
 * An import the compiler cannot resolve (its package is not installed) is an
 * alias of one shared placeholder symbol the compiler calls `unknown`, so
 * every such class, whatever it was, would become the same external
 * `unknown`. It goes by the name the import asks its module for instead:
 * `HttpClient` for `import { HttpClient as Http } from "..."`. A placeholder
 * reached with no import to name it, such as a member of an unresolved
 * namespace import, has no name and is left out rather than merged with
 * everything else that could not be resolved.
 *
 * @param {ts.TypeChecker} checker
 * @param {ts.Symbol | undefined} written the symbol as the source names it
 * @param {ts.Symbol | undefined} resolved that symbol with its alias followed
 * @returns {string | null}
 */
export function externalName(checker, written, resolved) {
    if (resolved !== undefined && checker.isUnknownSymbol(resolved)) {
        const specifier = written?.declarations?.find(
            (item) => ts.isImportSpecifier(item) || ts.isImportClause(item),
        );
        if (specifier === undefined) return null;
        if (ts.isImportClause(specifier)) return specifier.name?.text ?? null;
        return (specifier.propertyName ?? specifier.name).text;
    }
    const name = resolved?.getName();
    return name && !name.startsWith("__") ? name : null;
}
