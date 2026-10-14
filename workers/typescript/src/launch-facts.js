import ts from "typescript";
import { reference } from "./typescript-fact-utils.js";

/** Programs that run the script named after them, by basename: an argv starting with one launches that script. */
const INTERPRETERS = new Set([
    "php",
    "node",
    "nodejs",
    "bun",
    "deno",
    "python",
    "python3",
    "ruby",
    "perl",
    "bash",
    "sh",
    "zsh",
]);

/** A file name a script is run by, so a path without a directory still names a file. */
const SCRIPT = /\.(?:py|js|mjs|cjs|ts|php|rb|pl|sh)$/;

/** The child_process functions that take a program and its arguments. */
const STARTERS = new Set(["spawn", "spawnSync", "execFile", "execFileSync"]);

/**
 * The files the processes a file starts run: an argv array starting with an
 * interpreter (`["node", path]`) or with a path joined to a root, a
 * `spawn`/`execFile` call whose program is an interpreter, and `fork(path)`.
 * Each is a speculative `launches` edge naming the file by path, which the
 * core resolves to that file's module and drops when the project holds no
 * such file. Where the process is started does not matter: the code naming
 * what runs is what a change to that file affects.
 */
export class LaunchFacts {
    constructor(context) {
        this.context = context;
    }

    array(node) {
        const [first, ...rest] = node.elements;
        if (first === undefined) return;
        const interpreter = isInterpreter(first);
        for (const value of interpreter ? rest : [first]) {
            const path = argvPath(value, !interpreter);
            if (path !== null) {
                this.emit(path, node);
                return;
            }
        }
    }

    call(node) {
        const name = calleeName(node.expression);
        if (name === "fork" && node.arguments.length > 0) {
            this.emit(argvPath(node.arguments[0], false), node);
            return;
        }
        const [program, args] = node.arguments;
        if (
            !STARTERS.has(name) ||
            program === undefined ||
            args === undefined ||
            !ts.isArrayLiteralExpression(args) ||
            !isInterpreter(program)
        )
            return;
        for (const value of args.elements) {
            const path = argvPath(value, false);
            if (path !== null) {
                this.emit(path, node);
                return;
            }
        }
    }

    emit(path, node) {
        if (path === null) return;
        this.context.addEdge(
            "launches",
            this.context.currentSource() ?? this.context.moduleId,
            reference("launched_file", path),
            node,
            { speculative: true },
            "ast",
            "probable",
        );
    }
}

/** The name a call's callee ends with: `spawn` in `spawn(...)` and `cp.spawn(...)`. */
function calleeName(expression) {
    if (ts.isIdentifier(expression)) return expression.text;
    if (ts.isPropertyAccessExpression(expression)) return expression.name.text;
    return null;
}

/** An interpreter literal (`"node"`, `"/usr/bin/python3"`) or `process.execPath`. */
function isInterpreter(expression) {
    if (
        ts.isStringLiteral(expression) ||
        ts.isNoSubstitutionTemplateLiteral(expression)
    )
        return INTERPRETERS.has(expression.text.split("/").at(-1));
    return (
        ts.isPropertyAccessExpression(expression) &&
        ts.isIdentifier(expression.expression) &&
        expression.expression.text === "process" &&
        expression.name.text === "execPath"
    );
}

/**
 * The project-relative path an argv element names, when it reads as a path
 * to a file: a string literal, or the literal a path is joined from
 * (`` `${root}/bin/tool` ``, `root + "/bin/tool"`, `path.join(root, "a.js")`,
 * `new URL("./a.js", import.meta.url)`). A program in first place must be
 * joined to something, since a bare literal there is a command looked up on
 * PATH; an option (`--flag`) is never a path.
 */
function argvPath(value, program) {
    let literal = null;
    if (
        ts.isStringLiteral(value) ||
        ts.isNoSubstitutionTemplateLiteral(value)
    ) {
        literal = program ? null : value.text;
    } else if (ts.isTemplateExpression(value)) {
        literal = value.templateSpans.at(-1)?.literal.text || null;
    } else if (
        ts.isBinaryExpression(value) &&
        value.operatorToken.kind === ts.SyntaxKind.PlusToken &&
        ts.isStringLiteral(value.right)
    ) {
        literal = value.right.text;
    } else if (
        ts.isCallExpression(value) &&
        ["join", "resolve"].includes(calleeName(value.expression))
    ) {
        const tail = [];
        for (const argument of [...value.arguments].reverse()) {
            if (!ts.isStringLiteral(argument)) break;
            tail.unshift(argument.text);
        }
        literal =
            tail.length > 0 &&
            (!program || tail.length < value.arguments.length)
                ? tail.join("/")
                : null;
    } else if (
        ts.isNewExpression(value) &&
        ts.isIdentifier(value.expression) &&
        value.expression.text === "URL" &&
        value.arguments?.[0] &&
        ts.isStringLiteral(value.arguments[0])
    ) {
        literal = value.arguments[0].text;
    }
    if (literal === null) return null;
    const path = literal.replace(/^\.\//, "").replace(/^\/+/, "");
    if (
        path.startsWith("-") ||
        path.endsWith("/") ||
        /[\s=:]/.test(path) ||
        (!path.includes("/") && !SCRIPT.test(path))
    )
        return null;
    return path;
}
