/**
 * The module aliases, module roots and extensions a project's bundler
 * declares, read from its config text without running it.
 *
 * `withBundlerAliases` adds what vite, webpack, Vue CLI and SvelteKit configs
 * declare to a parsed tsconfig, and `inVueProject` says whether a program's
 * directory lies in a package that depends on Vue. Every config is probed and
 * read through the request's recorder, so a change to one invalidates the
 * facts it decided.
 */

import path from "node:path";
import ts from "typescript";
import { componentDialect } from "./component-source.js";
import {
    inRootFile,
    probeNamedRecorded,
    probeRecorded,
} from "./input-reads.js";
import {
    allowedCompilerPath,
    normalize,
    relativeInside,
} from "./project-paths.js";
import { readRecorded } from "./source-reading.js";
import { objectField, staticPropertyName } from "./syntax-predicates.js";

/**
 * Whether a program's directory lies on the path of a package that depends
 * on Vue: at or below it (the package holds the config), or above it (the
 * config, or the project root for the fallback program, holds the package).
 * Decided from manifests the core read, never from the files in a request.
 */
export function inVueProject(root, directory, vueProjects) {
    const relative = relativeInside(root, directory);
    if (relative === null) return false;
    const here = relative === "." ? "" : relative;
    return vueProjects.some(
        (project) =>
            typeof project === "string" &&
            (project === "" ||
                here === "" ||
                here === project ||
                here.startsWith(`${project}/`) ||
                project.startsWith(`${here}/`)),
    );
}

// Bundler and framework configs that declare module aliases, by directory.
const ALIAS_CONFIGS = [
    "svelte.config.js",
    "svelte.config.mjs",
    "svelte.config.ts",
    "vite.config.js",
    "vite.config.mjs",
    "vite.config.ts",
    "vite.config.mts",
    "webpack.config.js",
    "webpack.config.cjs",
    "webpack.config.mjs",
    "webpack.mix.js",
    "vue.config.js",
];

/**
 * The parsed config with the module aliases the project's bundler declares,
 * for any name the config does not map already.
 *
 * An import of `~/components/App` or `$lib/format` means what the bundler's
 * `resolve.alias` (or SvelteKit's `kit.alias`) says, and a project that
 * relies on the bundler has no tsconfig `paths` for it; SvelteKit writes its
 * aliases, `$lib` included, into a generated tsconfig no checkout has. Such
 * imports resolved to nothing, and everything imported that way read as
 * unused. The configs are read, and recorded, only when they exist.
 *
 * @param {{root: string, reads: InputReadRecorder, maxFileBytes: number, exclusions: Exclusions}} request
 *   the scan request the configs are read for
 */
