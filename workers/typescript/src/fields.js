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
 * may rename or add a key; a JS file's `this.x = …` expando, which any code
 * path may or may not set; or a field inherited from a generic base and typed
 * by its parameter (`extends Gen<number>`), whose argument is not substituted. A list longer than `MAX_ENTRIES` is cut and
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
        ...visibilityOf(declaration),
    };
}

/** A class field's or parameter property's `private` or `protected`; nothing for a public one. */
function visibilityOf(declaration) {
    if (declaration === undefined) return {};
    const flags = ts.getCombinedModifierFlags(declaration);
    if ((flags & ts.ModifierFlags.Private) !== 0) {
        return { visibility: "private" };
    }
    if ((flags & ts.ModifierFlags.Protected) !== 0) {
        return { visibility: "protected" };
    }
    return {};
}

/** Whether a deserialiser needs the key: not `?`, not a union with `undefined`, not a class field or parameter with an initializer. */
function isRequired(property, declaration) {
    if ((property.flags & ts.SymbolFlags.Optional) !== 0) return false;
    if (declaration === undefined) return true;
    if (
        (ts.isPropertyDeclaration(declaration) ||
            ts.isParameter(declaration)) &&
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
 * Whether the list may miss or misname a key: an index signature, a part the
 * checker cannot read, a decorator on the declaration or on a class an alias
 * intersects (or one it extends), or a property that is not plainly declared.
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
    const classes = [
        ...parts
            .flatMap((part) => [part, ...baseClasses(checker, part)])
            .flatMap((part) => part.symbol?.declarations ?? []),
        ...sourceDeclarations(checker, node, type),
    ];
    return (
        classes.some((declaration) => isDecoratedClass(checker, declaration)) ||
        parts.some((part) => hasUnknownSource(part)) ||
        checker
            .getPropertiesOfType(type)
            .some(
                (property) =>
                    isRenamable(checker, property) ||
                    isExpando(property) ||
                    inheritsParameter(property, node),
            )
    );
}

/**
 * Whether a JS file gives the property by assignment (`this.x = …`,
 * `Object.defineProperty`) rather than a declaration: typed by nothing, set
 * on any code path, so its key may or may not be filled.
 */
function isExpando(property) {
    return (property.declarations ?? []).some(
        (declaration) =>
            ts.isInJSFile(declaration) &&
            (ts.isBinaryExpression(declaration) ||
                ts.isPropertyAccessExpression(declaration) ||
                ts.isElementAccessExpression(declaration) ||
                ts.isCallExpression(declaration)),
    );
}

/**
 * Whether a property is inherited from a generic type and typed by one of
 * its parameters (`value: T` reached through `extends Gen<number>`): the
 * written type is not what the subclass holds, and the argument is not
 * substituted, so the list is not read as typed.
 */
function inheritsParameter(property, node) {
    const declaration = property.valueDeclaration ?? property.declarations?.[0];
    if (declaration?.type === undefined) return false;
    const owner = ts.isParameter(declaration)
        ? declaration.parent?.parent
        : declaration.parent;
    const names = new Set(
        (owner?.typeParameters ?? []).map((parameter) => parameter.name.text),
    );
    if (owner === node || names.size === 0) return false;
    const mentions = (written) =>
        (ts.isTypeReferenceNode(written) &&
            ts.isIdentifier(written.typeName) &&
            names.has(written.typeName.text)) ||
        ts.forEachChild(written, mentions) === true;
    return mentions(declaration.type);
}

/** Whether a declaration is a class that is decorated or extends a decorated class. */
function isDecoratedClass(checker, declaration) {
    if (!ts.isClassLike(declaration)) return false;
    if (isDecorated(declaration)) return true;
    const symbol =
        declaration.name === undefined
            ? undefined
            : checker.getSymbolAtLocation(declaration.name);
    return (
        symbol !== undefined &&
        baseClasses(checker, checker.getDeclaredTypeOfSymbol(symbol)).some(
            (base) =>
                (base.symbol?.declarations ?? []).some(
                    (inherited) =>
                        ts.isClassLike(inherited) && isDecorated(inherited),
                ),
        )
    );
}

/**
 * The declarations of every type a list is built from, beyond its own: the
 * type arguments a mapped type such as `Partial<Sub>` was made from, at any
 * depth, and every type the alias's right side or a heritage clause names,
 * through the aliases it names. A class-level decorator on such a source
 * may change which keys are serialised, though no field is declared there.
 */
function sourceDeclarations(checker, node, type) {
    const found = new Set();
    const seenTypes = new Set();
    const visitType = (current) => {
        if (current === undefined || seenTypes.has(current)) return;
        seenTypes.add(current);
        for (const declaration of current.symbol?.declarations ?? []) {
            found.add(declaration);
        }
        for (const argument of current.aliasTypeArguments ?? [])
            visitType(argument);
        for (const part of current.isUnionOrIntersection()
            ? current.types
            : []) {
            visitType(part);
        }
    };
    visitType(type);
    const seenAliases = new Set();
    const visitNode = (written) => {
        if (
            ts.isTypeReferenceNode(written) ||
            ts.isExpressionWithTypeArguments(written)
        ) {
            let symbol = checker.getSymbolAtLocation(
                ts.isTypeReferenceNode(written)
                    ? written.typeName
                    : written.expression,
            );
            if (
                symbol !== undefined &&
                (symbol.flags & ts.SymbolFlags.Alias) !== 0
            ) {
                symbol = checker.getAliasedSymbol(symbol);
            }
            for (const declaration of symbol?.declarations ?? []) {
                found.add(declaration);
                if (
                    ts.isTypeAliasDeclaration(declaration) &&
                    !seenAliases.has(declaration)
                ) {
                    seenAliases.add(declaration);
                    visitNode(declaration.type);
                }
            }
        }
        ts.forEachChild(written, visitNode);
    };
    if (ts.isTypeAliasDeclaration(node)) visitNode(node.type);
    for (const clause of node.heritageClauses ?? []) {
        for (const written of clause.types) visitNode(written);
    }
    return [...found];
}

/**
 * Whether a part is a mapped type whose source cannot be named: it is no
 * alias with type arguments (`Partial<T>`) and no alias whose right side can
 * be read (`type M = { [K in keyof T]: … }`), so a decorator on what it maps
 * cannot be looked for.
 */
function hasUnknownSource(part) {
    if (((part.objectFlags ?? 0) & ts.ObjectFlags.Mapped) === 0) return false;
    return (
        (part.aliasTypeArguments ?? []).length === 0 &&
        !(part.aliasSymbol?.declarations ?? []).some(ts.isTypeAliasDeclaration)
    );
}

/**
 * Whether a property's key may differ from its name or come from nowhere a
 * reader can see: it has no declaration in source (a key a mapped type makes
 * up, from `Record<"a" | "b", T>` or a template literal), or one of its
 * declarations sits in a class that is decorated or extends a decorated one.
 * A homomorphic mapped type (`Partial`, `Pick`, `Omit`, `Readonly`) keeps the
 * declarations of the property it maps, which is how a decorator under it is
 * found.
 */
function isRenamable(checker, property) {
    const declarations = property.declarations ?? [];
    if (declarations.length === 0) return true;
    return declarations.some((declaration) => {
        const owner = ts.isParameter(declaration)
            ? declaration.parent?.parent
            : declaration.parent;
        return owner !== undefined && isDecoratedClass(checker, owner);
    });
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
    const values = node.members.map((member) =>
        literalText(member.initializer),
    );
    const backed = values.every((value) => value !== null);
    const cases = node.members
        .map((member, index) => ({
            name: member.name
                .getText(node.getSourceFile())
                .replace(/^(['"])(.*)\1$/s, "$2"),
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
