// Checks the built site (run `npm run build` first): what the build promises
// and VitePress's own dead-link check cannot see.
//
//   node check.mjs [--dist=DIR]
//
// KNOSSOS_DOCS_REF must be the value the build ran with (default: main).
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { REPOSITORY, repositoryLink } from "./.vitepress/links.mjs";
import {
    allPages,
    readIndex,
    sidebarOf,
    unlistedPages,
} from "./.vitepress/navigation.mjs";

const here = path.dirname(fileURLToPath(import.meta.url));
const docsDir = path.resolve(here, "../docs");
const distArgument = process.argv.find((argument) =>
    argument.startsWith("--dist="),
);
const dist = distArgument
    ? path.resolve(distArgument.slice(7))
    : path.join(here, ".vitepress/dist");
const ref = process.env.KNOSSOS_DOCS_REF || "main";
const BASE = "/knossos/";

const failures = [];
const fail = (message) => failures.push(message);

if (!fs.existsSync(dist)) {
    console.error(`no build at ${dist}; run npm run build first`);
    process.exit(2);
}

/** The built file of a page's Markdown source. */
function builtFile(page) {
    if (page === "README.md") {
        return path.join(dist, "all-pages.html");
    }
    return path.join(dist, page.replace(/\.md$/, ".html"));
}

/** Markdown outside fenced code. */
function prose(source) {
    return source.replace(/^(`{3,}|~{3,})[^\n]*\n[\s\S]*?^\1[ \t]*$/gm, "");
}

const pages = allPages(docsDir);
const html = new Map(); // page -> built html
for (const page of pages) {
    const file = builtFile(page);
    if (!fs.existsSync(file)) {
        fail(`${page}: not built (${path.relative(dist, file)} is missing)`);
        continue;
    }
    html.set(page, fs.readFileSync(file, "utf8"));
}

// 1. A link that leaves docs/ reaches GitHub at the ref, in the built page.
let leaving = 0;
for (const [page, built] of html) {
    const file = path.join(docsDir, page);
    for (const match of prose(fs.readFileSync(file, "utf8")).matchAll(
        /(?<!!)\[[^\]]*\]\(([^)\s]+)/g,
    )) {
        const expected = repositoryLink(
            match[1].replace(/^<|>$/g, ""),
            file,
            docsDir,
            ref,
        );
        if (expected === null) {
            continue;
        }
        leaving += 1;
        if (!built.includes(`href="${expected}"`)) {
            fail(
                `${page}: ${match[1]} should be ${expected} in the built page`,
            );
        }
    }
}
if (leaving < 10) {
    fail(
        `only ${leaving} links leave docs/; the pages have more, so the rewrite is not running`,
    );
}
const sdk = html.get("reference/scanner-sdk.md") ?? "";
for (const expected of [
    `${REPOSITORY}/blob/${ref}/schemas/scanner/v1/manifest.schema.json`,
    `${REPOSITORY}/blob/${ref}/tests/Fixtures/scanner-sdk/golden.json`,
]) {
    if (!sdk.includes(`href="${expected}"`)) {
        fail(`reference/scanner-sdk.md lacks ${expected}`);
    }
}
if (
    !(html.get("README.md") ?? "").includes(
        `href="${REPOSITORY}/blob/${ref}/README.md"`,
    )
) {
    fail(`the index page lacks the link to the project README at ${ref}`);
}
if (
    !(html.get("README.md") ?? "").includes(
        `href="${REPOSITORY}/tree/${ref}/docs/examples"`,
    )
) {
    fail("the index page lacks the link to the examples directory");
}

// 2. Every internal link of every built page lands on a built page, and its
// anchor on an element with that id: this covers anchors and directory indexes.
const files = [];
(function walk(directory) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        const full = path.join(directory, entry.name);
        if (entry.isDirectory()) {
            if (path.relative(dist, full) !== "assets") {
                walk(full);
            }
        } else if (entry.name.endsWith(".html")) {
            files.push(full);
        }
    }
})(dist);
const ids = new Map();
const idsOf = (file) => {
    if (!ids.has(file)) {
        ids.set(
            file,
            new Set(
                [
                    ...fs
                        .readFileSync(file, "utf8")
                        .matchAll(/\sid="([^"]+)"/g),
                ].map((match) => match[1]),
            ),
        );
    }
    return ids.get(file);
};
let internal = 0;
let anchors = 0;
let indexes = 0;
for (const file of files) {
    const relative = path.relative(dist, file).split(path.sep).join("/");
    const route =
        relative === "index.html"
            ? ""
            : relative.endsWith("/index.html")
              ? relative.slice(0, -"index.html".length)
              : relative.replace(/\.html$/, "");
    const here = new URL(BASE + route, "http://site.invalid");
    for (const match of fs
        .readFileSync(file, "utf8")
        .matchAll(/<a\s[^>]*?href="([^"]*)"/g)) {
        const href = match[1].replace(/&amp;/g, "&");
        if (/^[a-z][a-z0-9+.-]*:/i.test(href)) {
            continue;
        }
        const target = new URL(href, here);
        if (!target.pathname.startsWith(BASE)) {
            fail(`${relative}: ${href} leaves the site (${target.pathname})`);
            continue;
        }
        const inner = decodeURIComponent(target.pathname.slice(BASE.length));
        if (/\.[a-z0-9]+$/i.test(inner) && !inner.endsWith(".html")) {
            continue; // an asset
        }
        internal += 1;
        const built = path.join(
            dist,
            inner === "" || inner.endsWith("/")
                ? inner + "index.html"
                : inner.replace(/\.html$/, "") + ".html",
        );
        if (inner.endsWith("/") && inner !== "") {
            indexes += 1;
        }
        if (!fs.existsSync(built)) {
            fail(
                `${relative}: ${href} has no built page (${path.relative(dist, built)})`,
            );
            continue;
        }
        const fragment = decodeURIComponent(target.hash.slice(1));
        if (fragment !== "") {
            anchors += 1;
            if (!idsOf(built).has(fragment)) {
                fail(
                    `${relative}: ${href} has no #${fragment} on the target page`,
                );
            }
        }
    }
}
if (anchors === 0 || indexes === 0) {
    fail(
        `the check saw ${anchors} anchor links and ${indexes} directory-index links; it is not looking`,
    );
}

