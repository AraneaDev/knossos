import ts from "typescript";
import { unalias } from "./declaration-kinds.js";
import { isStackOverflow } from "./errors.js";
import {
    belowNodeModules,
    isProjectSource,
    relativeInside,
} from "./project-paths.js";
import {
    positionalArgument,
    tableEntries,
    untypedBinding,
} from "./table-dispatch.js";
import { holderOf, unwrapExpression } from "./typescript-fact-utils.js";

/**
 * The calls of a program that hand a function of another file an object
 * literal where it destructures that argument with no declared type:
 * `hold({ tape: recorder })` in one file into `export function hold({ tape })`
 * in another. Only the call site says what `tape` holds, so the callee's file
 * reads these calls when its facts are collected (see
 * TableDispatch.crossFileArguments).
 *
 * The checker is asked only about a call whose callee's name can reach such
 * a function: a name one declares, or one an import or export renames it to.
 * What each file declares, renames and calls is read from its syntax alone,
 * once per parsed file, so a program rebuilt around unchanged files reads
 * only the changed ones again.
 */
const summaryByFile = new WeakMap();
const indexByProgram = new WeakMap();

/**
 * The calls into `fileName` from the other files `belongs` says the program
 * describes, in program and source order.
 *
 * @param {(relative: string) => boolean} belongs
 */
export function callSitesInto(program, root, exclusions, fileName, belongs) {
    const calls = [];
    for (const site of index(program, root, exclusions))
        if (site.callee.fileName === fileName && belongs(site.callerPath))
            calls.push(site.call);
    return calls;
}

/**
 * Each pair of files the program describes where a call in the first hands
 * a function of the second an object literal to destructure, as
 * `caller NUL callee`, sorted. The callee's contribution reads the caller, so
 * an edit there rebuilds it; a pair that appears is a file no read of the
 * callee named, which only the program as a whole shows (see
 * ReadAttribution.environmentOf).
 *
 * @param {(relative: string) => boolean} belongs
 */
export function callArgumentPairs(program, root, exclusions, belongs) {
    const pairs = new Set();
    for (const site of index(program, root, exclusions))
        if (belongs(site.callerPath) && belongs(site.calleePath))
            pairs.add(`${site.callerPath}\0${site.calleePath}`);
    return [...pairs].sort();
}

/**
 * Whether a file declares a function with a body that destructures a
 * parameter with no declared type, the only kind a call elsewhere can say
 * anything about.
 */
export function destructuresUntyped(sourceFile) {
    return summary(sourceFile).declares.size > 0;
}

function index(program, root, exclusions) {
    let sites = indexByProgram.get(program);
    if (sites === undefined) {
        sites = collectSites(program, root, exclusions);
        indexByProgram.set(program, sites);
    }
    return sites;
}

/** The program's project source files, each to its project path. */
function projectFiles(program, root, exclusions) {
    const files = new Map();
    for (const sourceFile of program.getSourceFiles()) {
        const relative = relativeInside(root, sourceFile.fileName);
        if (
            relative !== null &&
            !sourceFile.isDeclarationFile &&
            !belowNodeModules(relative) &&
            isProjectSource(relative, exclusions)
        )
            files.set(sourceFile, relative);
    }
    return files;
}

function collectSites(program, root, exclusions) {
    const files = projectFiles(program, root, exclusions);
    const names = reachingNames([...files.keys()].map(summary));
    if (names.size === 0) return [];
    const checker = program.getTypeChecker();
    const sites = [];
    for (const [caller, callerPath] of files)
        for (const { call, name } of summary(caller).calls) {
            if (name !== null && !names.has(name)) continue;
            for (const callee of literalCallees(checker, call)) {
                const calleePath = files.get(callee);
                if (calleePath !== undefined && callee !== caller)
                    sites.push({ call, callerPath, callee, calleePath });
            }
        }
    return sites;
}

/**
 * Every name a call can reach a function that destructures untyped by:
 * the names the functions are declared under, and whatever an import or
 * export renames one of those to, followed until nothing new is reached.
 */
