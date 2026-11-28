/**
 * The tests of a test file, as `test_case` nodes: each test's name, the calls
 * it makes into production and the modules they land in, and its
 * `expect(...).<matcher>(...)` assertions with where each operand's value
 * comes from, for the core to find the assertions that cannot fail.
 *
 * Ported from Momus's assertion pass (`parser-typescript/src/dataflow.ts`).
 * A test is an `it`/`test` call with a function to run, through any of the
 * modifiers `.only`, `.skip`, `.concurrent`, `.todo` and `.fails`, and the
 * `.each(rows)`, `.skipIf(condition)` and `.runIf(condition)` forms. An
 * `it.fails` test passes only when its body fails, so it says `fails` and no
 * assertion of it is a tautology. The `beforeAll`/`beforeEach` setups that run
 * before it are the file's own and those of the `describe` blocks around it,
 * through the same modifiers.
 */

import ts from "typescript";
import { TestScope } from "./test-case-flow.js";
import { ProductionRoots, productionCalls } from "./test-case-production.js";
import { EXPECTS, recordCallMatchers } from "./test-call-matchers.js";
import { dottedName, stripCast } from "./test-double-syntax.js";
import { reference } from "./typescript-fact-utils.js";

const TESTS = new Set(["it", "test"]);
const SUITES = new Set(["describe"]);
/** The links after `it`, `test` or `describe` that still define one. */
const MODIFIERS = new Set(["only", "skip", "concurrent", "todo", "fails"]);
/** The calls a definition is made through, its rows or condition aside: `it.skipIf(ci)('name', fn)`. */
const TABLE_FORMS = [".each", ".skipIf", ".runIf"];
const SETUPS = new Set(["beforeAll", "beforeEach"]);

/**
 * Emit the tests of `sourceFile` through `emit`, the file's fact collector,
 * naming the doubles `collectTestDoubles` returned for the same file, and
 * record on each double made inside a test the test it belongs to.
 */
export function collectTestCases(sourceFile, checker, doubles, emit) {
    new TestCaseFile(sourceFile, checker, doubles, emit).emit();
}

class TestCaseFile {
    constructor(sourceFile, checker, doubles, emit) {
        this.sourceFile = sourceFile;
        this.checker = checker;
        this.context = emit;
        this.doubles = doubles;
        this.roots = new ProductionRoots(emit.root, checker);
        this.bySymbol = new Map();
        this.byNode = new Map();
        for (const double of doubles) {
            if (double.binding !== undefined)
                this.bySymbol.set(double.binding, double);
            this.byNode.set(double.node, double);
        }
        this.tests = [];
        this.setups = [];
        this.find(sourceFile);
    }

    /** The double a variable holds. */
    double(identifier) {
        const symbol = this.checker.getSymbolAtLocation(identifier);
        return symbol === undefined ? undefined : this.bySymbol.get(symbol);
    }

    /** The double a call made. */
    doubleMadeBy(call) {
        return this.byNode.get(call);
    }

    /** The body of a function this file declares under a name. */
    localFunction(identifier) {
        const declaration =
            this.checker.getSymbolAtLocation(identifier)?.valueDeclaration;
        if (
            declaration === undefined ||
            declaration.getSourceFile() !== this.sourceFile
        )
            return undefined;
        if (ts.isFunctionDeclaration(declaration)) return declaration.body;
        if (
            !ts.isVariableDeclaration(declaration) ||
            declaration.initializer === undefined
        )
            return undefined;
        const value = stripCast(declaration.initializer);
        return ts.isArrowFunction(value) || ts.isFunctionExpression(value)
            ? value.body
            : undefined;
    }

    line(node) {
        return (
            this.sourceFile.getLineAndCharacterOfPosition(
                node.getStart(this.sourceFile),
            ).line + 1
        );
    }

    find(node) {
        if (ts.isCallExpression(node)) {
            const name = calledName(node);
            if (defines(name, TESTS)) {
                const fn = node.arguments.slice(1).find(isFunction);
                if (fn !== undefined)
                    this.tests.push({
                        call: node,
                        fn,
                        fails: name.split(".").includes("fails"),
                    });
            } else if (SETUPS.has(dottedName(node.expression))) {
                const fn = node.arguments[0];
                const suites = suitesAround(node);
                if (fn !== undefined && isFunction(fn))
                    this.setups.push({
                        kind: dottedName(node.expression),
                        body: fn.body,
                        suite: suites[0],
                        depth: suites.length,
                        line: this.line(node),
                    });
            }
        }
        ts.forEachChild(node, (child) => this.find(child));
    }

    /**
     * The setups that run before a test, in the order they run: every
     * `beforeAll` before any `beforeEach`, outer `describe` blocks first.
     */
    setupsOf(test) {
        const suites = new Set(suitesAround(test.call));
        return this.setups
            .filter(
                (setup) => setup.suite === undefined || suites.has(setup.suite),
            )
            .sort(
                (a, b) =>
                    phase(a) - phase(b) || a.depth - b.depth || a.line - b.line,
            );
    }

