import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { withMermaid } from "vitepress-plugin-mermaid";
import { REPOSITORY, repositoryLinks } from "./links.mjs";
import {
    SCRATCH_DIRECTORIES,
    brokenIndexLinks,
    readIndex,
    routeOf,
    sidebarOf,
    unlistedPages,
} from "./navigation.mjs";

const here = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(here, "../..");
const docsDir = path.join(repositoryRoot, "docs");

// The ref the site documents. A release build sets it to the tag, so the links
// that leave docs/ point at the files as released.
const ref = process.env.KNOSSOS_DOCS_REF || "main";
const version = fs
    .readFileSync(path.join(repositoryRoot, "version.txt"), "utf8")
    .trim();

const groups = readIndex(docsDir);
const problems = [
    ...unlistedPages(docsDir, groups).map(
        (page) =>
            `docs/${page} is not linked from docs/README.md, so the site would publish it with no way to reach it`,
    ),
    ...brokenIndexLinks(docsDir, groups).map(
        (link) => `docs/README.md links to ${link}, which does not exist`,
    ),
];
if (problems.length > 0) {
    throw new Error(problems.join("\n"));
}

// The groups the top bar offers. Looked up by title so that a renamed heading
// fails the build here instead of dropping an item from the bar.
const NAV_GROUPS = ["Get started", "Claude Code", "Guides", "Reference"];
const nav = NAV_GROUPS.map((title) => {
    const group = groups.find((candidate) => candidate.title === title);
    if (!group) {
        throw new Error(
            `docs/README.md has no "${title}" heading, which the top bar links to`,
        );
    }

    return { text: title, link: routeOf(group.items[0].page) };
});

// The pages live outside this package, so a bare import that VitePress writes
// into a compiled page (vue, vitepress) would be looked up from docs/ upwards,
// where there is no node_modules. Resolve those from this package instead.
const resolveFromSite = {
    name: "knossos-resolve-from-site",
    enforce: "pre",
    async resolveId(id, importer, options) {
        const bare =
            !id.startsWith(".") &&
            !id.startsWith("/") &&
            !id.startsWith("\0") &&
            !/^[a-z]+:/i.test(id);
        if (bare && importer?.startsWith(docsDir + path.sep)) {
            return this.resolve(id, fileURLToPath(import.meta.url), {
                ...options,
                skipSelf: true,
            });
        }

        return null;
    },
};

export default withMermaid({
    title: "Knossos",
    description:
        "Documentation for Knossos, the architecture graph for your code that Claude Code and other MCP clients can read.",
    lang: "en-US",
    // GitHub Pages serves a project site under the repository name.
    base: "/knossos/",
    srcDir: "../docs",
    // GitHub Pages answers /page with page.html, so the addresses carry no extension.
    cleanUrls: true,
    // docs/README.md is the index people read on GitHub; the site's front page is
    // docs/index.md. The index is published as the "All pages" page, at /all-pages.
    rewrites: { "README.md": "all-pages.md" },
    srcExclude: SCRATCH_DIRECTORIES.map((directory) => `${directory}/**`),
    // The page's own dead-link check stays on: a broken link fails the build.
    ignoreDeadLinks: false,
    // Off: a shallow checkout would give every page the date of one commit.
    lastUpdated: false,
    vite: { plugins: [resolveFromSite] },
    head: [["meta", { name: "color-scheme", content: "light dark" }]],
    markdown: {
        // The pages are written for GitHub, whose heading anchors keep underscores
        // and drop apostrophes; VitePress's own slugs differ, and a link such as
        // `page.md#one-files-context` would land on the top of the page.
        // Same rule as tools/documentation-check.php, which checks the links.
        anchor: {
            slugify: (text) =>
                text
                    .toLowerCase()
                    .replace(/[^\p{L}\p{N}_ -]/gu, "")
                    .replace(/ /g, "-"),
        },
        config: (md) => {
            md.use(repositoryLinks({ docsDir, ref }));
            // Vue reads {{ }} in a page as an expression. Fenced code is exempt
            // already; inline code is not, and the Laravel page quotes Blade.
            const inlineCode = md.renderer.rules.code_inline;
            md.renderer.rules.code_inline = (...args) =>
                inlineCode(...args).replace(/^<code/, "<code v-pre");
        },
    },
    themeConfig: {
        nav: [
            ...nav,
            { text: "All pages", link: "/all-pages" },
            {
                text: `v${version}`,
                link: `${REPOSITORY}/releases/tag/v${version}`,
            },
        ],
        sidebar: sidebarOf(groups, `${REPOSITORY}/tree/${ref}`),
        socialLinks: [{ icon: "github", link: REPOSITORY }],
        search: { provider: "local" },
        editLink: {
            pattern: `${REPOSITORY}/edit/main/docs/:path`,
            text: "Edit this page on GitHub",
        },
        outline: { level: [2, 3] },
        footer: { message: "Released under the MIT license." },
    },
});
