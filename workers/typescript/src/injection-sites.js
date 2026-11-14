/**
 * Where a class says what it is given: the types of its constructor's
 * parameters, and the classes it asks Angular's `inject()` for.
 */

import ts from "typescript";

const ANGULAR_CORE = "@angular/core";

/**
 * Whether a call's callee is the `inject` that `@angular/core` exports, under
 * whatever local name the file imports it. Decided from the import as
 * written, so it holds in a checkout whose dependencies are not installed.
 */
function isAngularInject(call, checker) {
    if (!ts.isIdentifier(call.expression)) return false;
    const declaration = checker
        .getSymbolAtLocation(call.expression)
        ?.declarations?.find((item) => ts.isImportSpecifier(item));
    if (declaration === undefined) return false;
    const specifier = declaration.parent.parent.parent.moduleSpecifier;
    return (
        (declaration.propertyName ?? declaration.name).text === "inject" &&
        ts.isStringLiteral(specifier) &&
        specifier.text === ANGULAR_CORE
    );
}

/** Every `inject(X)` call below `node` that is not inside a nested function or class. */
function injectCalls(node, checker, found = []) {
    ts.forEachChild(node, (child) => {
        if (ts.isFunctionLike(child) || ts.isClassLike(child)) return;
        if (
            ts.isCallExpression(child) &&
            child.arguments.length > 0 &&
            isAngularInject(child, checker)
        )
            found.push(child);
        injectCalls(child, checker, found);
    });
    return found;
}

/**
 * Whether what `inject()` is asked for can be a class: an identifier or a
 * qualified name that does not resolve to something else. A token that is a
 * constant (`inject(API_URL)`) names a value, not a dependency on a class.
 */
function mayBeAClass(token, checker) {
    if (!ts.isIdentifier(token) && !ts.isPropertyAccessExpression(token))
        return false;
    let symbol = checker.getSymbolAtLocation(token);
    if (symbol !== undefined && symbol.flags & ts.SymbolFlags.Alias) {
        try {
            symbol = checker.getAliasedSymbol(symbol);
        } catch {
            return true;
        }
    }
    const declarations = symbol?.declarations ?? [];
    return (
        declarations.length === 0 ||
        declarations.some(
            (item) => ts.isClassLike(item) || ts.isInterfaceDeclaration(item),
        )
    );
}

/**
 * What a class or interface is injected with, in source order: `type` for a
 * constructor parameter's annotation, `token` for the argument of an Angular
 * `inject()` call in a property initialiser or directly in the constructor's
 * body. `at` is the node the fact is evidenced by.
 *
 * @returns {Array<{type?: ts.TypeNode, token?: ts.Expression, at: ts.Node}>}
 */
export function injectionSites(node, checker) {
    const sites = [];
    for (const member of node.members ?? []) {
        if (ts.isConstructorDeclaration(member)) {
            for (const parameter of member.parameters) {
                if (parameter.type)
                    sites.push({ type: parameter.type, at: parameter });
            }
        }
        const scope = ts.isConstructorDeclaration(member)
            ? member.body
            : ts.isPropertyDeclaration(member)
              ? member
              : undefined;
        if (scope === undefined) continue;
        for (const call of injectCalls(scope, checker)) {
            if (mayBeAClass(call.arguments[0], checker))
                sites.push({ token: call.arguments[0], at: call });
        }
    }
    return sites;
}