    emit() {
        const context = this.context;
        const onLine = new Map();
        recordCallMatchers(this);
        for (const test of this.tests) {
            // Two tests that start on one line: the first is `#test:<line>`,
            // the next `#test:<line>.2`, and so on.
            const line = this.line(test.call);
            const nth = (onLine.get(line) ?? 0) + 1;
            onLine.set(line, nth);
            const canonical = `${context.relative}#test:${line}${nth === 1 ? "" : `.${nth}`}`;
            const id = reference("test_case", canonical);
            const name = testName(test.call, this.sourceFile);
            const kept = context.addNode(
                id,
                "test_case",
                canonical,
                // A node's display name may not be empty; an untitled test
                // shows its canonical name.
                name === "" ? canonical : name,
                test.call,
                this.attributes(test, name),
            );
            if (!kept) continue;
            context.addEdge("contains", context.moduleId, id, test.call);
            this.claimDoubles(test, canonical);
        }
    }

    attributes(test, name) {
        const scope = new TestScope(this, test.fn, [
            ...this.setupsOf(test).map((setup) => setup.body),
            test.fn.body,
        ]);
        const { count, subjects } = productionCalls(
            test.fn.body,
            scope,
            this.roots,
        );
        return {
            name,
            production_calls: count,
            subjects,
            ...(test.fails ? { fails: true } : {}),
            assertions: this.assertions(test.fn.body, scope),
        };
    }

    /** Each `expect(actual).<matcher>(expected)` of a test, in the order written. */
    assertions(body, scope) {
        const found = [];
        const visit = (node) => {
            if (
                ts.isCallExpression(node) &&
                EXPECTS.has(dottedName(node.expression))
            ) {
                const matcher = matcherCall(node);
                if (matcher !== undefined) {
                    const operands = [node.arguments[0], matcher.arguments[0]]
                        .filter((operand) => operand !== undefined)
                        .map((operand) => scope.operand(operand));
                    found.push({
                        api: matcher.expression.name.text,
                        line: this.line(matcher),
                        ...(negated(node, matcher) ? { negated: true } : {}),
                        operands,
                    });
                }
            }
            ts.forEachChild(node, visit);
        };
        visit(body);
        return found;
    }

    /** Name the test on each double made inside it. */
    claimDoubles(test, canonical) {
        for (const double of this.doubles) {
            if (
                double.node.pos >= test.fn.pos &&
                double.node.end <= test.fn.end
            )
                this.context.accumulator.setAttribute(
                    double.id,
                    "test_case",
                    canonical,
                );
        }
    }
}

/**
 * The name a test-defining call is made through, its `.each` table or its
 * `.skipIf`/`.runIf` condition aside: `it.only` for
 * `it.only.each(rows)('name', fn)`, `it` for `it.skipIf(ci)('name', fn)`.
 */
function calledName(call) {
    const direct = dottedName(call.expression);
    if (direct !== null) return direct;
    const table = ts.isCallExpression(call.expression)
        ? call.expression.expression
        : ts.isTaggedTemplateExpression(call.expression)
          ? call.expression.tag
          : undefined;
    const name = table === undefined ? null : dottedName(table);
    const form = TABLE_FORMS.find((suffix) => name?.endsWith(suffix));
    return form === undefined ? null : name.slice(0, -form.length);
}

/** Whether a called name defines one of `bases`: `it`, or `it.concurrent.only`. */
function defines(name, bases) {
    const [base, ...links] = name?.split(".") ?? [];
    return bases.has(base) && links.every((link) => MODIFIERS.has(link));
}

/** The `describe` calls around a node, nearest first. */
function suitesAround(node) {
    const suites = [];
    for (
        let current = node.parent;
        current !== undefined;
        current = current.parent
    )
        if (
            ts.isCallExpression(current) &&
            defines(calledName(current), SUITES)
        )
            suites.push(current);
    return suites;
}

function phase(setup) {
    return setup.kind === "beforeAll" ? 0 : 1;
}

function isFunction(node) {
    return ts.isArrowFunction(node) || ts.isFunctionExpression(node);
}

/** A test's title as written: the string's text, else the expression's source. */
function testName(call, sourceFile) {
    const title = call.arguments[0];
    if (title === undefined) return "";
    return ts.isStringLiteralLike(title)
        ? title.text
        : title.getText(sourceFile);
}

/** Whether the chain from `expect(...)` to its matcher passes through `.not`. */
function negated(expectCall, matcher) {
    for (
        let link = matcher.expression.expression;
        link !== expectCall;
        link = link.expression
    )
        if (link.name.text === "not") return true;
    return false;
}

/**
 * The matcher call an `expect(...)` starts: `toBe(1)` in
 * `expect(x).not.toBe(1)`; undefined when no matcher is called on it.
 */
function matcherCall(expectCall) {
    let chain = expectCall;
    while (
        ts.isPropertyAccessExpression(chain.parent) &&
        chain.parent.expression === chain
    )
        chain = chain.parent;
    const call = chain.parent;
    return chain !== expectCall &&
        ts.isCallExpression(call) &&
        call.expression === chain
        ? call
        : undefined;
}
