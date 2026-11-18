/**
 * Where an assertion's operands get their values, inside one test: from a
 * stub's configured value (`mock-config`), a double itself (`mock-call`),
 * the code under test (`production`), a literal, a row of a parameterised
 * test (`parameter`), or somewhere the source does not say (`unknown`).
 * Ported from Momus's provenance pass (`parser-typescript/src/dataflow.ts`).
 *
 * A test's scope is its body and the `beforeAll`/`beforeEach` setups that
 * run before it, in the order they run: the variables they bind and the
 * values they pin on doubles. Variables and doubles are told apart by the
 * checker's symbols, so a name reused across tests stays separate.
 */

import ts from "typescript";
import {
    dottedName,
    PIN_APIS,
    rootIdentifier,
    stripCast,
} from "./test-double-syntax.js";

const SPIES = new Set(["vi.spyOn", "jest.spyOn"]);
const IMPLEMENTATION_APIS = new Set([
    "mockImplementation",
    "mockImplementationOnce",
]);

/**
 * One test's view of its file. `file` names the doubles bound to variables
 * (`double`, `doubleMadeBy`), the file's local helpers (`localFunction`) and
 * its production roots (`roots`).
 */
export class TestScope {
    constructor(file, fn, scopeBodies) {
        this.file = file;
        this.checker = file.checker;
        this.sourceFile = file.sourceFile;
        this.roots = file.roots;
        this.fn = fn;
        this.bindings = new Map();
        this.configs = new Map();
        // Setups run before the test body: the body's own binding or pin wins.
        for (const body of scopeBodies) this.collectBindings(body);
        for (const body of scopeBodies) this.collectConfigs(body);
        this.helpersFollowed = new Set();
    }

    double(identifier) {
        return this.file.double(identifier);
    }

    localFunction(identifier) {
        return this.file.localFunction(identifier);
    }

    /** What a variable of the test or its setup was last bound to: `{expression, mutable}`. */
    binding(identifier) {
        const symbol = this.checker.getSymbolAtLocation(identifier);
        return symbol === undefined ? undefined : this.bindings.get(symbol);
    }

    collectBindings(body) {
        const visit = (node) => {
            if (ts.isVariableStatement(node)) {
                const mutable =
                    (node.declarationList.flags & ts.NodeFlags.Const) === 0;
                for (const declaration of node.declarationList.declarations) {
                    if (
                        ts.isIdentifier(declaration.name) &&
                        declaration.initializer !== undefined
                    )
                        this.bind(declaration.name, {
                            expression: skipAwait(declaration.initializer),
                            mutable,
                        });
                }
                return;
            }
            if (
                ts.isExpressionStatement(node) &&
                ts.isBinaryExpression(node.expression) &&
                node.expression.operatorToken.kind === ts.SyntaxKind.EqualsToken
            ) {
                const { left, right } = node.expression;
                if (ts.isIdentifier(left))
                    this.bind(left, { expression: right, mutable: true });
                return;
            }
            ts.forEachChild(node, visit);
        };
        visit(body);
    }

    bind(name, binding) {
        const symbol = this.checker.getSymbolAtLocation(name);
        if (symbol !== undefined) this.bindings.set(symbol, binding);
    }

    /**
     * The values pinned in scope, by the text of what they were pinned on:
     * `eachMock.getTotal` for `eachMock.getTotal.mockReturnValue(42)`, and
     * `svc.total` for a spy on `svc`'s `total`, as the test calls it.
     */
    collectConfigs(body) {
        const visit = (node) => {
            if (
                ts.isCallExpression(node) &&
                ts.isPropertyAccessExpression(node.expression) &&
                PIN_APIS.has(node.expression.name.text)
            )
                this.configure(node);
            ts.forEachChild(node, visit);
        };
        visit(body);
    }

