import path from "node:path";
import ts from "typescript";
import { objectField } from "./syntax-predicates.js";
import {
    addFrameworkRoute,
    callName,
    declarationModifiers,
    isFunctionBinding,
    propertyNameText,
    reference,
    unwrapExpression,
} from "./typescript-fact-utils.js";

/** Adds framework-convention facts without owning or traversing the AST. */
export class TypeScriptApplicationEnricher {
    constructor(context) {
        this.context = context;
    }

    declaration(node, id, canonical, name) {
        const roles = [];
        const lower = this.context.relative.toLowerCase();
        const base = path.basename(lower).replace(/\.(tsx?|jsx?)$/, "");
        const exported =
            hasModifier(node, ts.SyntaxKind.ExportKeyword) ||
            hasModifier(node, ts.SyntaxKind.DefaultKeyword);
        if (base === "page" && exported) roles.push("nextjs.page");
        if (base === "layout" && exported) roles.push("nextjs.layout");
        if (
            base === "route" &&
            exported &&
            [
                "GET",
                "POST",
                "PUT",
                "PATCH",
                "DELETE",
                "HEAD",
                "OPTIONS",
            ].includes(name)
        ) {
            roles.push("nextjs.route_handler");
            const routePath = nextRoutePath(this.context.relative);
            const routeCanonical = `${name} ${routePath} => ${canonical}`;
            const routeId = reference("route", routeCanonical);
            addFrameworkRoute(this.context, {
                id: routeId,
                canonical: routeCanonical,
                displayName: `${name} ${routePath}`,
                node,
                framework: "nextjs",
                httpMethod: name,
                path: routePath,
                target: id,
            });
        }
        const functionNode = isFunctionLike(node)
            ? node
            : ts.isVariableDeclaration(node) &&
                node.initializer &&
                isFunctionLike(unwrapExpression(node.initializer))
              ? unwrapExpression(node.initializer)
              : null;
        if (functionNode && hasUseDirective(functionNode, "use server"))
            roles.push("nextjs.server_action");
        roles.push(
            ...componentRoles(
                this.context.sourceFile,
                name,
                functionNode !== null || ts.isClassDeclaration(node),
                functionNode !== null,
                node,
            ),
        );
        const initializer = ts.isVariableDeclaration(node)
            ? node.initializer
            : null;
        const factory =
            initializer && ts.isCallExpression(initializer)
                ? callName(initializer.expression)
                : null;
        if (factory === "defineComponent") roles.push("vue.component");
        if (
            ["defineStore", "createStore", "configureStore", "create"].includes(
                factory,
            )
        )
            roles.push("state.store");
        return [...new Set(roles)].sort();
    }

    variable(node) {
        // A module-level function binding is a declaration of its own, and
        // declaration() gives it these roles; a node here would be a second
        // one under the same name.
        if (isFunctionBinding(node)) return;
        if (!ts.isIdentifier(node.name) || !node.initializer) return;
        const initializer = node.initializer;
        const factory = ts.isCallExpression(initializer)
            ? callName(initializer.expression)
            : null;
        const functionNode = isFunctionLike(initializer) ? initializer : null;
        const roles = [];
        if (factory === "defineComponent") roles.push("vue.component");
        if (
            ["defineStore", "createStore", "configureStore", "create"].includes(
                factory,
            )
        )
            roles.push("state.store");
        if (functionNode)
            roles.push(
                ...componentRoles(
                    this.context.sourceFile,
                    node.name.text,
                    true,
                    true,
                    functionNode,
                ),
            );
        if (functionNode && hasUseDirective(functionNode, "use server"))
            roles.push("nextjs.server_action");
        if (roles.length === 0) return;
        const canonical = `${this.context.relative}#${node.name.text}`;
        const id = reference("variable", canonical);
        this.context.addNode(id, "variable", canonical, node.name.text, node, {
            typescript_framework_roles: [...new Set(roles)].sort(),
        });
        this.context.addEdge("contains", this.context.moduleId, id, node);
    }

    call(node, source, calledName) {
        if (source === null) return;
        if (ts.isPropertyAccessExpression(node.expression)) {
            const name = node.expression.name.text;
            if (INSTANCE_METHODS.has(name) || name === "request") {
                const instance = axiosInstanceBase(
                    this.context.checker,
                    node.expression.expression,
                );
                if (instance !== null) {
                    this.instanceCall(node, source, instance);
                    return;
                }
            }
        }
        if (
            ![
                "fetch",
                "axios.get",
                "axios.post",
                "axios.put",
                "axios.patch",
                "axios.delete",
                "axios.head",
                "axios.request",
            ].includes(calledName) ||
            !node.arguments[0]
        )
            return;
        if (calledName === "axios.request") {
            this.instanceCall(node, source, null);
            return;
        }
        const url = requestUrl(node.arguments[0]);
        if (url === null) return;
        const method =
            calledName === "fetch"
                ? fetchMethod(node)
                : calledName.split(".").at(-1).toUpperCase();
        this.addEndpoint(node, source, method, url);
    }

