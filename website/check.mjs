// Checks the built site (run `npm run build` first): what the build promises
// and VitePress's own dead-link check cannot see.
//
//   node check.mjs [--dist=DIR]
//
// The ref the build documented is read from the built index page. KNOSSOS_DOCS_REF,
// when set, must equal it; unset, the check goes by what the build did.
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { REPOSITORY, repositoryLink } from "./.vitepress/links.mjs";
import {
    OG_IMAGE,
    SITE_URL,
    absoluteUrl,
    describe,
} from "./.vitepress/meta.mjs";
import { githubSlug } from "./.vitepress/slug.mjs";
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
const BASE = "/knossos/";

const failures = [];
const fail = (message) => failures.push(message);

if (!fs.existsSync(dist)) {
    console.error(`no build at ${dist}; run npm run build first`);
    process.exit(2);
}

// The ref the build used, as it shows in the link from the index to README.md.
const built = path.join(dist, "all-pages.html");
const detected = fs.existsSync(built)
    ? new RegExp(`href="${REPOSITORY}/blob/([^/"]+)/README.md"`).exec(
          fs.readFileSync(built, "utf8"),
      )?.[1]
    : undefined;
const asked = process.env.KNOSSOS_DOCS_REF || undefined;
const ref = detected ?? asked ?? "main";
if (asked !== undefined && detected !== undefined && asked !== detected) {
    fail(
        `KNOSSOS_DOCS_REF is ${asked} but the build documented ${detected}; run the check with the value the build ran with`,
    );
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

// 5. Nothing is loaded from anywhere but the site itself. A canonical or
// alternate link names an address; it loads nothing.
const siteOrigin = new URL(SITE_URL).origin;
for (const file of files) {
    for (const tag of fs
        .readFileSync(file, "utf8")
        .matchAll(/<(?:script|link|img|iframe)\s[^>]*>/g)) {
        const url = /\s(?:src|href)="((?:https?:)?\/\/[^"]+)"/.exec(
            tag[0],
        )?.[1];
        if (
            url === undefined ||
            /^https:\/\/github\.com\//.test(url) ||
            new URL(url, SITE_URL).origin === siteOrigin ||
            (/^<link\s/.test(tag[0]) &&
                /\srel="(?:canonical|alternate)"/.test(tag[0]))
        ) {
            continue;
        }
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

// 6. The slug rule is GitHub's: it keeps combining marks, underscores and non-ASCII letters.
for (const [text, slug] of [
    ["One file's context", "one-files-context"],
    ["`file_context` tool", "file_context-tool"],
    ["Cafe\u0301 Re\u0301sume\u0301", "cafe\u0301-re\u0301sume\u0301"],
    ["Why? / How it works", "why--how-it-works"],
]) {
    if (githubSlug(text) !== slug) {
        fail(
            `githubSlug(${JSON.stringify(text)}) is ${JSON.stringify(githubSlug(text))}, GitHub gives ${JSON.stringify(slug)}`,
        );
    }
}

// 7. Every page names its own address once as canonical, describes itself, and
// points link previews at a social card the build carries at the stated size.
const unescape = (value) =>
    value
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'")
        .replace(/&lt;/g, "<")
        .replace(/&gt;/g, ">")
        .replace(/&amp;/g, "&");
const metaOf = (built, key, name) =>
    [
        ...built.matchAll(
            new RegExp(`<meta ${key}="${name}" content="([^"]*)">`, "g"),
        ),
    ].map((match) => unescape(match[1]));
/** The width and height a PNG's header gives, or null for anything else. */
function pngSize(file) {
    const bytes = fs.readFileSync(file);
    if (bytes.toString("latin1", 1, 4) !== "PNG") {
        return null;
    }
    return { width: bytes.readUInt32BE(16), height: bytes.readUInt32BE(20) };
}
const sitemapFile = path.join(dist, "sitemap.xml");
const sitemap = fs.existsSync(sitemapFile)
    ? new Set(
          [
              ...fs
                  .readFileSync(sitemapFile, "utf8")
                  .matchAll(/<loc>([^<]+)<\/loc>/g),
          ].map((match) => unescape(match[1])),
      )
    : new Set();