    configure(call) {
        const base = call.expression.expression;
        const spyCall = chainStart(base);
        const owner = rootIdentifier(base);
        const double =
            (spyCall !== undefined
                ? this.file.doubleMadeBy(spyCall)
                : undefined) ??
            (owner === undefined ? undefined : this.double(owner));
        const argument = call.arguments[0];
        const config = {
            value:
                argument === undefined
                    ? undefined
                    : configuredValueText(
                          argument,
                          this.sourceFile,
                          call.expression.name.text,
                      ),
            double,
        };
        if (spyCall === undefined) this.configs.set(this.text(base), config);
        // A spy answers for the member it replaces: `svc.total()` returns
        // what `vi.spyOn(svc, 'total').mockReturnValue(5)` pinned.
        const spied =
            spyCall ??
            (double !== undefined && SPIES.has(double.pattern)
                ? double.node
                : undefined);
        const [object, member] = spied?.arguments ?? [];
        if (
            object !== undefined &&
            member !== undefined &&
            ts.isStringLiteralLike(member)
        )
            this.configs.set(`${this.text(object)}.${member.text}`, config);
    }

    text(node) {
        return node.getText(this.sourceFile);
    }

    /** Whether a name is a parameter of the test's own function, or destructured from one. */
    isParameter(identifier) {
        let declaration =
            this.checker.getSymbolAtLocation(identifier)?.valueDeclaration;
        while (
            declaration !== undefined &&
            (ts.isBindingElement(declaration) ||
                ts.isObjectBindingPattern(declaration) ||
                ts.isArrayBindingPattern(declaration))
        )
            declaration = declaration.parent;
        return (
            declaration !== undefined &&
            ts.isParameter(declaration) &&
            declaration.parent === this.fn
        );
    }

    /** An operand on the wire: `{source, text?, double?, unconfigured_spy?}`. */
    operand(expression) {
        const found = this.provenance(expression);
        const operand = { source: found.source };
        // A stub's operand reads as the value it was pinned to; any other
        // reads as written, unless evaluating it again could give another
        // value (a call, a `new`), when no text can show it equal to itself.
        const text =
            found.source === "mock-config"
                ? found.value
                : reevaluates(expression)
                  ? undefined
                  : this.text(expression);
        if (text !== undefined) operand.text = text;
        if (found.double !== undefined) {
            operand.double = found.double.canonical;
            if (unconfiguredSpy(found.double)) operand.unconfigured_spy = true;
        }
        return operand;
    }

    provenance(expression) {
        const value = unwrap(expression);
        if (isLiteral(value)) return { source: "literal" };
        if (ts.isIdentifier(value)) return this.identifier(value);
        if (ts.isCallExpression(value)) return this.call(value);
        if (ts.isPropertyAccessExpression(value)) return this.member(value);
        if (ts.isNewExpression(value)) return { source: "production" };
        return { source: "unknown" };
    }

    identifier(identifier) {
        const double = this.double(identifier);
        if (double !== undefined) return { source: "mock-call", double };
        if (this.isParameter(identifier)) return { source: "parameter" };
        const bound = this.binding(identifier);
        // A variable that can be reassigned holds no value we can prove.
        if (bound === undefined || bound.mutable) return { source: "unknown" };
        return this.provenance(bound.expression);
    }

    call(call) {
        const config = this.configs.get(this.text(call.expression));
        if (config !== undefined)
            return {
                source: "mock-config",
                value: config.value,
                double: config.double,
            };
        const root = rootIdentifier(call.expression);
        if (root === undefined) return { source: "unknown" };
        const double = this.double(root);
        if (double !== undefined) return { source: "mock-call", double };
        if (this.roots.isProductionRoot(root)) return { source: "production" };
        const bound = this.binding(root);
        if (
            bound !== undefined &&
            ts.isNewExpression(stripCast(bound.expression))
        )
            return { source: "production" };
        return this.throughHelper(root);
    }

    /**
     * A local helper wrapping the subject hands back what it calls. Only the
     * kind of source carries through: a literal one branch of a helper
     * returns says nothing of the value at the assertion.
     */
    throughHelper(root) {
        const body = this.localFunction(root);
        const returned = returnExpression(body);
        if (returned === undefined || this.helpersFollowed.has(body))
            return { source: "unknown" };
        this.helpersFollowed.add(body);
        try {
            const inner = this.provenance(returned);
            return inner.source === "literal" || inner.source === "unknown"
                ? { source: "unknown" }
                : { source: inner.source, double: inner.double };
        } finally {
            this.helpersFollowed.delete(body);
        }
    }