function reachingNames(summaries) {
    const names = new Set(summaries.flatMap((item) => [...item.declares]));
    if (names.size === 0) return names;
    const renames = summaries.flatMap((item) => item.renames);
    let grown = true;
    while (grown) {
        grown = false;
        for (const [from, to] of renames)
            if (names.has(from) && !names.has(to)) {
                names.add(to);
                grown = true;
            }
    }
    return names;
}

/** What a file declares, renames and calls, read once per parsed file. */
function summary(sourceFile) {
    let found = summaryByFile.get(sourceFile);
    if (found === undefined) {
        found = { declares: new Set(), renames: [], calls: [] };
        try {
            readSyntax(sourceFile, found);
        } catch (error) {
            // A file too deep to walk is one whose own facts fail the same
            // way; it neither takes nor hands anything.
            if (!isStackOverflow(error)) throw error;
            found = { declares: new Set(), renames: [], calls: [] };
        }
        summaryByFile.set(sourceFile, found);
    }
    return found;
}

function readSyntax(sourceFile, found) {
    const visit = (node) => {
        if (
            ts.isFunctionLike(node) &&
            node.body !== undefined &&
            node.parameters.some(untypedBinding)
        )
            for (const name of declaredNames(node)) found.declares.add(name);
        found.renames.push(...renames(node));
        if (ts.isCallExpression(node) && handsLiteral(node))
            found.calls.push({ call: node, name: calleeName(node) });
        ts.forEachChild(node, visit);
    };
    visit(sourceFile);
}

/**
 * The names a function is called by from another file: its own, the
 * binding or property it is the value of, and `default` for a default
 * export or `module.exports`.
 */
function declaredNames(fn) {
    const names = [];
    if (
        fn.name !== undefined &&
        (ts.isIdentifier(fn.name) || ts.isStringLiteral(fn.name))
    )
        names.push(fn.name.text);
    if (ts.isFunctionDeclaration(fn)) {
        if ((ts.getCombinedModifierFlags(fn) & ts.ModifierFlags.Default) !== 0)
            names.push("default");
        return names;
    }
    const holder = holderOf(fn);
    if (holder === undefined) return names;
    if (
        (ts.isVariableDeclaration(holder) || ts.isPropertyAssignment(holder)) &&
        (ts.isIdentifier(holder.name) || ts.isStringLiteral(holder.name))
    )
        names.push(holder.name.text);
    else if (ts.isExportAssignment(holder)) names.push("default");
    else if (
        ts.isBinaryExpression(holder) &&
        holder.operatorToken.kind === ts.SyntaxKind.EqualsToken &&
        ts.isPropertyAccessExpression(holder.left)
    ) {
        names.push(holder.left.name.text);
        if (holder.left.getText() === "module.exports") names.push("default");
    }
    return names;
}

/**
 * The renames a node makes, each `[from, to]`: an import or export
 * specifier, a default import or export of a name, a module bound whole by
 * `require`, and a name destructured out of one.
 */
function renames(node) {
    if (ts.isImportSpecifier(node) || ts.isExportSpecifier(node))
        return [[(node.propertyName ?? node.name).text, node.name.text]];
    if (ts.isImportClause(node) && node.name !== undefined)
        return [["default", node.name.text]];
    if (ts.isImportEqualsDeclaration(node))
        return [["default", node.name.text]];
    if (ts.isExportAssignment(node) && ts.isIdentifier(node.expression))
        return [[node.expression.text, "default"]];
    if (isAssignment(node) && ts.isIdentifier(node.right)) {
        const exported = commonJsExport(node.left);
        if (exported !== null) return [[node.right.text, exported]];
    }
    if (
        ts.isVariableDeclaration(node) &&
        node.initializer !== undefined &&
        isRequire(unwrapExpression(node.initializer))
    ) {
        if (ts.isIdentifier(node.name)) return [["default", node.name.text]];
        if (ts.isObjectBindingPattern(node.name))
            return node.name.elements.flatMap((element) => {
                const key = element.propertyName ?? element.name;
                return ts.isIdentifier(key) && ts.isIdentifier(element.name)
                    ? [[key.text, element.name.text]]
                    : [];
            });
    }
    return [];
}

