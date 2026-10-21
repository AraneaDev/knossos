import ts from "typescript";
import {
    callableKind,
    isDeclaration,
    referenceableDeclaration,
    unalias,
} from "./declaration-kinds.js";
import {
    holderOf,
    isFunctionAssignment,
    unwrapExpression,
} from "./typescript-fact-utils.js";

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
        // Each method call on a destructured binding, to each node reference
        // of the function it is in and the members the call sites' values
        // give the binding there.
        this.inferred = new Map();
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
     * reference; null when the receiver is no such literal. A computed key
     * can pick any of them, however many the table holds.
     */
    members(node) {
        const checker = this.context.checker;
        const table = tableEntries(checker, node);
        if (table === null) return null;
        const entries = [];
        for (const { property, fn } of table.entries) {
            const target = this.context.symbolReference(
                checker.getSymbolAtLocation(property.name),
                "method",
                true,
            );
            if (target !== null) entries.push({ target, fn });
        }
        return { computed: table.computed, entries };
    }

    /**
     * `run(step, { tape })` into a function of this file that destructures
     * that argument without a type, `function run(step, { tape })`: inside it
     * `tape` is untyped, but this call says what it holds. Each method call
     * on the binding the checker cannot resolve, `tape.hold()`, is a probable
     * call of the member the argument's value has.
     */
    objectArguments(node, signature, source) {
        for (const { target, fn } of this.callees(node, signature, source))
            this.boundArguments(node, fn, target);
    }

    /**
     * The calls of other files of the program that hand a function of this
     * file an object literal to destructure, read as `objectArguments` reads
     * a call of this file. This file's facts then follow those files, so
     * each one is among its reads: an edit or a deletion there rebuilds it.
     */
    crossFileArguments() {
        const project = this.context.project;
        for (const call of project.callSitesInto?.() ?? []) {
            project.readCaller?.(call.getSourceFile());
            const signature = this.context.checker.getResolvedSignature(call);
            for (const { target, fn } of this.callees(call, signature, null))
                this.boundArguments(call, fn, target);
        }
    }

    /**
     * Emit the inferred calls, once every call site of the file and of the
     * other files has been read: each distinct project member a call site's
     * value gives the binding, however many call sites there are.
     */
    finish() {
        this.crossFileArguments();
        for (const [call, bySource] of this.inferred)
            for (const [source, targets] of bySource)
                for (const target of targets)
                    this.context.addEdge(
                        "calls",
                        source,
                        target,
                        call,
                        { inferred_from: "call_argument" },
                        "ast",
                        "probable",
                    );
        this.inferred.clear();
    }

    /** Read the object literals `node` hands `fn` of this file into its bindings. */
    boundArguments(node, fn, target) {
        if (
            fn.getSourceFile() !== this.context.sourceFile ||
            fn.body === undefined
        )
            return;
        fn.parameters.forEach((parameter, index) => {
            const argument = positionalArgument(node, index);
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
     * Note the probable call of `binding.method()` on a value of `type`,
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
            target === null ||
            target === source ||
            target.startsWith("ts:external_")
        )
            return;
        const bySource = this.inferred.get(call) ?? new Map();
        const targets = bySource.get(source) ?? new Set();
        targets.add(target);
        bySource.set(source, targets);
        this.inferred.set(call, bySource);
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
 * The argument a call hands the parameter at `index`, past any parentheses
 * and casts; undefined when it hands none, or when a spread comes at or
 * before it, since what the spread holds decides which parameter each later
 * argument lands in.
 */
export function positionalArgument(call, index) {
    const spread = call.arguments.findIndex((argument) =>
        ts.isSpreadElement(argument),
    );
    if (spread !== -1 && spread <= index) return undefined;
    return unwrapExpression(call.arguments[index]);
}

/**
 * Whether a parameter destructures its argument with no type declared for
 * it: no annotation, no JSDoc `@param`, and no annotation or JSDoc `@type` on
 * the binding its function is the value of (`const f: Handler = ({ a }) =>`).
 * Only such a binding is typed by what a call hands it.
 */
export function untypedBinding(parameter) {
    if (
        !ts.isObjectBindingPattern(parameter.name) ||
        parameter.type !== undefined ||
        ts.getJSDocType(parameter) !== undefined
    )
        return false;
    const holder = holderOf(parameter.parent);
    return !(
        holder !== undefined &&
        ts.isVariableDeclaration(holder) &&
        (holder.type !== undefined || ts.getJSDocType(holder) !== undefined)
    );
}

/**
 * The entries of the object literal a `const` binding (here or imported)
 * holds that `table[key]` can pick, each property with its function; null
 * when the receiver is no such literal. A literal key picks only the entry
 * it names.
 */
export function tableEntries(checker, node) {
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
        entries.push({ property, fn });
    }
    return { computed: named === undefined, entries };
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
