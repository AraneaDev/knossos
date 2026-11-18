/**
 * The complete list of names a module exports or a class or interface offers,
 * read through the checker, for the core to judge a test double against.
 *
 * A list is only worth sending when it is complete, so each reader also says
 * when it could not be: an `export *` from a module the program cannot
 * resolve, an `export =`, a base type the checker could not read, or an index
 * signature that makes any name a member. An over-long list is not sent.
 */

import ts from "typescript";

/** Names listed at most; a longer list is left out and the core stays silent. */
export const MAX_NAMES = 1000;

/** @returns {string[]} the names, sorted and without repeats */
function sorted(names) {
    return [...new Set(names)].sort();
}

/**
 * Every name a module exports: declarations, `export const`, aliases,
 * `export default` (as "default") and what `export *` re-exports.
 *
 * @returns {{exports: string[], open: boolean} | null} null when the file is
 *   not an ES module, or lists too many names
 */
export function moduleExports(checker, sourceFile) {
    const symbol = checker.getSymbolAtLocation(sourceFile);
    if (symbol === undefined) return null;
    let open = false;
    for (const statement of sourceFile.statements) {
        if (ts.isExportAssignment(statement) && statement.isExportEquals) {
            open = true;
        } else if (
            ts.isExportDeclaration(statement) &&
            statement.exportClause === undefined &&
            statement.moduleSpecifier !== undefined &&
            checker.getSymbolAtLocation(statement.moduleSpecifier) === undefined
        ) {
            open = true;
        }
    }
    const names = sorted(
        checker
            .getExportsOfModule(symbol)
            .map((exported) => exported.name)
            .filter((name) => name !== "export="),
    );
    return names.length > MAX_NAMES ? null : { exports: names, open };
}

/** Whether a heritage clause's type is one the checker could not read. */
function unreadableHeritage(checker, node) {
    return (node.heritageClauses ?? []).some((clause) =>
        clause.types.some(
            (type) =>
                (checker.getTypeAtLocation(type.expression).flags &
                    ts.TypeFlags.Any) !==
                0,
        ),
    );
}

/**
 * Every name a class or interface declares or inherits, on its instances and,
 * for a class, on the constructor (static members).
 *
 * @returns {{members: string[], open: boolean} | null} null when the
 *   declaration has no symbol, or lists too many names
 */
export function typeMembers(checker, node) {
    const symbol =
        node.name === undefined
            ? undefined
            : checker.getSymbolAtLocation(node.name);
    if (symbol === undefined) return null;
    const instance = checker.getDeclaredTypeOfSymbol(symbol);
    const names = checker.getPropertiesOfType(instance).map((p) => p.name);
    let open =
        checker.getIndexInfosOfType(instance).length > 0 ||
        unreadableHeritage(checker, node) ||
        checker
            .getBaseTypes(instance)
            .some((base) => (base.flags & ts.TypeFlags.Any) !== 0);
    if (ts.isClassDeclaration(node)) {
        const constructorType = checker.getTypeOfSymbolAtLocation(symbol, node);
        for (const property of checker.getPropertiesOfType(constructorType)) {
            if (property.name !== "prototype") names.push(property.name);
        }
        open = open || checker.getIndexInfosOfType(constructorType).length > 0;
    }
    const list = sorted(names);
    return list.length > MAX_NAMES ? null : { members: list, open };
}