/** Whether a node is a plain `left = right` assignment. */
function isAssignment(node) {
    return (
        ts.isBinaryExpression(node) &&
        node.operatorToken.kind === ts.SyntaxKind.EqualsToken
    );
}

/**
 * The name another file's `require` reads what is assigned to `target`
 * under: `default` for `module.exports`, `run` for `exports.run` and
 * `module.exports.run`; null for anything else.
 */
function commonJsExport(target) {
    if (!ts.isPropertyAccessExpression(target)) return null;
    if (isModuleExports(target)) return "default";
    const object = target.expression;
    return (ts.isIdentifier(object) && object.text === "exports") ||
        isModuleExports(object)
        ? target.name.text
        : null;
}

function isModuleExports(expression) {
    return (
        ts.isPropertyAccessExpression(expression) &&
        ts.isIdentifier(expression.expression) &&
        expression.expression.text === "module" &&
        expression.name.text === "exports"
    );
}

function isRequire(expression) {
    return (
        expression !== undefined &&
        ts.isCallExpression(expression) &&
        ts.isIdentifier(expression.expression) &&
        expression.expression.text === "require"
    );
}

/** Whether a call hands some parameter an object literal, past any spread. */
function handsLiteral(call) {
    return call.arguments.some((_, index) => {
        const value = positionalArgument(call, index);
        return value !== undefined && ts.isObjectLiteralExpression(value);
    });
}

/**
 * The name a call calls: `f` in `f()`, `m` in `a.b.m()`; null for
 * `table[key]()`, whose entries the checker names.
 */
function calleeName(call) {
    const callee = unwrapExpression(call.expression);
    if (ts.isElementAccessExpression(callee)) return null;
    const name = ts.isPropertyAccessExpression(callee) ? callee.name : callee;
    return ts.isIdentifier(name) ? name.text : "";
}

/**
 * The files of the functions `call` hands an object literal where they
 * destructure that argument with no declared type, each once.
 */
function literalCallees(checker, call) {
    const files = new Set();
    for (const fn of argumentCallees(checker, call))
        if (
            fn.body !== undefined &&
            fn.parameters.some((parameter, index) => {
                const value = positionalArgument(call, index);
                return (
                    value !== undefined &&
                    ts.isObjectLiteralExpression(value) &&
                    untypedBinding(parameter)
                );
            })
        )
            files.add(fn.getSourceFile());
    return files;
}

/**
 * The functions a call can run, by the name it calls: a table's entries for
 * `table[key](...)`, else what the called name is declared as. The name's
 * symbol is enough here and cheaper than resolving the call; the callee's
 * file resolves it when it reads the call.
 */
function argumentCallees(checker, call) {
    const callee = unwrapExpression(call.expression);
    if (ts.isElementAccessExpression(callee))
        return (tableEntries(checker, callee)?.entries ?? []).map(
            ({ fn }) => fn,
        );
    const name = ts.isPropertyAccessExpression(callee) ? callee.name : callee;
    if (name === undefined || !ts.isIdentifier(name)) return [];
    const symbol = unalias(checker, checker.getSymbolAtLocation(name));
    return (symbol?.declarations ?? [])
        .map(declaredFunction)
        .filter((fn) => fn !== undefined);
}

/**
 * The function a declaration is or holds: `function f`, `const f = () =>`,
 * `f: function () {}`, `export default (x) => ...`, and the CommonJS
 * assignments `module.exports = function () {}` (declared as the whole
 * assignment) and `exports.f = function () {}` (declared as its target).
 */
function declaredFunction(declaration) {
    if (ts.isFunctionLike(declaration)) return declaration;
    const value =
        ts.isVariableDeclaration(declaration) ||
        ts.isPropertyAssignment(declaration)
            ? declaration.initializer
            : ts.isExportAssignment(declaration)
              ? declaration.expression
              : isAssignment(declaration)
                ? declaration.right
                : isAssignment(declaration.parent) &&
                    declaration.parent.left === declaration
                  ? declaration.parent.right
                  : undefined;
    const fn = unwrapExpression(value);
    return fn !== undefined && ts.isFunctionLike(fn) ? fn : undefined;
}