if (sitemap.size === 0) {
    fail("no sitemap.xml with page addresses in the build");
}
const cards = new Map(); // og:image -> its size in the build, checked once
let described = 0;
for (const file of files) {
    const relative = path.relative(dist, file).split(path.sep).join("/");
    if (relative === "404.html") {
        continue;
    }
    const built = fs.readFileSync(file, "utf8");
    const address = absoluteUrl(
        relative.replace(/(^|\/)index\.html$/, "$1").replace(/\.html$/, ""),
    );
    const canonical = [
        ...built.matchAll(/<link rel="canonical" href="([^"]*)">/g),
    ].map((match) => unescape(match[1]));
    if (canonical.length !== 1 || canonical[0] !== address) {
        fail(
            `${relative}: wants one canonical URL, ${address}; has ${canonical.length === 0 ? "none" : canonical.join(", ")}`,
        );
    }
    if (!sitemap.has(address)) {
        fail(`${relative}: ${address} is not in sitemap.xml`);
    }
    if (metaOf(built, "property", "og:url")[0] !== address) {
        fail(`${relative}: og:url is not ${address}`);
    }
    const descriptions = metaOf(built, "name", "description");
    if (descriptions.length !== 1 || descriptions[0].trim() === "") {
        fail(
            `${relative}: wants one non-empty description, has ${JSON.stringify(descriptions)}`,
        );
    } else {
        described += 1;
    }
    const image = metaOf(built, "property", "og:image");
    const width = Number(metaOf(built, "property", "og:image:width")[0]);
    const height = Number(metaOf(built, "property", "og:image:height")[0]);
    if (image.length !== 1 || !image[0].startsWith(SITE_URL)) {
        fail(
            `${relative}: wants one og:image on ${SITE_URL}, has ${JSON.stringify(image)}`,
        );
        continue;
    }
    if (!metaOf(built, "property", "og:image:alt")[0]) {
        fail(`${relative}: og:image has no alt text`);
    }
    if (!cards.has(image[0])) {
        const inBuild = path.join(
            dist,
            decodeURIComponent(image[0].slice(SITE_URL.length)),
        );
        cards.set(
            image[0],
            fs.existsSync(inBuild) ? pngSize(inBuild) : undefined,
        );
    }
    const size = cards.get(image[0]);
    if (size === undefined) {
        fail(`${relative}: og:image ${image[0]} is not in the build`);
    } else if (
        size === null ||
        size.width !== width ||
        size.height !== height
    ) {
        fail(
            `${relative}: og:image ${image[0]} is ${size === null ? "not a PNG" : `${size.width}x${size.height}`}, the page says ${width}x${height}`,
        );
    }
}
if (!cards.has(absoluteUrl(OG_IMAGE.path))) {
    fail(`no page points at the social card ${OG_IMAGE.path}`);
}

// 8. Descriptions come from a page's lead, cut at a sentence within 160 characters.
for (const [source, description] of [
    [
        "# T\n\nThe **pane** opens with [`/knossos`](a.md). It shows the graph.\n",
        "The pane opens with /knossos. It shows the graph.",
    ],
    [
        "# T\n\nStatus: Accepted\n\n## Context\n\nRun it with:\n\n```sh\nx\n```\n\nThe graph is local.\n",
        "The graph is local.",
    ],
    [
        "# T\n\nOne line sums up the turn:\n\n```text\nx\n```\n",
        "One line sums up the turn.",
    ],
    [
        `# T\n\nA first sentence that is long enough to stand alone. A second sentence that ${"runs on and on, ".repeat(10)}to its end.\n`,
        "A first sentence that is long enough to stand alone.",
    ],
    [
        `# T\n\nYou have it. A second sentence that ${"runs on and on, ".repeat(10)}to its end.\n`,
        "You have it. A second sentence that runs on and on, runs on and on, runs on and on, runs on and on, runs on and on, runs on and on, runs on and on, runs on…",
    ],
    [
        "# T\n\nThis file is generated from the source.\n\n## `tool`\n\nAn entry.\n",
        "",
    ],
]) {
    if (describe(source) !== description) {
        fail(
            `describe(${JSON.stringify(source)}) is ${JSON.stringify(describe(source))}, expected ${JSON.stringify(description)}`,
        );
    }
}
for (const page of pages) {
    const description = describe(
        fs.readFileSync(path.join(docsDir, page), "utf8"),
    );
    if (description.length > 160) {
        fail(
            `${page}: the derived description runs to ${description.length} characters`,
        );
    }
}

if (failures.length > 0) {
    console.error(failures.join("\n"));
    process.exit(1);
}
console.log(
    `site check: ${html.size} pages, ${leaving} links to GitHub at ${ref}, ${internal} internal links (${anchors} with an anchor, ${indexes} to a directory index), ${described} pages described, search index present; ref ${ref} ${asked === undefined ? "(read from the build)" : "(KNOSSOS_DOCS_REF)"}`,
);