export function withBundlerAliases(request, directory, parsed) {
    const { root, reads, maxFileBytes, exclusions } = request;
    const aliases = {};
    const roots = [];
    const extensions = [];
    const bundlerDiagnostics = [];
    // The conventional names first, then the config a package script passes
    // webpack, then each webpack config one of those requires (a base config
    // merged into a dev or prod one).
    const conventional = ALIAS_CONFIGS.map((name) =>
        path.join(directory, name),
    );
    const configs = [
        ...conventional,
        ...scriptWebpackConfigs(request, directory),
    ];
    const seen = new Set();
    while (configs.length > 0 && seen.size < MAX_BUNDLER_CONFIGS) {
        const config = configs.shift();
        if (seen.has(config)) continue;
        seen.add(config);
        const name = path.basename(config);
        const present = conventional.includes(config)
            ? allowedCompilerPath(root, config, exclusions) &&
              probeRecorded(reads, root, config, maxFileBytes, exclusions)
            : probeNamedRecorded(reads, root, config, maxFileBytes);
        if (!present) continue;
        if (name.startsWith("svelte.")) aliases.$lib ??= "src/lib";
        const text = readRecorded(root, config, reads, maxFileBytes);
        if (text === undefined) continue;
        // `__dirname` in a config elsewhere names that config's directory.
        const dirname =
            normalize(path.relative(directory, path.dirname(config))) || ".";
        Object.assign(
            aliases,
            declaredAliases(text, name.startsWith("vite."), dirname),
        );
        const resolve = declaredResolve(text, dirname);
        // A config a script names is webpack's whatever it is called.
        if (/webpack/i.test(name) || !conventional.includes(config))
            configs.push(...requiredWebpackConfigs(request, config, text));
        roots.push(...resolve.modules);
        extensions.push(...resolve.extensions);
        for (const key of resolve.unread)
            bundlerDiagnostics.push({
                severity: "warning",
                code: "BUNDLER_CONFIG_UNREAD",
                message: `${normalize(path.relative(root, config))}: ${key} could not be read without running the config, so imports through it are not resolved (applies to the whole program)`,
            });
    }
    const componentExtensions = [
        ...new Set(
            extensions.filter((extension) => componentDialect(`x${extension}`)),
        ),
    ];
    const rootsWhere = (late) => [
        ...new Set(
            roots
                .filter((entry) => entry.late === late)
                .map((entry) =>
                    normalize(path.resolve(directory, entry.target)),
                )
                .filter((target) =>
                    allowedCompilerPath(root, target, exclusions),
                ),
        ),
    ];
    const moduleRoots = rootsWhere(false);
    const lateModuleRoots = rootsWhere(true);
    const extra = { componentExtensions, lateModuleRoots, bundlerDiagnostics };
    if (Object.keys(aliases).length === 0 && moduleRoots.length === 0)
        return { ...parsed, ...extra };
    const paths = { ...parsed.options.paths };
    for (const [name, target] of Object.entries(aliases)) {
        const absolute = normalize(path.resolve(directory, target));
        if (name.endsWith("/*")) {
            paths[name] ??= [absolute];
            continue;
        }
        paths[name] ??= [absolute];
        paths[`${name}/*`] ??= [`${absolute}/*`];
    }
    // A root webpack tries before node_modules: the compiler falls back to
    // node_modules when no root holds the specifier. A root after it is
    // tried by the resolver once the rest found nothing (componentResolver).
    if (moduleRoots.length > 0)
        paths["*"] ??= moduleRoots.map((target) => `${target}/*`);
    return {
        ...parsed,
        options: { ...parsed.options, paths },
        ...extra,
    };
}

// A bound on the configs one program follows, so a cycle of requires or a
// tree of build configs cannot run on.
const MAX_BUNDLER_CONFIGS = 32;

/**
 * The webpack configs the package.json in `directory` passes webpack with
 * `--config` (or `-c`) in a script, as a project names a config webpack would
 * not find by itself: `webpack --config build/webpack.prod.conf.js`. Read
 * against the package's directory, which a script runs from. An absent
 * manifest is not probed: discovery hashes every package.json already.
 */