    /**
     * A request through an axios instance, or through the direct import when
     * `instance` is null: then the URL stands as written.
     */
    instanceCall(node, source, instance) {
        const name = node.expression.name.text;
        const first = node.arguments[0];
        let method;
        let argument;
        if (INSTANCE_METHODS.has(name)) {
            method = name.toUpperCase();
            argument = first;
        } else if (first && ts.isObjectLiteralExpression(first)) {
            method = optionMethod(first) ?? "GET";
            argument = objectField(first, "url");
        }
        const url = argument === undefined ? null : requestUrl(argument);
        if (url === null) return;
        this.addEndpoint(
            node,
            source,
            method,
            instance === null ? url : joinBase(instance, url),
        );
    }

    addEndpoint(node, source, method, url) {
        const attributes = {
            framework: "web",
            method,
            uri: url.uri,
            template: urlTemplate(
                url.uri,
                url.dropLeading ?? url.prefixUnknown,
            ),
            prefix: url.prefixUnknown ? "unknown" : "known",
            external: ABSOLUTE_URL.test(url.uri),
        };
        const endpointId = reference("endpoint", `${method} ${url.uri}`);
        this.context.addNode(
            endpointId,
            "endpoint",
            `${method} ${url.uri}`,
            `${method} ${url.uri}`,
            node,
            attributes,
            "framework_convention",
        );
        this.context.addEdge(
            "calls_endpoint",
            source,
            endpointId,
            node,
            {},
            "framework_convention",
        );
    }
}

const ABSOLUTE_URL = /^([a-z][a-z0-9+.-]*:|\/\/)/i;

/**
 * A request URL as written, each substitution shown as `{}`, and whether
 * anything before its path is unknown: a leading substitution names a base
 * the worker cannot read. Null for anything but a string or template literal.
 */
function requestUrl(argument) {
    if (
        ts.isStringLiteral(argument) ||
        ts.isNoSubstitutionTemplateLiteral(argument)
    )
        return { uri: argument.text, prefixUnknown: false };
    if (!ts.isTemplateExpression(argument)) return null;
    const uri =
        argument.head.text +
        argument.templateSpans.map((span) => `{}${span.literal.text}`).join("");
    if (uri.replaceAll("{}", "") === "") return null;
    return { uri, prefixUnknown: argument.head.text === "" };
}

const INSTANCE_METHODS = new Set([
    "get",
    "post",
    "put",
    "patch",
    "delete",
    "head",
]);

/**
 * The `baseURL` of an axios instance a receiver resolves to through the
 * checker, as `{ base, known }`, or null when the receiver is no
 * `axios.create(...)` result. A relative literal base is known; a missing,
 * absolute or computed one is not.
 */
function axiosInstanceBase(checker, receiver) {
    let symbol = checker.getSymbolAtLocation(receiver);
    if (symbol && symbol.flags & ts.SymbolFlags.Alias)
        symbol = checker.getAliasedSymbol(symbol);
    const declaration = symbol?.valueDeclaration;
    // `export default axios.create({ ... })` is an export assignment, not a variable.
    let initializer;
    if (declaration && ts.isVariableDeclaration(declaration))
        initializer = declaration.initializer;
    else if (declaration && ts.isExportAssignment(declaration))
        initializer = declaration.expression;
    if (!initializer || !isAxiosCreate(checker, initializer)) return null;
    const options = initializer.arguments[0];
    const base = options ? objectField(options, "baseURL") : undefined;
    if (
        base !== undefined &&
        ts.isStringLiteralLike(base) &&
        !ABSOLUTE_URL.test(base.text)
    )
        return { base: base.text.replace(/\/+$/, ""), known: true };
    return { base: "", known: false };
}

/** A `<name>.create(...)` call whose receiver is an import of `axios`. */
function isAxiosCreate(checker, call) {
    if (
        !ts.isCallExpression(call) ||
        !ts.isPropertyAccessExpression(call.expression) ||
        call.expression.name.text !== "create"
    )
        return false;
    const symbol = checker.getSymbolAtLocation(call.expression.expression);
    return (symbol?.declarations ?? []).some((declaration) => {
        const statement = ts.findAncestor(declaration, ts.isImportDeclaration);
        return (
            statement !== undefined &&
            ts.isStringLiteral(statement.moduleSpecifier) &&
            statement.moduleSpecifier.text === "axios"
        );
    });
}

/**
 * The URL an instance call requests: axios ignores `baseURL` for an absolute
 * URL, and otherwise joins the two with exactly one slash. An unknown base
 * leads the uri as a `{}` segment, as a leading substitution does, so the
 * endpoint is never confused with a direct request to the same path.
 */
function joinBase(instance, url) {
    if (ABSOLUTE_URL.test(url.uri)) return url;
    const path = url.uri.replace(/^\/+/, "");
    if (instance.known)
        return {
            uri: `${instance.base}/${path}`,
            prefixUnknown: false,
            dropLeading: false,
        };
    return { uri: `{}/${path}`, prefixUnknown: true, dropLeading: true };
}

