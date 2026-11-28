/**
 * The test doubles of a test file (mocks, spies and stubs), as `test_double`
 * nodes with a `doubles` edge to what each stands in for.
 *
 * Ported from Momus's mock recognisers (`parser-typescript/src/mocks.ts`).
 * The Vitest and Jest forms it reads:
 *
 * - `vi.mock` / `jest.mock` / `jest.doMock`, the keys of their factory's
 *   object as members, an automock without a factory;
 * - `vi.importMock`, `jest.requireMock`, `jest.createMockFromModule` and
 *   `jest.genMockFromModule`, always automocks;
 * - `vi.stubGlobal('name', value)`;
 * - `vi.fn()` / `jest.fn()`;
 * - `vi.spyOn(object, 'member')` / `jest.spyOn`, its target the object's type;
 * - `vi.mocked(new X())` / `jest.mocked(x)` (`vi.mocked-instance`), and a
 *   project class's instance whose members the test configures;
 * - `{ m: vi.fn() }` object literals (`object-literal`) and
 *   `new Proxy({}, { get: () => vi.fn() })` (`proxy`), their target the type
 *   they are cast or annotated to.
 *
 * Where the source stops saying something, so does the fact: a computed
 * member name gives no member, a target the checker cannot name gives no
 * edge, and its pinned values are `"unknown"`.
 */

import ts from "typescript";
import { signatureOf } from "./signatures.js";
import { recordReach } from "./test-double-reach.js";
import {
    bindingOf,
    chainedImplementation,
    chainedPins,
    dottedName,
    factoryObject,
    functionArgument,
    isMockFactory,
    MOCKED,
    MODULE_AUTOMOCKS,
    MODULE_MOCKS,
    objectKey,
    PIN_APIS,
    rootIdentifier,
    SPIES,
    staticName,
    stripCast,
    writtenTypeReference,
} from "./test-double-syntax.js";
import {
    declaredInProject,
    moduleNamespace,
    namedTarget,
    ownerOf,
    pinnedValue,
} from "./test-double-types.js";
import { reference } from "./typescript-fact-utils.js";

/**
 * Emit the test doubles of `sourceFile` through `emit`, the file's fact
 * collector (`addNode`, `addEdge`, `symbolReference`, `moduleTarget`,
 * `relative`, `moduleId`, `root`), and return them, each with the local id
 * of its node, for the facts of the file's tests to name.
 */
export function collectTestDoubles(sourceFile, checker, emit) {
    const file = new TestDoubleFile(sourceFile, checker, emit);
    file.recognise();
    return file.emit();
}

class TestDoubleFile {
    constructor(sourceFile, checker, emit) {
        this.sourceFile = sourceFile;
        this.checker = checker;
        this.context = emit;
        this.doubles = [];
        // The variable's symbol -> the double it holds. A name reused across
        // tests is a symbol per test.
        this.bindings = new Map();
        // A spied-on object (its symbol, or its text when it has none) ->
        // the spies installed on it.
        this.spiedObjects = new Map();
        this.byFactory = new Map();
        this.fileFramework = fileFramework(sourceFile);
    }

    line(node) {
        return (
            this.sourceFile.getLineAndCharacterOfPosition(
                node.getStart(this.sourceFile),
            ).line + 1
        );
    }

    recognise() {
        const calls = (node) => {
            if (ts.isCallExpression(node)) this.call(node);
            ts.forEachChild(node, calls);
        };
        calls(this.sourceFile);
        const declarations = (node) => {
            if (
                ts.isVariableDeclaration(node) &&
                ts.isIdentifier(node.name) &&
                node.initializer !== undefined
            )
                this.declaration(node);
            ts.forEachChild(node, declarations);
        };
        declarations(this.sourceFile);
        this.pins();
        // A project instance is a double only once the test configures it.
        this.doubles = this.doubles.filter(
            (double) => !double.implicit || double.members.length > 0,
        );
        recordReach(this.sourceFile, {
            bound: (identifier) => this.bound(identifier),
            made: (call) => this.byFactory.get(call),
            spied: (object) =>
                this.spiedObjects.get(this.objectKey(object)) ?? [],
        });
    }

    add(node, fields) {
        const double = {
            node,
            framework: frameworkOf(fields.pattern) ?? this.fileFramework,
            automock: false,
            members: [],
            owner: null,
            edge: null,
            implicit: false,
            sites: new Set(),
            ...fields,
        };
        this.doubles.push(double);
        return double;
    }