// 3. Every page is reachable: the index names it, so the sidebar carries it.
const groups = readIndex(docsDir);
for (const page of unlistedPages(docsDir, groups)) {
    fail(`docs/${page} is not linked from docs/README.md`);
}
const sidebarLinks = new Set(
    sidebarOf(groups, `${REPOSITORY}/tree/${ref}`).flatMap((group) =>
        group.items.map((item) => item.link),
    ),
);
for (const page of pages.filter(
    (candidate) => candidate !== "index.md" && candidate !== "README.md",
)) {
    const route = "/" + page.replace(/\.md$/, "").replace(/\/index$/, "/");
    if (!sidebarLinks.has(route)) {
        fail(`${page} is not in the sidebar`);
    }
}
if (!fs.existsSync(path.join(dist, "all-pages.html"))) {
    fail("the index is not published as /all-pages");
}
if (fs.existsSync(path.join(dist, "README.html"))) {
    fail("docs/README.md is also published as /README");
}

// 4. The search index exists.
const chunks = path.join(dist, "assets/chunks");
const searchIndex = fs.existsSync(chunks)
    ? fs
          .readdirSync(chunks)
          .filter((name) => name.startsWith("@localSearchIndex"))
    : [];
if (searchIndex.length === 0) {
    fail("no local search index in assets/chunks");
}

// 5. Nothing is loaded from anywhere but the site itself.
for (const file of files) {
    const external = [
        ...fs
            .readFileSync(file, "utf8")
            .matchAll(
                /<(?:script|link|img|iframe)\s[^>]*?(?:src|href)="((?:https?:)?\/\/[^"]+)"/g,
            ),
    ]
        .map((match) => match[1])
        .filter((url) => !/^https:\/\/github\.com\//.test(url));
    for (const url of external) {
        fail(`${path.relative(dist, file)} loads ${url}`);
    }
}
for (const name of fs.readdirSync(path.join(dist, "assets"))) {
    if (
        /\.(?:css)$/.test(name) &&
        /url\(\s*["']?https?:/.test(
            fs.readFileSync(path.join(dist, "assets", name), "utf8"),
        )
    ) {
        fail(`assets/${name} loads a remote resource`);
    }
}

if (failures.length > 0) {
    console.error(failures.join("\n"));
    process.exit(1);
}
console.log(
    `site check: ${html.size} pages, ${leaving} links to GitHub at ${ref}, ${internal} internal links (${anchors} with an anchor, ${indexes} to a directory index), search index present`,
);
