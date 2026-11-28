/**
 * What a test runs of the code under test: how many calls it makes into
 * production, and the project modules those calls land in (its subjects).
 * Ported from Momus's `productionCalls` (`parser-typescript/src/dataflow.ts`).
 *
 * A production root is a name imported from anything but the test framework,
 * statically or through `await import('x')`. A call counts when it starts at
 * such a name, at a variable the test bound to `new X()`, or inside a local
 * helper the test calls; a dynamic `import('x')` counts too, as it runs the
 * module. What a test hands a double while making or configuring it
 * (`vi.mocked(new Db())`, `.mockReturnValue(build())`) runs nothing.
 *
 * A subject is the module a counted call's name is declared in, when that
 * module is the project's own code: never a dependency, a declaration file,
 * or another test module.
 */

import ts from "typescript";
import { belowNodeModules, relativeInside } from "./project-paths.js";
import { isTestModule } from "./test-module-paths.js";
import {
    dottedName,
    MOCK_FACTORIES,
    PIN_APIS,
    rootIdentifier,
    stripCast,
} from "./test-double-syntax.js";

/** Specifiers of the test framework: its names are helpers, never the subject. */
const FRAMEWORK_SPECIFIER = /^(vitest|@vitest\/.*|jest|@jest\/.*)$/;

/** Calls that make a double: what they are handed builds the double. */
const DOUBLE_MAKERS = new Set([
    ...MOCK_FACTORIES,
    "vi.spyOn",
    "jest.spyOn",
    "vi.mocked",
    "jest.mocked",
    "vi.mock",
    "jest.mock",
    "jest.doMock",
    "vi.importMock",
    "jest.requireMock",
    "vi.stubGlobal",
]);

/**
 * The names of a test file that lead into production code, read through the
 * checker, and the modules they land in.
 */
export class ProductionRoots {
    constructor(root, checker) {
        this.root = root;
        this.checker = checker;
    }

    /**
     * The module specifier a name was imported through: `import { a } from
     * 'x'`, `import * as a from 'x'`, or a binding of `await import('x')`.
     */
    importOf(identifier) {
        const symbol = this.checker.getSymbolAtLocation(identifier);
        for (const declaration of symbol?.declarations ?? []) {
            const specifier =
                staticImportSpecifier(declaration) ??
                dynamicImportSpecifier(declaration);
            if (specifier !== undefined) return { symbol, specifier };
        }
        return null;
    }

    isProductionRoot(identifier) {
        const found = this.importOf(identifier);
        return (
            found !== null && !FRAMEWORK_SPECIFIER.test(found.specifier.text)
        );
    }

    /** The project module a production root's declaration lives in, or null. */
    subjectOf(identifier) {
        const found = this.importOf(identifier);
        if (found === null || FRAMEWORK_SPECIFIER.test(found.specifier.text))
            return null;
        const { symbol, specifier } = found;
        const target =
            symbol.flags & ts.SymbolFlags.Alias
                ? this.checker.getAliasedSymbol(symbol)
                : this.checker.getSymbolAtLocation(specifier);
        return this.moduleOf(target);
    }

    /** The project module a dynamic `import('x')` runs, or null. */
    importedModule(call) {
        const [specifier] = call.arguments;
        if (specifier === undefined || !ts.isStringLiteralLike(specifier))
            return null;
        return this.moduleOf(this.checker.getSymbolAtLocation(specifier));
    }

    moduleOf(symbol) {
        const declaration =
            symbol?.valueDeclaration ?? symbol?.declarations?.[0];
        if (declaration === undefined) return null;
        const file = declaration.getSourceFile();
        if (file.isDeclarationFile) return null;
        const relative = relativeInside(this.root, file.fileName);
        if (
            relative === null ||
            belowNodeModules(relative) ||
            isTestModule(relative)
        )
            return null;
        return relative;
    }
}

/**
 * The production calls of a test's `body`, and the subjects they land in.
 * `scope` answers which variable holds a double (`double`), what a variable
 * of the test or its setup was bound to (`binding`) and the body of a local
 * helper (`localFunction`).
 */
export function productionCalls(body, scope, roots) {
    let count = 0;
    const subjects = new Set();
    const seen = new Set();
    const land = (subject) => {
        if (subject !== null) subjects.add(subject);
    };
    const constructed = (expression) => {
        const root = rootIdentifier(expression.expression);
        return root === undefined ? null : roots.subjectOf(root);
    };

    const visit = (node) => {
        if (seen.has(node)) return;
        seen.add(node);
        if (ts.isCallExpression(node)) {
            if (node.expression.kind === ts.SyntaxKind.ImportKeyword) {
                count++;
                land(roots.importedModule(node));
                return;
            }
            if (makesOrConfiguresDouble(node)) return;
            const root = rootIdentifier(node.expression);
            if (root !== undefined && scope.double(root) === undefined) {
                const bound = scope.binding(root);
                const value = bound && stripCast(bound.expression);
                if (value !== undefined && ts.isNewExpression(value)) {
                    count++;
                    land(constructed(value));
                } else if (roots.isProductionRoot(root)) {
                    count++;
                    land(roots.subjectOf(root));
                } else {
                    const local = scope.localFunction(root);
                    if (local !== undefined) visit(local);
                }
            }
        } else if (ts.isNewExpression(node)) {
            const root = rootIdentifier(node.expression);
            if (root !== undefined && roots.isProductionRoot(root)) {
                count++;
                land(roots.subjectOf(root));
            }
        }
        ts.forEachChild(node, visit);
    };
    visit(body);
    return { count, subjects: [...subjects].sort() };
}

/** Whether a call makes a double, or pins what one does. */
function makesOrConfiguresDouble(call) {
    if (DOUBLE_MAKERS.has(dottedName(call.expression))) return true;
    return (
        ts.isPropertyAccessExpression(call.expression) &&
        PIN_APIS.has(call.expression.name.text)
    );
}

/** The specifier of the import declaration a name is bound by. */
function staticImportSpecifier(declaration) {
    let current = declaration;
    while (
        current !== undefined &&
        (ts.isImportSpecifier(current) ||
            ts.isNamedImports(current) ||
            ts.isNamespaceImport(current) ||
            ts.isImportClause(current))
    )
        current = current.parent;
    if (current === undefined || current === declaration) return undefined;
    return ts.isImportDeclaration(current) &&
        ts.isStringLiteral(current.moduleSpecifier)
        ? current.moduleSpecifier
        : undefined;
}

/** The specifier of `await import('x')` a variable or its destructured name is bound to. */
function dynamicImportSpecifier(declaration) {
    let current = declaration;
    while (
        ts.isBindingElement(current) ||
        ts.isObjectBindingPattern(current) ||
        ts.isArrayBindingPattern(current)
    )
        current = current.parent;
    if (!ts.isVariableDeclaration(current) || current.initializer === undefined)
        return undefined;
    let value = stripCast(current.initializer);
    if (ts.isAwaitExpression(value)) value = stripCast(value.expression);
    if (
        !ts.isCallExpression(value) ||
        value.expression.kind !== ts.SyntaxKind.ImportKeyword
    )
        return undefined;
    const [specifier] = value.arguments;
    return specifier !== undefined && ts.isStringLiteralLike(specifier)
        ? specifier
        : undefined;
}