    bind(name, double) {
        const symbol = name && this.checker.getSymbolAtLocation(name);
        if (symbol === undefined) return;
        this.bindings.set(symbol, double);
        // The test-case facts name a double by the variable that holds it.
        double.binding = symbol;
    }

    /** The double an identifier holds, read as the value a shorthand property copies. */
    bound(identifier) {
        const parent = identifier.parent;
        const symbol =
            parent !== undefined &&
            ts.isShorthandPropertyAssignment(parent) &&
            parent.name === identifier
                ? this.checker.getShorthandAssignmentValueSymbol(parent)
                : this.checker.getSymbolAtLocation(identifier);
        return symbol === undefined ? undefined : this.bindings.get(symbol);
    }

    /** What names a spied-on object: its variable's symbol, else its text. */
    objectKey(expression) {
        return objectKey(this.checker, this.sourceFile, expression);
    }

    call(node) {
        const name = dottedName(node.expression);
        if (name === null) return;
        if (MODULE_MOCKS.has(name) || MODULE_AUTOMOCKS.has(name))
            this.moduleMock(node, name);
        else if (name === "vi.stubGlobal") this.stubGlobal(node);
        else if (isMockFactory(node)) {
            const double = this.add(node, {
                pattern: name,
                target: { kind: "unknown" },
            });
            this.byFactory.set(node, double);
            this.bind(bindingOf(node), double);
        } else if (SPIES.has(name)) this.spy(node, name);
        else if (MOCKED.has(name)) this.mocked(node);
    }

    moduleMock(node, name) {
        const [specifier, factory, options] = node.arguments;
        const literal =
            specifier !== undefined && ts.isStringLiteralLike(specifier);
        // A virtual module (`{ virtual: true }`) exists only as the mock: its
        // specifier names no file to hold the double against, so none is kept.
        const named = literal && !isVirtual(options);
        const automock = MODULE_AUTOMOCKS.has(name) || factory === undefined;
        const members = [];
        for (const property of factoryObject(factory)?.properties ?? []) {
            const key = ts.isPropertyAssignment(property)
                ? staticName(property.name)
                : null;
            if (key !== null)
                members.push({
                    name: key,
                    nameNode: property.name,
                    api: "mockFactoryKey",
                    pins: [],
                });
        }
        const double = this.add(node, {
            pattern: name,
            target: named
                ? { kind: "module", specifier: specifier.text }
                : { kind: literal ? "module" : "unknown" },
            automock,
            members,
            edge: named
                ? this.context.moduleTarget(specifier.text, specifier)
                : null,
        });
        if (MODULE_AUTOMOCKS.has(name)) this.bind(bindingOf(node), double);
    }

    stubGlobal(node) {
        const [name] = node.arguments;
        if (name === undefined || !ts.isStringLiteralLike(name)) return;
        this.add(node, {
            pattern: "vi.stubGlobal",
            target: { kind: "global", export: name.text },
        });
    }

    spy(node, pattern) {
        const [object, member] = node.arguments;
        if (object === undefined || member === undefined) return;
        const memberName = ts.isStringLiteralLike(member) ? member.text : null;
        // `vi.spyOn(ns, 'f')` on `import * as ns` replaces a module's export.
        const namespace = moduleNamespace(this.checker, object);
        const owner = namespace?.owner ?? ownerOf(this.checker, object);
        const written = owner?.name ?? castName(object, this.sourceFile);
        const implementation = chainedImplementation(node);
        const memberTarget = memberName === null ? {} : { member: memberName };
        const double = this.add(node, {
            pattern,
            target:
                namespace !== null
                    ? {
                          kind: "module",
                          specifier: namespace.specifier.text,
                          ...memberTarget,
                      }
                    : {
                          kind: "instance-member",
                          ...(written === undefined ? {} : { export: written }),
                          ...memberTarget,
                      },
            owner,
            // What names the spied-on object, for a matcher on its member.
            spiedKey: this.objectKey(object),
            edge:
                namespace !== null
                    ? this.context.moduleTarget(
                          namespace.specifier.text,
                          namespace.specifier,
                      )
                    : this.edgeTo(owner),
            members:
                memberName === null
                    ? []
                    : [
                          {
                              name: memberName,
                              nameNode: member,
                              api: "spyOn",
                              implementation,
                              pins: chainedPins(node),
                          },
                      ],
        });
        this.bind(bindingOf(node), double);
        const key = this.objectKey(object);
        this.spiedObjects.set(key, [
            ...(this.spiedObjects.get(key) ?? []),
            double,
        ]);
    }

