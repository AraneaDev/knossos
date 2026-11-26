/**
 * The fields a deserialiser fills on a class, interface or object-literal
 * type alias, and the cases of an enum, for the core to hold a fixture
 * against.
 *
 * Fields come from the type checker, so a base or intersection it resolves
 * adds its own. A list that may not be the wire's whole story is marked
 * `fields_open`: a base or intersection part the checker cannot read, a type
 * parameter, an index signature, or a decorator anywhere on the class or a
 * class it extends (class-transformer's `@Expose`, TypeORM's columns), which
 * may rename or add a key. A list longer than `MAX_ENTRIES` is cut and
 * marked, and the core never judges it.
 */

import ts from "typescript";

/** Fields or cases listed at most; a longer list is cut and marked truncated. */
export const MAX_ENTRIES = 64;

/**
 * The `fields` or `cases` attributes of a declaration node: none for a node
 * that has neither, or for fields when no checker is at hand.
 */
export function fieldFacts(checker, node) {
    if (ts.isEnumDeclaration(node)) return enumCases(node);
    if (
        checker === undefined ||
        !(
            ts.isClassDeclaration(node) ||
            ts.isInterfaceDeclaration(node) ||
            (ts.isTypeAliasDeclaration(node) && isObjectShape(node.type))
        )
    ) {
        return {};
    }
    const type = declaredType(checker, node);
    // A union keeps only the members every variant shares, so its list would be neither whole nor typed right.
    if (type === undefined || type.isUnion()) return {};
    const fields = checker
        .getPropertiesOfType(type)
        .filter(isField)
        .map((property) => fieldOf(property, node))
        .sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
    return {
        fields: fields.slice(0, MAX_ENTRIES),
        ...(isOpen(checker, node, type) ? { fields_open: true } : {}),
        ...(fields.length > MAX_ENTRIES ? { fields_truncated: true } : {}),
    };
}

/** Whether a type alias's right side is an object literal type, or an intersection of named or literal object types. */
function isObjectShape(type) {
    if (ts.isParenthesizedTypeNode(type)) return isObjectShape(type.type);
    if (ts.isTypeLiteralNode(type)) return true;
    return (
        ts.isIntersectionTypeNode(type) &&
        type.types.every(
            (part) =>
                ts.isTypeReferenceNode(part) ||
                ts.isParenthesizedTypeNode(part) ||
                ts.isTypeLiteralNode(part),
        )
    );
}

/** The instance type a declaration names, undefined when it has no symbol. */
function declaredType(checker, node) {
    if (ts.isTypeAliasDeclaration(node)) {
        return checker.getTypeAtLocation(node.type);
    }
    const symbol =
        node.name === undefined
            ? undefined
            : checker.getSymbolAtLocation(node.name);
    return symbol === undefined
        ? undefined
        : checker.getDeclaredTypeOfSymbol(symbol);
}

/** Whether a property is a data field: not a method, an accessor or an ECMAScript private name. */
function isField(property) {
    const notData =
        ts.SymbolFlags.Method |
        ts.SymbolFlags.GetAccessor |
        ts.SymbolFlags.SetAccessor;
    return (
        (property.flags & ts.SymbolFlags.Property) !== 0 &&
        (property.flags & notData) === 0 &&
        !/^(?:__)?#/.test(property.name)
    );
}

/**
 * One field: its name, its type as written, whether a deserialiser needs the
 * key, and the line of its declaration. A field declared in another file
 * (an inherited one) takes the line of the declaration that inherits it.
 */
function fieldOf(property, node) {
    const declaration = property.valueDeclaration ?? property.declarations?.[0];
    const sourceFile = node.getSourceFile();
    const at =
        declaration !== undefined && declaration.getSourceFile() === sourceFile
            ? declaration
            : node;
    const typeNode = declaration?.type;
    return {
        name: property.name,
        ...(typeNode === undefined
            ? {}
            : { type: typeNode.getText(declaration.getSourceFile()) }),
        required: isRequired(property, declaration),
        line:
            sourceFile.getLineAndCharacterOfPosition(at.getStart(sourceFile))
                .line + 1,
    };
}

/** Whether a deserialiser needs the key: not `?`, not a union with `undefined`, not a class field or parameter with an initializer. */
function isRequired(property, declaration) {
    if ((property.flags & ts.SymbolFlags.Optional) !== 0) return false;
    if (declaration === undefined) return true;
    if (
        (ts.isPropertyDeclaration(declaration) || ts.isParameter(declaration)) &&
        declaration.initializer !== undefined
    ) {
        return false;
    }
    return !admitsUndefined(declaration.type);
}