function scriptWebpackConfigs(request, directory) {
    const { root, reads, maxFileBytes, exclusions } = request;
    const manifest = path.join(directory, "package.json");
    if (
        !allowedCompilerPath(root, manifest, exclusions) ||
        !inRootFile(root, manifest)
    )
        return [];
    const text = readRecorded(root, manifest, reads, maxFileBytes);
    let scripts;
    try {
        scripts = JSON.parse(text ?? "null")?.scripts;
    } catch {
        return [];
    }
    if (scripts === null || typeof scripts !== "object") return [];
    const configs = [];
    for (const script of Object.values(scripts)) {
        if (typeof script !== "string") continue;
        for (const command of script.split(/&&|\|\||[;|]/)) {
            const rest =
                /(?:^|\s)(?:\S*\/)?webpack(?:-cli|-dev-server)?(?=\s|$)(.*)$/.exec(
                    command,
                )?.[1];
            const config =
                rest === undefined
                    ? undefined
                    : /(?:^|\s)(?:--config(?:=|\s+)|-c\s+)(["']?)([^\s"']+)\1/.exec(
                          rest,
                      )?.[2];
            if (config !== undefined)
                configs.push(path.join(directory, config));
        }
    }
    return configs;
}

/**
 * The webpack configs a config requires or imports by a relative path, a
 * base config merged into it: only a file whose name says webpack, since a
 * helper module's `resolve` is not webpack's. A specifier without an
 * extension is tried as Node would, and each candidate's answer is recorded.
 */
function requiredWebpackConfigs(request, config, text) {
    const { root, reads, maxFileBytes } = request;
    const file = ts.createSourceFile(
        "config.ts",
        text,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TS,
    );
    const specifiers = [];
    const visit = (node) => {
        const specifier = ts.isImportDeclaration(node)
            ? node.moduleSpecifier
            : ts.isCallExpression(node) &&
                ts.isIdentifier(node.expression) &&
                node.expression.text === "require"
              ? node.arguments[0]
              : undefined;
        if (
            specifier !== undefined &&
            ts.isStringLiteralLike(specifier) &&
            /^\.{1,2}\//.test(specifier.text) &&
            /webpack/i.test(path.posix.basename(specifier.text))
        )
            specifiers.push(specifier.text);
        ts.forEachChild(node, visit);
    };
    visit(file);
    const found = [];
    for (const specifier of specifiers) {
        const base = path.join(path.dirname(config), specifier);
        const candidates = /\.[cm]?[jt]s$/.test(specifier)
            ? [base]
            : [
                  base,
                  ...[".js", ".cjs", ".mjs", ".ts"].map((ext) => base + ext),
              ];
        const present = candidates.find((candidate) =>
            probeNamedRecorded(reads, root, candidate, maxFileBytes),
        );
        if (present !== undefined) found.push(present);
    }
    return found;
}

/**
 * The module roots and extensions a webpack config's `resolve` declares,
 * read without running it: `resolve: { modules: [...], extensions: [...] }`,
 * or Vue CLI's `config.resolve.modules.add(...)` in `chainWebpack`. A root
 * is a string (a directory name, read against the config's directory) or
 * `path.join|resolve(…)`. webpack tries the roots in order, `node_modules`
 * among them: a root is `late` when it comes after `node_modules`, as one
 * `.add` appends to Vue CLI's defaults does (`.prepend` puts it first). A
 * rule's own `resolve` (under `rules` or `oneOf`) is left alone. `unread`
 * names `resolve.modules` when a root could not be read; an extension that
 * cannot be read is skipped, since only a component extension would change
 * what resolves.
 */
function declaredResolve(text, dirname = ".") {
    const file = ts.createSourceFile(
        "config.ts",
        text,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TS,
    );
    const found = { modules: [], extensions: [], unread: [] };
    const unread = () => {
        if (!found.unread.includes("resolve.modules"))
            found.unread.push("resolve.modules");
    };
    const rootOf = (expression) =>
        expression === undefined
            ? null
            : ts.isStringLiteralLike(expression)
              ? expression.text
              : aliasTarget(expression, dirname);
    const isNodeModules = (target) =>
        target !== null && path.posix.normalize(target) === "node_modules";
    const addModules = (expressions, late) => {
        const marker = expressions.findIndex((element) =>
            isNodeModules(rootOf(element)),
        );
        expressions.forEach((element, index) => {
            const target = rootOf(element);
            if (target === null) unread();
            else if (!isNodeModules(target))
                found.modules.push({
                    target,
                    late: late || (marker !== -1 && index > marker),
                });
        });
    };
    const addExtension = (expression) => {
        if (expression !== undefined && ts.isStringLiteralLike(expression))
            found.extensions.push(expression.text);
    };
    const insideRule = (node) => {
        for (let up = node.parent; up !== undefined; up = up.parent) {
            if (
                ts.isPropertyAssignment(up) &&
                ["rules", "oneOf"].includes(staticPropertyName(up.name))
            )
                return true;
        }
        return false;
    };
    // `config.resolve.modules`, or a call chained onto `….add(x)`, and
    // whether the call prepends.
    const chainKey = (expression) => {
        if (ts.isCallExpression(expression)) {
            const callee = expression.expression;
            return ts.isPropertyAccessExpression(callee) &&
                ["add", "prepend"].includes(callee.name.text)
                ? chainKey(callee.expression)
                : null;
        }
        return ts.isPropertyAccessExpression(expression) &&
            ["modules", "extensions"].includes(expression.name.text) &&
            ts.isPropertyAccessExpression(expression.expression) &&
            expression.expression.name.text === "resolve" &&
            ts.isIdentifier(expression.expression.expression)
            ? expression.name.text
            : null;
    };
    const visit = (node) => {
        const key = ts.isPropertyAssignment(node)
            ? staticPropertyName(node.name)
            : null;
        if (
            (key === "modules" || key === "extensions") &&
            ts.isObjectLiteralExpression(node.parent) &&
            ts.isPropertyAssignment(node.parent.parent) &&
            staticPropertyName(node.parent.parent.name) === "resolve" &&
            !insideRule(node)
        ) {
            const elements = ts.isArrayLiteralExpression(node.initializer)
                ? [...node.initializer.elements]
                : null;
            if (key === "extensions") elements?.forEach(addExtension);
            else if (elements === null) unread();
            else addModules(elements, false);
        }
        if (
            ts.isCallExpression(node) &&
            ts.isPropertyAccessExpression(node.expression) &&
            ["add", "prepend"].includes(node.expression.name.text)
        ) {
            const chained = chainKey(node.expression.expression);
            if (chained === "extensions") addExtension(node.arguments[0]);
            if (chained === "modules")
                addModules(
                    node.arguments.slice(0, 1),
                    node.expression.name.text === "add",
                );
        }
        ts.forEachChild(node, visit);
    };
    visit(file);
    return found;
}

/**
 * The entries of every `alias` object in a config whose target can be read
 * without running it: a string, `path.join|resolve(__dirname, …)`, or
 * `fileURLToPath(new URL('./x', import.meta.url))`, as an object or as
 * Vite's `[{ find, replacement }]` array. An exact-match key (`vue$`) names a
 * package, not a directory, and is skipped. With `rootRelative` (Vite), a
 * target starting with `/` is read against the config's directory.
 */
function declaredAliases(text, rootRelative, dirname = ".") {
    const file = ts.createSourceFile(
        "config.ts",
        text,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TS,
    );
    const aliases = {};
    const add = (name, expression) => {
        const target = name === null ? null : aliasTarget(expression, dirname);
        if (target === null || name.endsWith("$")) return;
        // Vite reads `/src` against the project root, not the filesystem's.
        aliases[name] =
            rootRelative && target.startsWith("/") ? `.${target}` : target;
    };
    const visit = (node) => {
        const entries =
            ts.isPropertyAssignment(node) &&
            staticPropertyName(node.name) === "alias"
                ? node.initializer
                : undefined;
        if (entries !== undefined && ts.isObjectLiteralExpression(entries)) {
            for (const entry of entries.properties) {
                if (ts.isPropertyAssignment(entry))
                    add(staticPropertyName(entry.name), entry.initializer);
            }
        }
        // Vite's array form: `[{ find: '@', replacement: '/src' }]`, with a
        // string `find`; a regular expression names no fixed prefix.
        if (entries !== undefined && ts.isArrayLiteralExpression(entries)) {
            for (const entry of entries.elements) {
                const find = objectField(entry, "find");
                const replacement = objectField(entry, "replacement");
                if (
                    find !== undefined &&
                    replacement !== undefined &&
                    ts.isStringLiteralLike(find)
                )
                    add(find.text, replacement);
            }
        }
        ts.forEachChild(node, visit);
    };
    visit(file);
    return aliases;
}

/**
 * A directory an alias maps to, relative to the program's directory, or null;
 * `dirname` is the config's own directory relative to that.
 */
function aliasTarget(expression, dirname = ".") {
    if (ts.isStringLiteralLike(expression)) {
        // A path, not a package (`lodash-es`, `@scope/pkg`).
        const target = expression.text;
        return /^\.{0,2}\//.test(target) ||
            (target.includes("/") && !target.startsWith("@"))
            ? target
            : null;
    }
    if (!ts.isCallExpression(expression)) return null;
    const callee = expression.expression.getText();
    const [first, ...rest] = expression.arguments;
    // Without `__dirname`, resolved against the working directory, which a
    // build runs from: the config's own directory.
    const parts =
        first !== undefined &&
        ts.isIdentifier(first) &&
        first.text === "__dirname"
            ? rest
            : expression.arguments;
    if (
        /(?:^|\.)(?:join|resolve)$/.test(callee) &&
        parts.length > 0 &&
        parts.every((part) => ts.isStringLiteralLike(part))
    )
        return path.posix.join(
            parts === rest ? dirname : ".",
            ...parts.map((part) => part.text),
        );
    const url = expression.arguments[0];
    if (
        callee === "fileURLToPath" &&
        url !== undefined &&
        ts.isNewExpression(url) &&
        url.arguments?.[0] !== undefined &&
        ts.isStringLiteralLike(url.arguments[0])
    )
        return url.arguments[0].text;
    return null;
}
