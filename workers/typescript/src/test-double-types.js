/**
 * What a test double stands in for, as the checker reads it, and whether the
 * values a test pins on its members fit the types they replace.
 *
 * Only what the source declares counts. A target the checker cannot name, or
 * one declared outside the project, leaves every pinned value `"unknown"`; a
 * member that declares no return type, or a value typed `any`, marks the
 * comparison `typed: false`.
 */

import ts from "typescript";
import { relativeInside, belowNodeModules } from "./project-paths.js";
import { fits, inferredReturnFits } from "./test-double-returns.js";
import { functionArgument, stripCast } from "./test-double-syntax.js";
import { unwrapExpression } from "./typescript-fact-utils.js";

/** Pinning APIs whose argument is the member's return value. */
const RETURN_APIS = new Set(["mockReturnValue", "mockReturnValueOnce"]);
/** Pinning APIs whose argument is what the member's promise resolves to. */
const RESOLVE_APIS = new Set(["mockResolvedValue", "mockResolvedValueOnce"]);
/** Pinning APIs whose argument is a function standing in for the member. */
const IMPLEMENTATION_APIS = new Set([
    "mockImplementation",
    "mockImplementationOnce",
]);

/**
 * The named type a double stands in for: its symbol and name, or null for an
 * anonymous, `any` or unresolved type.
 */
export function namedTarget(type) {
    if (type === undefined || type.flags & ts.TypeFlags.AnyOrUnknown)
        return null;
    const symbol = type.aliasSymbol ?? type.getSymbol();
    const name = symbol?.getName();
    // A module's symbol is named by its file's absolute path: a module is
    // named by the specifier that imports it instead (`moduleNamespace`).
    if (
        name === undefined ||
        name.startsWith("__") ||
        symbol.flags & ts.SymbolFlags.ValueModule
    )
        return null;
    return { symbol, name, type };
}

/**
 * The module an identifier bound by `import * as ns from 'x'` stands for:
 * its specifier, and its type when the checker resolved the module.
 */
export function moduleNamespace(checker, expression) {
    const value = stripCast(expression);
    if (!ts.isIdentifier(value)) return null;
    const binding = checker
        .getSymbolAtLocation(value)
        ?.declarations?.find((item) => ts.isNamespaceImport(item));
    const specifier = binding?.parent.parent.moduleSpecifier;
    if (specifier === undefined || !ts.isStringLiteral(specifier)) return null;
    const type = checker.getTypeAtLocation(value);
    const module = type.getSymbol();
    return {
        specifier,
        owner:
            module !== undefined && module.flags & ts.SymbolFlags.ValueModule
                ? { symbol: module, name: specifier.text, type }
                : null,
    };
}

/**
 * The named type of the object a double replaces. An object cast away to
 * `any` is still what it was made as: the `new X()` it is, or that the
 * variable it names was bound to.
 */
export function ownerOf(checker, expression, depth = 0) {
    const named = namedTarget(checker.getTypeAtLocation(expression));
    if (named !== null) return named;
    const value = stripCast(expression);
    if (ts.isNewExpression(value))
        return namedTarget(checker.getTypeAtLocation(value));
    if (!ts.isIdentifier(value) || depth > 3) return null;
    const declaration = checker.getSymbolAtLocation(value)?.valueDeclaration;
    return declaration !== undefined &&
        ts.isVariableDeclaration(declaration) &&
        declaration.initializer !== undefined
        ? ownerOf(checker, declaration.initializer, depth + 1)
        : null;
}

/** Whether a named target is declared in the project, outside its dependencies. */
export function declaredInProject(root, target) {
    return (target?.symbol.declarations ?? []).some((declaration) => {
        const relative = relativeInside(
            root,
            declaration.getSourceFile().fileName,
        );
        return relative !== null && !belowNodeModules(relative);
    });
}

/**
 * A pinned value on the wire: where it was pinned, how, and whether it fits
 * the return type of `member` on `owner` (a named target declared in the
 * project, or null when there is none to hold it to).
 */
export function pinnedValue(checker, sourceFile, pin, owner, member) {
    const line =
        sourceFile.getLineAndCharacterOfPosition(pin.call.getStart(sourceFile))
            .line + 1;
    return {
        api: pin.api,
        line,
        once: pin.api.endsWith("Once"),
        ...assignability(checker, pin, owner, member),
    };
}

const UNKNOWN = { assignable: "unknown", typed: false };

