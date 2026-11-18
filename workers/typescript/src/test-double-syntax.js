/**
 * The syntax test doubles are written in, read without the checker: the
 * dotted name a call is made through, the `vi.fn()` a chain starts from, and
 * the calls that pin a double's behaviour.
 */

import ts from "typescript";

/** The calls that make a bare mock function. */
export const MOCK_FACTORIES = new Set(["vi.fn", "jest.fn"]);

/** The calls that pin what a double's member does, by the name they are called through. */
export const PIN_APIS = new Set([
    "mockReturnValue",
    "mockReturnValueOnce",
    "mockResolvedValue",
    "mockResolvedValueOnce",
    "mockRejectedValue",
    "mockRejectedValueOnce",
    "mockImplementation",
    "mockImplementationOnce",
]);

/** `vi.spyOn` for `vi.spyOn(...)`; null when any link is not a plain name. */
export function dottedName(expression) {
    if (ts.isIdentifier(expression)) return expression.text;
    if (!ts.isPropertyAccessExpression(expression)) return null;
    const owner = dottedName(expression.expression);
    return owner === null ? null : `${owner}.${expression.name.text}`;
}

/** The identifier a member or call chain starts from: `svc` in `svc.a().b`. */
export function rootIdentifier(expression) {
    if (ts.isIdentifier(expression)) return expression;
    if (
        ts.isPropertyAccessExpression(expression) ||
        ts.isCallExpression(expression)
    )
        return rootIdentifier(expression.expression);
    return undefined;
}

/** An expression past the parentheses and `as` casts around it. */
export function stripCast(expression) {
    let current = expression;
    while (ts.isAsExpression(current) || ts.isParenthesizedExpression(current))
        current = current.expression;
    return current;
}

/** Whether a call makes a bare mock function. */
export function isMockFactory(node) {
    return (
        ts.isCallExpression(node) &&
        MOCK_FACTORIES.has(dottedName(node.expression))
    );
}

/**
 * The `vi.fn()` call a chain starts from: the call itself, or the start of
 * `vi.fn().mockReturnValue(1)`.
 */
export function findMockFactory(expression) {
    let current = stripCast(expression);
    for (;;) {
        if (isMockFactory(current)) return current;
        if (
            ts.isCallExpression(current) ||
            ts.isPropertyAccessExpression(current)
        )
            current = current.expression;
        else return undefined;
    }
}

/**
 * The pinning calls chained on `node`: `.mockReturnValue(1)` in
 * `vi.spyOn(a, 'm').mockReturnValue(1)`, each as `{api, call}`.
 */
export function chainedPins(node) {
    const pins = [];
    let parent = node.parent;
    while (
        parent !== undefined &&
        ts.isPropertyAccessExpression(parent) &&
        ts.isCallExpression(parent.parent) &&
        parent.parent.expression === parent
    ) {
        if (PIN_APIS.has(parent.name.text))
            pins.push({ api: parent.name.text, call: parent.parent });
        parent = parent.parent.parent;
    }
    return pins;
}

/** The function a chained `.mockImplementation(fn)` hands a spy, if any. */
export function chainedImplementation(node) {
    const pin = chainedPins(node).find(({ api }) =>
        api.startsWith("mockImplementation"),
    );
    return functionArgument(pin?.call.arguments[0]);
}

/** An arrow or function expression, past its parentheses; undefined for anything else. */
export function functionArgument(expression) {
    if (expression === undefined) return undefined;
    const value = stripCast(expression);
    return ts.isArrowFunction(value) || ts.isFunctionExpression(value)
        ? value
        : undefined;
}

/**
 * The name of the variable a call's value is bound to: `spy` in `const spy =
 * vi.spyOn(a, 'm').mockReturnValue(1)`, as the chain returns the spy.
 */
export function bindingOf(node) {
    let current = node;
    for (;;) {
        const parent = current.parent;
        if (parent === undefined) return undefined;
        if (
            (ts.isPropertyAccessExpression(parent) &&
                parent.expression === current) ||
            (ts.isCallExpression(parent) && parent.expression === current) ||
            ts.isParenthesizedExpression(parent) ||
            ts.isAsExpression(parent)
        ) {
            current = parent;
            continue;
        }
        return ts.isVariableDeclaration(parent) &&
            parent.initializer === current &&
            ts.isIdentifier(parent.name)
            ? parent.name
            : undefined;
    }
}

/** The name a type cast or annotation gives a binding: `Db` for `x as unknown as Db`. */
export function writtenTypeReference(declaration) {
    if (
        declaration.type !== undefined &&
        ts.isTypeReferenceNode(declaration.type)
    )
        return declaration.type;
    const value = declaration.initializer;
    if (
        value !== undefined &&
        ts.isAsExpression(value) &&
        ts.isTypeReferenceNode(value.type)
    )
        return value.type;
    return undefined;
}

/** A property's name as written: an identifier or string; null for a computed one. */
export function staticName(name) {
    return ts.isIdentifier(name) || ts.isStringLiteralLike(name)
        ? name.text
        : null;
}

/**
 * The object literal a module mock's factory returns: the arrow's
 * expression body, or the first `return` of a block body.
 */
export function factoryObject(factory) {
    const fn = functionArgument(factory);
    if (fn === undefined) return undefined;
    if (!ts.isBlock(fn.body)) {
        const body = stripCast(fn.body);
        return ts.isObjectLiteralExpression(body) ? body : undefined;
    }
    let found;
    const visit = (node) => {
        if (found !== undefined || ts.isFunctionLike(node)) return;
        if (ts.isReturnStatement(node)) {
            const value =
                node.expression === undefined
                    ? undefined
                    : stripCast(node.expression);
            found =
                value !== undefined && ts.isObjectLiteralExpression(value)
                    ? value
                    : null;
            return;
        }
        ts.forEachChild(node, visit);
    };
    ts.forEachChild(fn.body, visit);
    return found ?? undefined;
}
