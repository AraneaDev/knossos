import ts from "typescript";
import {
    callableKind,
    isDeclaration,
    referenceableDeclaration,
    unalias,
} from "./declaration-kinds.js";
import {
    isFunctionAssignment,
    unwrapExpression,
} from "./typescript-fact-utils.js";

/**
 * The most functions a lookup by a computed key fans out to. A larger table
 * is a registry the key could pick anything from; referencing every entry
 * would bury the in-degree signal hub and hotspot ranking depend on, so such
 * a lookup references none of them.
 */
export const TABLE_FAN_OUT_LIMIT = 64;

/**
 * Calls that reach a function through a table or hand it an object literal.
 *
 * `handlers[data.type](step, ctx)` picks a function out of an object literal
 * by a key only the run time knows, and `run(step, { tape })` into a function
 * that destructures `{ tape }` without a type leaves `tape` untyped inside it.
 * In both cases the checker resolves nothing, so the functions in the table
 * and the methods called on `tape` read as unused.
 */
export class TableDispatch {
    constructor(context) {
        this.context = context;
    }

    /**
     * `handlers[data.type]` references each function the table holds, as
     * possible: the key picks one of them. A literal key, `handlers['ping']`,
     * reaches only the entry it names, certainly.
     */
    lookup(node) {
        const members = this.members(node);
        if (members === null) return;
        const source = this.context.currentSource();
        for (const { target } of members.entries) {
            if (target !== source)
                this.context.addEdge(
                    "references",
                    source,
                    target,
                    node,
                    {},
                    "ast",
                    members.computed ? "possible" : "certain",
                );
        }
    }

    /**
     * The functions `table[key]` can pick out of the object literal a
     * `const` binding (here or imported) holds, with each one's node
     * reference; null when the receiver is no such literal, or a computed
     * key would fan out over more than `TABLE_FAN_OUT_LIMIT` functions.
     */
    members(node) {
        const checker = this.context.checker;
        const symbol = unalias(
            checker,
            checker.getSymbolAtLocation(node.expression),
        );
        const binding = symbol?.declarations?.find(
            (item) => ts.isVariableDeclaration(item) && item.initializer,
        );
        const table =
            binding === undefined
                ? undefined
                : unwrapExpression(binding.initializer);
        if (table === undefined || !ts.isObjectLiteralExpression(table))
            return null;
        const key = unwrapExpression(node.argumentExpression);
        const named =
            ts.isStringLiteralLike(key) || ts.isNumericLiteral(key)
                ? key.text
                : undefined;
        const entries = [];
        for (const property of table.properties) {
            const fn = tableFunction(property);
            if (fn === undefined) continue;
            if (named !== undefined && property.name.text !== named) continue;
            const target = this.context.symbolReference(
                checker.getSymbolAtLocation(property.name),
                "method",
                true,
            );
            if (target !== null) entries.push({ target, fn });
        }
        if (named === undefined && entries.length > TABLE_FAN_OUT_LIMIT)
            return null;
        return { computed: named === undefined, entries };
    }

    /**
     * `run(step, { tape })` into a function of this file that destructures
     * that argument without a type, `function run(step, { tape })`: inside it
     * `tape` is untyped, but this call says what it holds. Each method call
     * on the binding the checker cannot resolve, `tape.hold()`, is a probable
     * call of the member the argument's value has.
     */
    objectArguments(node, signature, source) {
        for (const { target, fn } of this.callees(node, signature, source)) {
            if (
                fn.getSourceFile() !== this.context.sourceFile ||
                fn.body === undefined
            )
                continue;
            fn.parameters.forEach((parameter, index) => {
                const argument = unwrapExpression(node.arguments[index]);
                if (
                    !ts.isObjectBindingPattern(parameter.name) ||
                    argument === undefined ||
                    !ts.isObjectLiteralExpression(argument)
                )
                    return;
                for (const element of parameter.name.elements)
                    this.boundCalls(fn.body, element, argument, target);
            });
        }
    }

    /** The functions a call runs, each with its node reference. */
    callees(node, signature, source) {
        const callee = unwrapExpression(node.expression);
        if (ts.isElementAccessExpression(callee))
            return this.members(callee)?.entries ?? [];
        const fn = signature?.declaration;
        if (fn === undefined || !ts.isFunctionLike(fn)) return [];
        const target = this.context.callTarget(node, signature);
        return target === null || target === source ? [] : [{ target, fn }];
    }

    /**
     * The calls in `body` of a method on the binding `element` names, to the
     * member of the value `argument` gives that binding.
     */
    boundCalls(body, element, argument, source) {
        if (element.dotDotDotToken || !ts.isIdentifier(element.name)) return;
        const type = this.argumentType(element, argument);
        if (type === undefined) return;
        const checker = this.context.checker;
        const binding = checker.getSymbolAtLocation(element.name);
        const visit = (child) => {
            if (child !== body && isDeclaration(child)) return;
            if (
                ts.isCallExpression(child) &&
                ts.isPropertyAccessExpression(child.expression) &&
                ts.isIdentifier(child.expression.expression) &&
                checker.getSymbolAtLocation(child.expression.expression) ===
                    binding &&
                checker.getResolvedSignature(child)?.declaration === undefined
            )
                this.boundCall(child, type, source);
            ts.forEachChild(child, visit);
        };
        visit(body);
    }

    /**
     * The probable `calls` edge of `binding.method()` on a value of `type`,
     * when that member is declared in this project.
     */
    boundCall(call, type, source) {
        const member = type.getProperty(call.expression.name.text);
        const declaration = member?.declarations?.find((item) =>
            referenceableDeclaration(item),
        );
        if (!declaration) return;
        const target = this.context.symbolReference(
            member,
            callableKind(declaration),
            true,
        );
        // Only a member of this project: what a library value's method runs
        // is no question of this graph.
        if (
            target !== null &&
            target !== source &&
            !target.startsWith("ts:external_")
        )
            this.context.addEdge(
                "calls",
                source,
                target,
                call,
                { inferred_from: "call_argument" },
                "ast",
                "probable",
            );
    }

    /**
     * The type of the value `argument` gives the property `element` takes
     * out of it: `{ tape }` or `{ tape: recorder() }`.
     */
    argumentType(element, argument) {
        const key = element.propertyName ?? element.name;
        if (!ts.isIdentifier(key) && !ts.isStringLiteral(key)) return undefined;
        const property = argument.properties.find(
            (item) =>
                (ts.isShorthandPropertyAssignment(item) ||
                    ts.isPropertyAssignment(item)) &&
                (ts.isIdentifier(item.name) || ts.isStringLiteral(item.name)) &&
                item.name.text === key.text,
        );
        if (property === undefined) return undefined;
        const checker = this.context.checker;
        if (ts.isPropertyAssignment(property))
            return checker.getTypeAtLocation(property.initializer);
        const value = checker.getShorthandAssignmentValueSymbol(property);
        return value === undefined
            ? undefined
            : checker.getTypeOfSymbolAtLocation(value, property);
    }
}

/**
 * The function a table entry holds: `ping: (message) => ...` in a module's
 * table, or a method, `async hold(step, ctx) { ... }`.
 */
function tableFunction(property) {
    if (isFunctionAssignment(property))
        return unwrapExpression(property.initializer);
    if (
        ts.isMethodDeclaration(property) &&
        (ts.isIdentifier(property.name) || ts.isStringLiteral(property.name))
    )
        return property;
    return undefined;
}