function assignability(checker, pin, owner, member) {
    const argument = pin.call.arguments[0];
    if (owner === null || member === undefined || argument === undefined)
        return UNKNOWN;
    // A rejection reason is held to no declared type.
    if (
        !RETURN_APIS.has(pin.api) &&
        !RESOLVE_APIS.has(pin.api) &&
        !IMPLEMENTATION_APIS.has(pin.api)
    )
        return UNKNOWN;
    try {
        const declared = declaredReturn(checker, owner.type, member);
        if (declared === null) return UNKNOWN;
        const expected = RESOLVE_APIS.has(pin.api)
            ? (checker.getAwaitedType(declared.type) ?? declared.type)
            : declared.type;
        const value = pinnedType(checker, pin.api, argument);
        if (value === undefined) return UNKNOWN;
        const typed = declared.typed && value.typed;
        if (containsTypeParameter(checker, expected))
            return { assignable: "unknown", typed };
        // A value with no context to type it, and an inferred return, widen
        // the literals they build; each is held to the declared type as
        // written instead.
        const inferred =
            IMPLEMENTATION_APIS.has(pin.api) && !value.typed
                ? functionArgument(argument)
                : undefined;
        const written = !IMPLEMENTATION_APIS.has(pin.api)
            ? fits(
                  checker,
                  value.typed ? argument : castLiteral(argument),
                  expected,
              )
            : inferred === undefined
              ? null
              : inferredReturnFits(checker, inferred, expected);
        return {
            assignable:
                written ?? checker.isTypeAssignableTo(value.type, expected),
            typed,
        };
    } catch {
        return UNKNOWN;
    }
}

/**
 * The object or array literal a value cast to `any` was written as, which
 * still says what it holds (`{ id: '1' } as any` has no `email`); any other
 * value as it stands.
 */
function castLiteral(argument) {
    const value = stripCast(argument);
    return ts.isObjectLiteralExpression(value) ||
        ts.isArrayLiteralExpression(value)
        ? value
        : argument;
}

/**
 * The type a pinned argument stands for, the value or what an
 * implementation returns, and whether that type is stated: a value not
 * `any`, an implementation that declares its return type. An inferred
 * return widens its literals (`status: 'open'` reads as `string`), so it
 * is no declared side.
 */
function pinnedType(checker, api, argument) {
    const type = checker.getTypeAtLocation(argument);
    if (!IMPLEMENTATION_APIS.has(api))
        return { type, typed: !(type.flags & ts.TypeFlags.AnyOrUnknown) };
    const signatures = type.getCallSignatures();
    if (signatures.length !== 1) return undefined;
    const returned = signatures[0].getReturnType();
    return {
        type: returned,
        typed:
            signatures[0].getDeclaration()?.type !== undefined &&
            !(returned.flags & ts.TypeFlags.AnyOrUnknown),
    };
}

/**
 * The return type `member` of `type` declares, and whether it is declared
 * at all (rather than inferred, or `any`); null for a member the type does
 * not have, or one whose overloads leave the return ambiguous.
 */
function declaredReturn(checker, type, member) {
    const property = checker.getPropertyOfType(type, member);
    if (property === undefined) return null;
    const signatures = checker.getTypeOfSymbol(property).getCallSignatures();
    if (signatures.length !== 1) return null;
    const returned = signatures[0].getReturnType();
    const written = writtenFunction(
        property.valueDeclaration ?? property.declarations?.[0],
    );
    return {
        type: returned,
        typed:
            written?.type !== undefined &&
            !(returned.flags & ts.TypeFlags.AnyOrUnknown),
    };
}

/** The function a member declaration writes: a method, a function type, or a function value. */
function writtenFunction(declaration) {
    if (declaration === undefined) return undefined;
    if (ts.isFunctionLike(declaration)) return declaration;
    if (
        declaration.type !== undefined &&
        ts.isFunctionTypeNode(declaration.type)
    )
        return declaration.type;
    const value = unwrapExpression(declaration.initializer);
    return value !== undefined &&
        (ts.isArrowFunction(value) || ts.isFunctionExpression(value))
        ? value
        : undefined;
}

/** Whether a type still holds a type parameter, which no value can be held to. */
function containsTypeParameter(checker, type) {
    if (type.flags & ts.TypeFlags.TypeParameter) return true;
    if (type.isUnionOrIntersection())
        return type.types.some((part) => containsTypeParameter(checker, part));
    if (
        !(type.flags & ts.TypeFlags.Object) ||
        !(type.objectFlags & ts.ObjectFlags.Reference)
    )
        return false;
    return checker
        .getTypeArguments(type)
        .some((part) => containsTypeParameter(checker, part));
}