    mocked(node) {
        const inner = node.arguments[0] && stripCast(node.arguments[0]);
        if (
            inner === undefined ||
            (!ts.isNewExpression(inner) && !ts.isIdentifier(inner))
        )
            return;
        const owner = ownerOf(this.checker, inner);
        const written = owner?.name ?? castName(inner, this.sourceFile);
        if (written === undefined) return;
        const double = this.add(node, {
            pattern: "vi.mocked-instance",
            framework: frameworkOf(dottedName(node.expression)),
            target: { kind: "class", export: written },
            owner,
            edge: this.edgeTo(owner),
            instance: true,
        });
        this.bind(bindingOf(node), double);
    }

    declaration(node) {
        const value = stripCast(node.initializer);
        if (
            ts.isNewExpression(value) &&
            dottedName(value.expression) === "Proxy"
        ) {
            const factory = proxyFactory(value);
            if (factory !== undefined)
                this.typedLiteral(node, "proxy", [], factory);
        } else if (ts.isObjectLiteralExpression(value)) {
            this.objectLiteral(node, value);
        } else if (ts.isNewExpression(value)) {
            const owner = namedTarget(this.checker.getTypeAtLocation(value));
            if (owner === null || !declaredInProject(this.context.root, owner))
                return;
            const double = this.add(node, {
                pattern: "vi.mocked-instance",
                target: { kind: "class", export: owner.name },
                owner,
                edge: this.edgeTo(owner),
                instance: true,
                implicit: true,
            });
            this.bind(node.name, double);
        }
    }

    objectLiteral(node, literal) {
        const members = [];
        let factory;
        for (const property of literal.properties) {
            if (!ts.isPropertyAssignment(property)) continue;
            const value = stripCast(property.initializer);
            if (!isMockFactory(value)) continue;
            factory ??= value;
            const key = staticName(property.name);
            if (key !== null)
                members.push({
                    name: key,
                    nameNode: property.name,
                    api: "objectLiteralKey",
                    implementation: functionArgument(value.arguments[0]),
                    pins: [],
                });
        }
        if (factory !== undefined)
            this.typedLiteral(node, "object-literal", members, factory);
    }

    /** An object-literal or proxy double, its target the type it is cast or annotated to. */
    typedLiteral(node, pattern, members, factory) {
        const written = writtenTypeReference(node);
        const owner =
            written === undefined
                ? null
                : namedTarget(this.checker.getTypeFromTypeNode(written));
        const name = owner?.name ?? written?.typeName.getText(this.sourceFile);
        const double = this.add(node, {
            pattern,
            framework: frameworkOf(dottedName(factory.expression)),
            target:
                name === undefined
                    ? { kind: "unknown" }
                    : { kind: "class", export: name },
            owner,
            edge: this.edgeTo(owner),
            members,
        });
        this.bind(node.name, double);
    }

    /**
     * The pins applied after a double is bound: `spy.mockReturnValue(1)`
     * on a spy's one member, `mocked.member.mockResolvedValue(x)` on a
     * member of an object-literal or instance double.
     */
    pins() {
        const visit = (node) => {
            if (
                ts.isCallExpression(node) &&
                ts.isPropertyAccessExpression(node.expression) &&
                PIN_APIS.has(node.expression.name.text)
            )
                this.pin(node, node.expression.expression);
            ts.forEachChild(node, visit);
        };
        visit(this.sourceFile);
    }

    pin(call, base) {
        const owner = rootIdentifier(base);
        const double = owner && this.bound(owner);
        if (double === undefined) return;
        const pin = { api: call.expression.name.text, call };
        if (ts.isIdentifier(base)) {
            if (SPIES.has(double.pattern)) addPin(double.members[0], pin);
            return;
        }
        if (!ts.isPropertyAccessExpression(base)) return;
        const name = base.name.text;
        let member = double.members.find((item) => item.name === name);
        if (member === undefined && double.instance) {
            member = {
                name,
                nameNode: base.name,
                api: "instance-member",
                pins: [],
            };
            double.members.push(member);
        }
        addPin(member, pin);
    }

    /** The `doubles` edge target: the node of a named type, in the project or not. */
    edgeTo(owner) {
        return owner === null
            ? null
            : this.context.symbolReference(owner.symbol, "class");
    }

