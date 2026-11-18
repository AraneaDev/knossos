/**
 * Where a test file reaches its doubles: the lines that call one, and the
 * lines that hand one to code that may call it (an argument, an object or
 * array element, a property it is installed on). A double reached nowhere is
 * a stub nothing can run. Ported from Momus's mock reachability.
 *
 * Calls into the test framework itself (`expect(spy)`, `vi.mocked(x)`) and
 * the calls that configure a double (`spy.mockReturnValue(1)`) reach
 * nothing.
 */

import ts from "typescript";
import {
    findMockFactory,
    rootIdentifier,
    stripCast,
} from "./test-double-syntax.js";

/** Callee roots that belong to the test framework rather than the code under test. */
const FRAMEWORK_CALLEE =
    /^(expect|vi|jest|it|test|describe|beforeEach|afterEach|beforeAll|afterAll|xit|xtest|fit|fdescribe)$/;

/** Configuring calls: what they are handed becomes a double's behaviour. */
const CONFIG_CALL =
    /^mock(ReturnValue|ResolvedValue|RejectedValue|Implementation)(Once)?$/;

/**
 * Add to each double's `sites` every line of `sourceFile` that reaches it.
 * `doubles` names a double by an identifier bound to it (`bound(identifier)`),
 * by the `vi.fn()` call it was made by (`made(call)`), and the spies
 * installed on an object (`spied(expression)`).
 */
export function recordReach(sourceFile, doubles) {
    const line = (node) =>
        sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile))
            .line + 1;
    const reach = (double, node) => double?.sites.add(line(node));

    const handOff = (expression, site) => {
        if (expression === undefined) return;
        const value = stripCast(expression);
        if (ts.isIdentifier(value)) reach(doubles.bound(value), site);
        const factory = findMockFactory(value);
        if (factory !== undefined) reach(doubles.made(factory), site);
        for (const spy of doubles.spied(value)) reach(spy, site);
    };

    const visit = (node) => {
        if (ts.isCallExpression(node)) call(node);
        if (ts.isNewExpression(node))
            for (const argument of node.arguments ?? [])
                handOff(argument, node);
        // `host.load = vi.fn()` installs the double where the subject calls it.
        if (
            ts.isBinaryExpression(node) &&
            node.operatorToken.kind === ts.SyntaxKind.EqualsToken &&
            (ts.isPropertyAccessExpression(node.left) ||
                ts.isElementAccessExpression(node.left))
        )
            handOff(node.right, node);
        if (ts.isObjectLiteralExpression(node)) {
            for (const property of node.properties) {
                if (ts.isPropertyAssignment(property))
                    handOff(property.initializer, node);
                else if (ts.isShorthandPropertyAssignment(property))
                    handOff(property.name, node);
            }
        } else if (ts.isArrayLiteralExpression(node)) {
            for (const element of node.elements) handOff(element, node);
        }
        ts.forEachChild(node, visit);
    };

    const call = (node) => {
        const member = ts.isPropertyAccessExpression(node.expression)
            ? node.expression.name.text
            : undefined;
        const configures = member !== undefined && CONFIG_CALL.test(member);
        const root = rootIdentifier(node.expression)?.text;
        // What a configuring call is handed is what the subject gets back,
        // even when the chain starts at the framework: `jest.spyOn(cm,
        // 'make').mockReturnValue(adapter)`.
        if (configures || root === undefined || !FRAMEWORK_CALLEE.test(root))
            for (const argument of node.arguments) handOff(argument, node);
        if (configures) return;
        const base = ts.isPropertyAccessExpression(node.expression)
            ? node.expression.expression
            : node.expression;
        const owner = rootIdentifier(base);
        if (owner !== undefined) reach(doubles.bound(owner), node);
        // `svc.totalCents()` runs the spy installed by `vi.spyOn(svc, 'totalCents')`.
        if (member !== undefined && ts.isIdentifier(base))
            for (const spy of doubles.spied(base))
                if (spy.target.member === member) reach(spy, node);
    };

    visit(sourceFile);
}
