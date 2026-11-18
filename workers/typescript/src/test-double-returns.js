/**
 * Whether an implementation that declares no return type returns what the
 * member it replaces declares, read from the expressions it returns.
 *
 * The return type the checker infers for such a function widens the literals
 * of the objects and arrays it builds: `() => ({ status: 'open' })` returns
 * `{ status: string }`, which `{ status: 'open' | 'closed' }` refuses. Each
 * returned expression is held to the declared type as written instead, an
 * object literal property by property, so `'open'` meets `'open' | 'closed'`
 * as the compiler would have it with the member's type as context.
 */

import ts from "typescript";
import { staticName } from "./test-double-syntax.js";

/**
 * True, false, or `"unknown"` when a returned expression cannot be held to
 * `declared` (a spread, a computed key, a bare `return`).
 */
export function inferredReturnFits(checker, fn, declared) {
    const expected = isAsync(fn)
        ? (checker.getAwaitedType(declared) ?? declared)
        : declared;
    const returned = returnedExpressions(fn);
    if (returned === undefined) return "unknown";
    // A body that returns nothing returns `undefined`, as its inferred
    // `void` says; the checker answers that one.
    if (returned.length === 0) return null;
    return all(
        returned.map((expression) => fits(checker, expression, expected)),
    );
}

function isAsync(fn) {
    return (ts.getCombinedModifierFlags(fn) & ts.ModifierFlags.Async) !== 0;
}

/** The expressions a function returns; undefined when one `return` has none. */
function returnedExpressions(fn) {
    if (!ts.isBlock(fn.body)) return [fn.body];
    const found = [];
    let bare = false;
    const visit = (node) => {
        if (ts.isFunctionLike(node) || ts.isClassLike(node)) return;
        if (ts.isReturnStatement(node)) {
            if (node.expression === undefined) bare = true;
            else found.push(node.expression);
            return;
        }
        ts.forEachChild(node, visit);
    };
    ts.forEachChild(fn.body, visit);
    return bare ? undefined : found;
}

/** Combine answers: any false is false, else any unknown is unknown. */
function all(answers) {
    if (answers.includes(false)) return false;
    return answers.includes("unknown") ? "unknown" : true;
}

/** Whether one expression, as written, fits a type. */
function fits(checker, expression, expected) {
    let value = expression;
    while (ts.isParenthesizedExpression(value)) value = value.expression;
    if (expected.flags & ts.TypeFlags.AnyOrUnknown) return true;
    if (ts.isConditionalExpression(value))
        return all([
            fits(checker, value.whenTrue, expected),
            fits(checker, value.whenFalse, expected),
        ]);
    if (ts.isObjectLiteralExpression(value))
        return objectFits(checker, value, expected);
    if (ts.isArrayLiteralExpression(value)) {
        const element = checker.getIndexTypeOfType(
            expected,
            ts.IndexKind.Number,
        );
        if (element !== undefined)
            return all(
                value.elements.map((item) =>
                    ts.isSpreadElement(item)
                        ? "unknown"
                        : fits(checker, item, element),
                ),
            );
    }
    return checker.isTypeAssignableTo(
        checker.getTypeAtLocation(value),
        expected,
    );
}

/** Whether an object literal fits a type: some member of a union, or every property. */
function objectFits(checker, literal, expected) {
    if (expected.isUnion()) {
        const answers = expected.types.map((part) =>
            objectFits(checker, literal, part),
        );
        if (answers.includes(true)) return true;
        return answers.includes("unknown") ? "unknown" : false;
    }
    if (!(expected.flags & ts.TypeFlags.Object))
        return checker.isTypeAssignableTo(
            checker.getTypeAtLocation(literal),
            expected,
        );
    const answers = [];
    const written = new Set();
    for (const property of literal.properties) {
        const name =
            ts.isPropertyAssignment(property) ||
            ts.isShorthandPropertyAssignment(property)
                ? staticName(property.name)
                : null;
        if (name === null) return "unknown";
        written.add(name);
        const target = checker.getPropertyOfType(expected, name);
        // A property the type does not name is no mismatch of what it does.
        if (target === undefined) continue;
        const type = checker.getTypeOfSymbol(target);
        if (ts.isPropertyAssignment(property)) {
            answers.push(fits(checker, property.initializer, type));
        } else {
            const symbol = checker.getShorthandAssignmentValueSymbol(property);
            answers.push(
                symbol === undefined
                    ? "unknown"
                    : checker.isTypeAssignableTo(
                          checker.getTypeOfSymbolAtLocation(symbol, property),
                          type,
                      ),
            );
        }
    }
    for (const property of checker.getPropertiesOfType(expected))
        if (
            !(property.flags & ts.SymbolFlags.Optional) &&
            !written.has(property.getName())
        )
            answers.push(false);
    return all(answers);
}
