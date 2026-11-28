/**
 * The argument-list assertions on a double's members, as `called_with` on
 * the member's fact: how many arguments `toHaveBeenCalledWith(a, b)` lists,
 * for the core to hold against what the member takes.
 *
 * A matcher is read where it is called on `expect(x)`, through `.not`,
 * `.resolves` and `.rejects`, or on `x` itself, as `(spy as any)
 * .toHaveBeenCalledWith(...)` is. It is about a member when `x` is the
 * spy that replaces it, `obj.member` for an object whose member a spy
 * replaces, or `double.member` for a double that stubs that member. Where
 * the source stops saying which member, no matcher is recorded.
 */

import ts from "typescript";
import {
    dottedName,
    objectKey,
    rootIdentifier,
    stripCast,
} from "./test-double-syntax.js";

export const EXPECTS = new Set(["expect", "expect.soft", "vi.expect"]);

/** Matchers that list every argument of the call, then those that list the call number first. */
const WHOLE = new Set([
    "toHaveBeenCalledWith",
    "toBeCalledWith",
    "toHaveBeenLastCalledWith",
    "lastCalledWith",
]);
const AFTER_CALL_NUMBER = new Set(["toHaveBeenNthCalledWith", "nthCalledWith"]);
const SPIES = new Set(["vi.spyOn", "jest.spyOn"]);
const MODIFIERS = new Set(["not", "resolves", "rejects"]);

/**
 * Add `called_with` to the member facts of `file`'s doubles. `file` names
 * its doubles (`doubles`, `double(identifier)`), its checker, source file and
 * `line(node)`, and the collector that holds their facts (`context`).
 */
export function recordCallMatchers(file) {
    const found = new Map();
    const visit = (node) => {
        if (
            ts.isCallExpression(node) &&
            ts.isPropertyAccessExpression(node.expression)
        ) {
            const api = node.expression.name.text;
            const member =
                WHOLE.has(api) || AFTER_CALL_NUMBER.has(api)
                    ? memberOf(file, receiver(node.expression.expression))
                    : undefined;
            if (member !== undefined)
                found.set(member, [
                    ...(found.get(member) ?? []),
                    matcher(file, node, api),
                ]);
        }
        ts.forEachChild(node, visit);
    };
    visit(file.sourceFile);
    const facts = file.context.accumulator.nodesById;
    for (const double of file.doubles) {
        const attributes = facts.get(double.id)?.attributes;
        double.members.forEach((member, index) => {
            if (found.has(member))
                attributes.members[index].called_with = found.get(member);
        });
    }
}

/** One matcher call: its api, line, and the arguments it lists. */
function matcher(file, call, api) {
    const listed = call.arguments.length;
    return {
        api,
        line: file.line(call),
        count: AFTER_CALL_NUMBER.has(api) ? Math.max(listed - 1, 0) : listed,
        spread: call.arguments.some(ts.isSpreadElement),
    };
}

/** What a matcher is called on: the operand of the `expect(...)` it follows, else the operand itself. */
function receiver(base) {
    let value = stripCast(base);
    while (
        ts.isPropertyAccessExpression(value) &&
        MODIFIERS.has(value.name.text)
    )
        value = stripCast(value.expression);
    if (ts.isCallExpression(value) && EXPECTS.has(dottedName(value.expression)))
        return value.arguments[0] && stripCast(value.arguments[0]);
    return value;
}

/** The member of a double that an expression names, if it names one. */
function memberOf(file, expression) {
    if (expression === undefined) return undefined;
    if (ts.isIdentifier(expression)) {
        const double = file.double(expression);
        return double !== undefined &&
            SPIES.has(double.pattern) &&
            double.members.length === 1
            ? double.members[0]
            : undefined;
    }
    if (!ts.isPropertyAccessExpression(expression)) return undefined;
    const name = expression.name.text;
    const holder = stripCast(expression.expression);
    const held =
        rootIdentifier(holder) === holder ? file.double(holder) : undefined;
    const stubbed = held?.members.find((member) => member.name === name);
    if (stubbed !== undefined) return stubbed;
    const key = objectKey(file.checker, file.sourceFile, holder);
    return file.doubles.find(
        (double) =>
            SPIES.has(double.pattern) &&
            double.spiedKey === key &&
            double.target.member === name,
    )?.members[0];
}