    emit() {
        const context = this.context;
        const sorted = [...this.doubles].sort(
            (a, b) =>
                a.node.getStart(this.sourceFile) -
                b.node.getStart(this.sourceFile),
        );
        const emitted = [];
        for (const double of sorted) {
            if (double.framework === null) continue;
            const start = this.sourceFile.getLineAndCharacterOfPosition(
                double.node.getStart(this.sourceFile),
            );
            const canonical = `${context.relative}#double:${start.line + 1}:${start.character + 1}`;
            const id = reference("test_double", canonical);
            const kept = context.addNode(
                id,
                "test_double",
                canonical,
                displayName(double),
                double.node,
                {
                    framework: double.framework,
                    pattern: double.pattern,
                    target: double.target,
                    automock: double.automock,
                    invocations: double.sites.size,
                    members: double.members.map((member) =>
                        this.member(double, member),
                    ),
                },
            );
            if (!kept) continue;
            context.addEdge("contains", context.moduleId, id, double.node);
            if (double.edge !== null)
                context.addEdge("doubles", id, double.edge, double.node);
            emitted.push({ id, canonical, ...double });
        }
        return emitted;
    }

    member(double, member) {
        const owner =
            double.owner !== null &&
            declaredInProject(this.context.root, double.owner)
                ? double.owner
                : null;
        const signature =
            member.implementation === undefined
                ? undefined
                : signatureOf(member.implementation, this.sourceFile);
        return {
            name: member.name,
            line: this.line(member.nameNode),
            api: member.api,
            ...(signature === undefined ? {} : { params: signature.params }),
            returns: member.pins.map((pin) =>
                pinnedValue(
                    this.checker,
                    this.sourceFile,
                    pin,
                    owner,
                    member.name,
                ),
            ),
        };
    }
}

/** Add a pin once: a chained pin is also met again as a pin through a binding. */
function addPin(member, pin) {
    if (
        member !== undefined &&
        !member.pins.some((item) => item.call === pin.call)
    )
        member.pins.push(pin);
}

/** The framework a pattern's own call names, or null for one it does not. */
function frameworkOf(name) {
    if (name?.startsWith("vi.")) return "vitest";
    if (name?.startsWith("jest.")) return "jest";
    return null;
}

/** The framework a file imports, or null when it imports neither. */
function fileFramework(sourceFile) {
    for (const statement of sourceFile.statements) {
        if (
            !ts.isImportDeclaration(statement) ||
            !ts.isStringLiteral(statement.moduleSpecifier)
        )
            continue;
        const specifier = statement.moduleSpecifier.text;
        if (specifier === "vitest" || specifier.startsWith("@vitest/"))
            return "vitest";
        if (specifier === "@jest/globals" || specifier === "jest")
            return "jest";
    }
    return null;
}

/**
 * The `vi.fn()` a `new Proxy(target, { get })` handler makes; undefined for
 * a proxy whose `get` makes no mock.
 */
function proxyFactory(proxy) {
    const handler = proxy.arguments?.[1];
    if (handler === undefined || !ts.isObjectLiteralExpression(handler))
        return undefined;
    const get = handler.properties.find(
        (property) =>
            (ts.isMethodDeclaration(property) ||
                ts.isPropertyAssignment(property)) &&
            staticName(property.name) === "get",
    );
    let factory;
    const visit = (node) => {
        if (factory !== undefined) return;
        if (isMockFactory(node)) factory = node;
        else ts.forEachChild(node, visit);
    };
    if (get !== undefined) visit(get);
    return factory;
}

/** The type name a cast writes, `X` in `y as X`, for a type the checker cannot name. */
function castName(expression, sourceFile) {
    let value = expression;
    while (ts.isParenthesizedExpression(value)) value = value.expression;
    if (!ts.isAsExpression(value)) return undefined;
    return ts.isTypeReferenceNode(value.type)
        ? value.type.typeName.getText(sourceFile)
        : castName(value.expression, sourceFile);
}

/** Whether a module mock's options are an object literal setting `virtual: true`. */
function isVirtual(options) {
    const object = options === undefined ? undefined : stripCast(options);
    return (
        object !== undefined &&
        ts.isObjectLiteralExpression(object) &&
        object.properties.some(
            (property) =>
                ts.isPropertyAssignment(property) &&
                staticName(property.name) === "virtual" &&
                property.initializer.kind === ts.SyntaxKind.TrueKeyword,
        )
    );
}

function displayName(double) {
    const { export: name, member, specifier } = double.target;
    const subject = [specifier ?? name, member].filter(Boolean).join(".");
    return subject === "" ? double.pattern : `${double.pattern} ${subject}`;
}