/** Whether a written type is a union that holds `undefined`. */
function admitsUndefined(type) {
    if (type === undefined) return false;
    if (ts.isParenthesizedTypeNode(type)) return admitsUndefined(type.type);
    return (
        ts.isUnionTypeNode(type) &&
        type.types.some(
            (part) =>
                part.kind === ts.SyntaxKind.UndefinedKeyword ||
                (ts.isLiteralTypeNode(part) &&
                    part.literal.kind === ts.SyntaxKind.UndefinedKeyword),
        )
    );
}

/**
 * Whether the list may miss or misname a key. A decorator is looked for on
 * the declaration itself, on each class an alias intersects, and on every
 * class those extend.
 */
function isOpen(checker, node, type) {
    if (
        checker.getIndexInfosOfType(type).length > 0 ||
        isDecorated(node) ||
        hasUnreadablePart(checker, node, type)
    ) {
        return true;
    }
    const parts = type.isIntersection() ? type.types : [type];
    return parts
        .flatMap((part) => [part, ...baseClasses(checker, part)])
        .some((part) =>
            (part.symbol?.declarations ?? []).some(
                (declaration) =>
                    ts.isClassLike(declaration) && isDecorated(declaration),
            ),
        );
}

/** Whether a heritage clause, an intersection part or a base type is one the checker could not read. */
function hasUnreadablePart(checker, node, type) {
    const unreadable = (part) =>
        (part.flags & (ts.TypeFlags.Any | ts.TypeFlags.TypeParameter)) !== 0;
    if (ts.isTypeAliasDeclaration(node)) {
        return (
            unreadable(type) ||
            (type.isIntersection() && type.types.some(unreadable))
        );
    }
    return (
        (node.heritageClauses ?? []).some((clause) =>
            clause.types.some((written) =>
                unreadable(checker.getTypeAtLocation(written.expression)),
            ),
        ) || checker.getBaseTypes(type).some(unreadable)
    );
}

/** Every class or interface type a type extends, at any depth, each once. */
function baseClasses(checker, type) {
    const seen = new Set();
    const pending = [type];
    while (pending.length > 0) {
        const current = pending.pop();
        if (!current.isClassOrInterface()) continue;
        for (const base of checker.getBaseTypes(current)) {
            const target = base.target ?? base;
            if (!seen.has(target)) {
                seen.add(target);
                pending.push(target);
            }
        }
    }
    return [...seen];
}

/** Whether a class, one of its members or a constructor parameter carries a decorator. */
function isDecorated(node) {
    if (!ts.isClassLike(node)) return false;
    const decorated = (part) =>
        ts.canHaveDecorators(part) && (ts.getDecorators(part) ?? []).length > 0;
    return (
        decorated(node) ||
        node.members.some(
            (member) =>
                decorated(member) ||
                (ts.isConstructorDeclaration(member) &&
                    member.parameters.some(decorated)),
        )
    );
}

/**
 * An enum's cases, sorted by name. Each carries its backing literal as
 * `value` only when every case has a string or number literal initializer;
 * an enum with an implicit or computed member gives none, so nothing is
 * judged against a guess.
 */
function enumCases(node) {
    const values = node.members.map((member) => literalText(member.initializer));
    const backed = values.every((value) => value !== null);
    const cases = node.members
        .map((member, index) => ({
            name: member.name.getText(node.getSourceFile()).replace(/^(['"])(.*)\1$/s, "$2"),
            ...(backed ? { value: values[index] } : {}),
        }))
        .sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
    return {
        cases: cases.slice(0, MAX_ENTRIES),
        ...(cases.length > MAX_ENTRIES ? { cases_truncated: true } : {}),
    };
}

/** The text of a string or (signed) number literal, null for anything else. */
function literalText(initializer) {
    if (initializer === undefined) return null;
    if (
        ts.isStringLiteral(initializer) ||
        ts.isNoSubstitutionTemplateLiteral(initializer) ||
        ts.isNumericLiteral(initializer)
    ) {
        return initializer.text;
    }
    if (
        ts.isPrefixUnaryExpression(initializer) &&
        initializer.operator === ts.SyntaxKind.MinusToken &&
        ts.isNumericLiteral(initializer.operand)
    ) {
        return `-${initializer.operand.text}`;
    }
    return null;
}
