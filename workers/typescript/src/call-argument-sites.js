import ts from "typescript";
import { unalias } from "./declaration-kinds.js";
import { isStackOverflow } from "./errors.js";
import {
    belowNodeModules,
    isProjectSource,
    relativeInside,
} from "./project-paths.js";
import { tableEntries } from "./table-dispatch.js";
import { unwrapExpression } from "./typescript-fact-utils.js";

/**
 * The calls of a program that hand a function of another file an object
 * literal where it destructures that argument: `hold({ tape: recorder })` in
 * one file into `export function hold({ tape })` in another. Only the call
 * site says what `tape` holds, so the callee's file reads these calls when
 * its facts are collected (see TableDispatch.crossFileArguments).
 *
 * Built once per program with the program's own checker, so a program the
 * cache keeps keeps its index. A program none of whose files destructures a
 * parameter without a type has none to build.
 */
const indexByProgram = new WeakMap();

/** The calls into `fileName` from other project files of the program. */
export function callSitesInto(program, root, exclusions, fileName) {
    return index(program, root, exclusions).sites.get(fileName) ?? [];
}

/**
 * Each pair of project files where a call in the first hands a function of
 * the second an object literal to destructure, as `caller NUL callee`,
 * sorted. The callee's contribution reads the caller, so an edit there
 * rebuilds it; a pair that appears is a file no read of the callee named,
 * which only the program as a whole shows (see ReadAttribution.environmentOf).
 */
export function callArgumentPairs(program, root, exclusions) {
    return index(program, root, exclusions).pairs;
}

/**
 * Whether a file declares a function with a body that destructures a
 * parameter without a declared type, the only kind a call elsewhere can say
 * anything about.
 */
export function destructuresUntyped(sourceFile) {
    const visit = (node) => {
        if (
            ts.isFunctionLike(node) &&
            node.body !== undefined &&
            node.parameters.some(
                (parameter) =>
                    ts.isObjectBindingPattern(parameter.name) &&
                    parameter.type === undefined,
            )
        )
            return true;
        return ts.forEachChild(node, visit) ?? false;
    };
    return visit(sourceFile);
}

function index(program, root, exclusions) {
    let found = indexByProgram.get(program);
    if (found === undefined) {
        found = collectSites(program, root, exclusions);
        indexByProgram.set(program, found);
    }
    return found;
}

/** The program's project source files, each with its project path. */
function projectFiles(program, root, exclusions) {
    const files = [];
    for (const sourceFile of program.getSourceFiles()) {
        const relative = relativeInside(root, sourceFile.fileName);
        if (
            relative !== null &&
            !sourceFile.isDeclarationFile &&
            !belowNodeModules(relative) &&
            isProjectSource(relative, exclusions)
        )
            files.push({ sourceFile, relative });
    }
    return files;
}

function collectSites(program, root, exclusions) {
    const files = projectFiles(program, root, exclusions);
    const relativeOf = new Map(
        files.map(({ sourceFile, relative }) => [sourceFile, relative]),
    );
    const sites = new Map();
    const pairs = new Set();
    const walkable = (sourceFile) => {
        try {
            return destructuresUntyped(sourceFile);
        } catch (error) {
            // A file too deep to walk is one whose own facts fail the same
            // way; it neither takes nor hands anything.
            if (!isStackOverflow(error)) throw error;
            return false;
        }
    };
    if (!files.some(({ sourceFile }) => walkable(sourceFile)))
        return { sites, pairs: [] };
    const checker = program.getTypeChecker();
    for (const { sourceFile, relative } of files) {
        const visit = (node) => {
            if (ts.isCallExpression(node))
                for (const file of literalCallees(checker, node)) {
                    const into = relativeOf.get(file);
                    if (into === undefined || file === sourceFile) continue;
                    const list = sites.get(file.fileName) ?? [];
                    list.push(node);
                    sites.set(file.fileName, list);
                    pairs.add(`${relative}\0${into}`);
                }
            ts.forEachChild(node, visit);
        };
        try {
            visit(sourceFile);
        } catch (error) {
            if (!isStackOverflow(error)) throw error;
        }
    }
    return { sites, pairs: [...pairs].sort() };
}

/**
 * The files of the functions `call` hands an object literal where they
 * destructure that argument, each once.
 */
function literalCallees(checker, call) {
    const literals = call.arguments.map((argument) => {
        const value = unwrapExpression(argument);
        return value !== undefined && ts.isObjectLiteralExpression(value);
    });
    if (!literals.includes(true)) return [];
    const files = new Set();
    for (const fn of argumentCallees(checker, call))
        if (
            fn.body !== undefined &&
            fn.parameters.some(
                (parameter, index) =>
                    literals[index] === true &&
                    ts.isObjectBindingPattern(parameter.name),
            )
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
 * `f: function () {}` and `export default (x) => ...`.
 */
function declaredFunction(declaration) {
    if (ts.isFunctionLike(declaration)) return declaration;
    const value =
        ts.isVariableDeclaration(declaration) ||
        ts.isPropertyAssignment(declaration)
            ? declaration.initializer
            : ts.isExportAssignment(declaration)
              ? declaration.expression
              : undefined;
    const fn = unwrapExpression(value);
    return fn !== undefined && ts.isFunctionLike(fn) ? fn : undefined;
}