/** The path template a URL stands for, as the core normalises it. */
function urlTemplate(uri, prefixUnknown) {
    const path = uri.replace(/[?#].*$/s, "");
    const segments = path
        .split("/")
        .filter((segment) => segment !== "")
        .map((segment) => (segment.includes("{}") ? "{}" : segment));
    const kept = prefixUnknown ? segments.slice(1) : segments;
    return `/${kept.join("/")}`;
}

function fetchMethod(call) {
    const options = call.arguments[1];
    if (!options || !ts.isObjectLiteralExpression(options)) return "GET";
    return optionMethod(options) ?? "GET";
}

/**
 * The method an options object names: undefined when it names none, the
 * upper-cased text of a literal, and `ANY` for one read from a variable,
 * since any method may be sent.
 */
function optionMethod(options) {
    const property = options.properties.find(
        (item) =>
            (ts.isPropertyAssignment(item) ||
                ts.isShorthandPropertyAssignment(item)) &&
            propertyNameText(item.name) === "method",
    );
    if (!property) return undefined;
    return ts.isPropertyAssignment(property) &&
        ts.isStringLiteralLike(property.initializer)
        ? property.initializer.text.toUpperCase()
        : "ANY";
}

function hasModifier(node, kind) {
    return declarationModifiers(node).some(
        (modifier) => modifier.kind === kind,
    );
}

function isFunctionLike(node) {
    return (
        ts.isFunctionDeclaration(node) ||
        ts.isMethodDeclaration(node) ||
        ts.isArrowFunction(node) ||
        ts.isFunctionExpression(node)
    );
}

/**
 * The React/Vue roles a named declaration earns, if any.
 *
 * Each rule is keyed on evidence from the code rather than on the file's name
 * or extension: a component renders JSX, and a `use`-prefixed function is a
 * hook or a composable according to which library the file actually imports.
 */
function componentRoles(sourceFile, name, componentShaped, callable, body) {
    const roles = [];
    if (componentShaped && /^[A-Z]/.test(name) && containsJsx(body))
        roles.push("react.component");
    if (callable && /^use[A-Z0-9]/.test(name)) {
        if (importsModule(sourceFile, "react")) roles.push("react.hook");
        if (importsModule(sourceFile, "vue")) roles.push("vue.composable");
    }
    return roles;
}

/**
 * Whether a declaration actually contains JSX.
 *
 * The TypeScript compiler reports languageVariant JSX for every .js, .jsx, .mjs
 * and .cjs file — only .ts is Standard — so that flag cannot distinguish a
 * React component from any other capitalized declaration in a JavaScript
 * project. Looking for JSX in the declaration itself does, and it keeps working
 * for the plenty of real React code that lives in plain .js files.
 */
function containsJsx(node) {
    let found = false;
    const walk = (current) => {
        if (found) return;
        if (
            ts.isJsxElement(current) ||
            ts.isJsxSelfClosingElement(current) ||
            ts.isJsxFragment(current)
        ) {
            found = true;
            return;
        }
        ts.forEachChild(current, walk);
    };
    ts.forEachChild(node, walk);
    return found;
}

/**
 * Whether the file imports the given package, by import declaration or require.
 *
 * A hook and a composable are both just a `use`-prefixed function; only the
 * library in scope says which — or whether it is neither, as with a
 * `useDatabase()` helper in a plain Node service.
 */
function importsModule(sourceFile, packageName) {
    const cache = (sourceFile.knossosImportedModules ??=
        collectImports(sourceFile));
    return cache.has(packageName);
}

function collectImports(sourceFile) {
    const modules = new Set();
    const record = (specifier) => {
        if (typeof specifier !== "string" || specifier === "") return;
        // "react-dom/client" and "react/jsx-runtime" both count as react.
        modules.add(specifier.split("/")[0].replace(/^(@[^/]+)$/, "$1"));
    };
    const walk = (node) => {
        if (
            (ts.isImportDeclaration(node) || ts.isExportDeclaration(node)) &&
            node.moduleSpecifier &&
            ts.isStringLiteral(node.moduleSpecifier)
        ) {
            record(node.moduleSpecifier.text);
        } else if (
            ts.isCallExpression(node) &&
            (node.expression.kind === ts.SyntaxKind.ImportKeyword ||
                (ts.isIdentifier(node.expression) &&
                    node.expression.text === "require")) &&
            node.arguments[0] &&
            ts.isStringLiteral(node.arguments[0])
        ) {
            record(node.arguments[0].text);
        }
        ts.forEachChild(node, walk);
    };
    walk(sourceFile);
    return modules;
}

function hasUseDirective(node, directive) {
    return (
        node.body &&
        ts.isBlock(node.body) &&
        node.body.statements.some(
            (statement) =>
                ts.isExpressionStatement(statement) &&
                ts.isStringLiteral(statement.expression) &&
                statement.expression.text === directive,
        )
    );
}

function nextRoutePath(relative) {
    const normalized = relative.replaceAll("\\", "/");
    const marker = normalized.includes("/app/")
        ? normalized.split("/app/").at(-1)
        : normalized.replace(/^app\//, "");
    const parts = marker
        .split("/")
        .slice(0, -1)
        .filter((part) => !/^\(.+\)$/.test(part));
    return `/${parts.join("/")}`.replaceAll("//", "/") || "/";
}
