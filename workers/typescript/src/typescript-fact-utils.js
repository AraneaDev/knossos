import ts from "typescript";

export function callName(expression) {
    if (ts.isIdentifier(expression)) return expression.text;
    if (ts.isPropertyAccessExpression(expression)) {
        const owner = callName(expression.expression);
        return owner
            ? `${owner}.${expression.name.text}`
            : expression.name.text;
    }
    return null;
}

export function propertyNameText(name) {
    return ts.isIdentifier(name) ||
        ts.isStringLiteral(name) ||
        ts.isNumericLiteral(name)
        ? name.text
        : null;
}

export function reference(kind, canonical) {
    return `ts:${kind}:${canonical}`;
}

export function addFrameworkRoute(
    context,
    { id, canonical, displayName, node, framework, httpMethod, path, target },
) {
    context.addNode(
        id,
        "route",
        canonical,
        displayName,
        node,
        {
            framework,
            http_methods: [httpMethod],
            path,
        },
        "framework_convention",
    );
    context.addEdge(
        "routes_to",
        id,
        target,
        node,
        { framework },
        "framework_convention",
    );
}

/** Parentheses, `as` or `satisfies`: syntax around a value that is not a value of its own. */
export function isExpressionWrapper(node) {
    return (
        ts.isParenthesizedExpression(node) ||
        ts.isAsExpression(node) ||
        ts.isSatisfiesExpression(node)
    );
}

/** An expression's value through parentheses, `as` and `satisfies`. */
export function unwrapExpression(expression) {
    let current = expression;
    while (current !== undefined && isExpressionWrapper(current))
        current = current.expression;
    return current;
}

/** The node holding an expression, past any parentheses, `as` and `satisfies` around it. */
export function holderOf(node) {
    let current = node.parent;
    while (current !== undefined && isExpressionWrapper(current))
        current = current.parent;
    return current;
}

/**
 * `const f = () => {}` or `var f = function () {}` as a statement of the
 * module itself: a function written as a binding, which the graph holds as
 * the function it is, and so is one an immediately invoked function builds
 * (see `builtFunction`). Bindings inside a function or block, and initializers
 * that hand the function to any other call, are left alone.
 */
export function isFunctionBinding(node) {
    if (!ts.isVariableDeclaration(node) || !ts.isIdentifier(node.name))
        return false;
    const list = node.parent;
    const statement = list?.parent;
    if (
        list === undefined ||
        !ts.isVariableDeclarationList(list) ||
        statement === undefined ||
        !ts.isVariableStatement(statement) ||
        statement.parent === undefined ||
        !ts.isSourceFile(statement.parent)
    )
        return false;
    const value = unwrapExpression(node.initializer);
    return (
        value !== undefined &&
        (ts.isArrowFunction(value) ||
            ts.isFunctionExpression(value) ||
            builtFunction(value) !== null)
    );
}

/**
 * The function an immediately invoked function builds and returns, or null:
 * `(function () { const f = () => {}; return f; })()`. The module name it is
 * bound to is that function, built once in a closure; any other call in an
 * initializer is left alone, since what it returns is not known here.
 */
export function builtFunction(value) {
    if (value === undefined || !ts.isCallExpression(value)) return null;
    const callee = unwrapExpression(value.expression);
    if (
        callee === undefined ||
        !(ts.isArrowFunction(callee) || ts.isFunctionExpression(callee)) ||
        !ts.isBlock(callee.body)
    )
        return null;
    const statements = callee.body.statements;
    const returned = statements.find(ts.isReturnStatement)?.expression;
    const name = unwrapExpression(returned);
    if (name === undefined || !ts.isIdentifier(name)) return null;
    for (const statement of statements) {
        if (
            ts.isFunctionDeclaration(statement) &&
            statement.name?.text === name.text
        )
            return statement;
        if (!ts.isVariableStatement(statement)) continue;
        for (const declaration of statement.declarationList.declarations) {
            if (!ts.isIdentifier(declaration.name)) continue;
            if (declaration.name.text !== name.text) continue;
            const inner = unwrapExpression(declaration.initializer);
            return inner !== undefined &&
                (ts.isArrowFunction(inner) || ts.isFunctionExpression(inner))
                ? inner
                : null;
        }
    }
    return null;
}

/**
 * The module binding whose immediately invoked function builds and returns
 * `node`, or null. Calling the binding is calling that function.
 */
export function builderOf(node) {
    const callee = node.parent?.parent;
    if (
        callee === undefined ||
        !(ts.isArrowFunction(callee) || ts.isFunctionExpression(callee))
    )
        return null;
    const binding = holderOf(holderOf(callee) ?? callee);
    return binding !== undefined &&
        isFunctionBinding(binding) &&
        builtFunction(unwrapExpression(binding.initializer)) === node
        ? binding
        : null;
}

/**
 * `private initializeScene = async () => { ... }`: a class property that holds
 * a function, the way a React class component writes a method bound to its
 * instance. It is a method in all but syntax, and the calls in its body are
 * its own rather than the class's.
 */
export function isFunctionProperty(node) {
    if (!ts.isPropertyDeclaration(node)) return false;
    const value = unwrapExpression(node.initializer);
    return (
        value !== undefined &&
        (ts.isArrowFunction(value) || ts.isFunctionExpression(value))
    );
}

/** The function binding an arrow or function expression initialises, or null. */
export function functionBindingOf(node) {
    if (
        node === undefined ||
        !(ts.isArrowFunction(node) || ts.isFunctionExpression(node))
    )
        return null;
    const current = holderOf(node);
    return current !== undefined &&
        isFunctionBinding(current) &&
        unwrapExpression(current.initializer) === node
        ? current
        : null;
}

/** The keyword a variable declaration is bound with. */
export function bindingKeyword(declaration) {
    const flags = declaration.parent.flags;
    // `await using` carries the Const bit, so the two `using` forms are told
    // apart first.
    if ((flags & ts.NodeFlags.AwaitUsing) === ts.NodeFlags.AwaitUsing)
        return "await using";
    if ((flags & ts.NodeFlags.Using) !== 0) return "using";
    if ((flags & ts.NodeFlags.Const) !== 0) return "const";
    if ((flags & ts.NodeFlags.Let) !== 0) return "let";
    return "var";
}

/**
 * A declaration's modifiers. `export` and `default` on a variable sit on its
 * statement, not on the declaration, so every binding read as not exported.
 */
export function declarationModifiers(node) {
    const owner =
        ts.isVariableDeclaration(node) &&
        node.parent !== undefined &&
        ts.isVariableDeclarationList(node.parent) &&
        node.parent.parent !== undefined &&
        ts.isVariableStatement(node.parent.parent)
            ? node.parent.parent
            : node;
    return ts.canHaveModifiers(owner) ? (ts.getModifiers(owner) ?? []) : [];
}