    member(access) {
        const root = rootIdentifier(access);
        const double = root === undefined ? undefined : this.double(root);
        if (double !== undefined) return { source: "mock-call", double };
        const base = unwrap(access.expression);
        if (!ts.isIdentifier(base)) return { source: "unknown" };
        if (this.isParameter(base)) return { source: "parameter" };
        const bound = this.binding(base);
        return bound === undefined
            ? { source: "unknown" }
            : this.provenance(bound.expression);
    }
}

/** A spy that replaces a named member and that nothing pins. */
function unconfiguredSpy(double) {
    return (
        SPIES.has(double.pattern) &&
        double.members.length > 0 &&
        double.members.every((member) => member.pins.length === 0)
    );
}

/** The spy call a chain of pins starts from: `vi.spyOn(a, 'm')` in `vi.spyOn(a, 'm').mockReturnValueOnce(1)`. */
function chainStart(expression) {
    let current = stripCast(expression);
    for (;;) {
        if (
            ts.isCallExpression(current) &&
            SPIES.has(dottedName(current.expression))
        )
            return current;
        if (
            ts.isCallExpression(current) ||
            ts.isPropertyAccessExpression(current)
        )
            current = current.expression;
        else return undefined;
    }
}

/** An expression past `await`, parentheses, casts and `!`. */
function unwrap(expression) {
    let current = expression;
    while (
        ts.isAwaitExpression(current) ||
        ts.isParenthesizedExpression(current) ||
        ts.isAsExpression(current) ||
        ts.isSatisfiesExpression(current) ||
        ts.isNonNullExpression(current)
    )
        current = current.expression;
    return current;
}

function skipAwait(expression) {
    return ts.isAwaitExpression(expression)
        ? expression.expression
        : expression;
}

/** Whether evaluating an expression again may give another value. */
function reevaluates(expression) {
    const value = unwrap(expression);
    return (
        ts.isCallExpression(value) ||
        ts.isNewExpression(value) ||
        ts.isTaggedTemplateExpression(value)
    );
}

/** A literal whose value its text states: a string, number, boolean or null. */
function isLiteral(node) {
    if (
        ts.isPrefixUnaryExpression(node) &&
        (node.operator === ts.SyntaxKind.MinusToken ||
            node.operator === ts.SyntaxKind.PlusToken)
    )
        return (
            ts.isNumericLiteral(node.operand) ||
            ts.isBigIntLiteral(node.operand)
        );
    return (
        ts.isStringLiteral(node) ||
        ts.isNoSubstitutionTemplateLiteral(node) ||
        ts.isNumericLiteral(node) ||
        ts.isBigIntLiteral(node) ||
        node.kind === ts.SyntaxKind.TrueKeyword ||
        node.kind === ts.SyntaxKind.FalseKeyword ||
        node.kind === ts.SyntaxKind.NullKeyword
    );
}

/**
 * The text of the value a pin makes a stub return: the argument as written,
 * or for an implementation the expression it returns, when that is a single
 * expression body or a literal returned.
 */
function configuredValueText(value, sourceFile, api) {
    if (!IMPLEMENTATION_APIS.has(api)) return value.getText(sourceFile);
    const callback = ts.isParenthesizedExpression(value)
        ? value.expression
        : value;
    if (ts.isArrowFunction(callback) && !ts.isBlock(callback.body))
        return callback.body.getText(sourceFile);
    if (ts.isFunctionExpression(callback) || ts.isArrowFunction(callback)) {
        let result;
        const visit = (node) => {
            if (result !== undefined) return;
            if (
                ts.isReturnStatement(node) &&
                node.expression !== undefined &&
                isLiteral(node.expression)
            ) {
                result = node.expression.getText(sourceFile);
                return;
            }
            ts.forEachChild(node, visit);
        };
        ts.forEachChild(callback.body, visit);
        if (result !== undefined) return result;
    }
    return value.getText(sourceFile);
}

/**
 * The one expression a local helper hands back: an arrow's expression body,
 * or the value of the only `return` that carries one. A helper with several
 * has no single source.
 */
function returnExpression(body) {
    if (body === undefined) return undefined;
    if (!ts.isBlock(body)) return body;
    const returns = [];
    const walk = (node) => {
        if (ts.isFunctionLike(node)) return;
        if (ts.isReturnStatement(node) && node.expression !== undefined)
            returns.push(node.expression);
        ts.forEachChild(node, walk);
    };
    ts.forEachChild(body, walk);
    return returns.length === 1 ? returns[0] : undefined;
}
