import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { defineConfig } from "vitepress";
import { REPOSITORY, repositoryLinks } from "./links.mjs";
import {
    OG_IMAGE,
    SITE_NAME,
    SITE_URL,
    THEME_COLOR,
    absoluteUrl,
    describe,
    routeOfPage,
} from "./meta.mjs";
import { githubSlug } from "./slug.mjs";
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

// The pane's sigil, read from the web client so the site never carries a copy that
// drifts. It goes inline as data: URIs, since the site may load nothing from
// another host and VitePress serves public files only from docs/public.
const sigil = fs.readFileSync(
    path.join(repositoryRoot, "web/public/icon.svg"),
    "utf8",
);
const dataUri = (svg) =>
    `data:image/svg+xml,${encodeURIComponent(svg.replace(/\s+/g, " "))}`;
// The favicon keeps the sigil's own rule: a dark backplate only when the
// browser is light. The logo follows the site's appearance switch instead.
const favicon = dataUri(sigil);
const logoOnDark = dataUri(sigil.replace(/<style>[\s\S]*?<\/style>/, ""));
const logoOnLight = dataUri(
    sigil
        .replace(/<style>[\s\S]*?<\/style>/, "")
        .replace(/(class="backplate"[\s\S]*?)fill="none"/, '$1fill="#08090b"'),
);

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

// What Knossos is, in one line: the site's description and the front page's
// structured data say it the same way.
const WHAT_IT_IS =
    "the architecture graph for your code that Claude Code and other MCP clients can read";
const SITE_DESCRIPTION = `Documentation for Knossos, ${WHAT_IT_IS}.`;

/**
 * A page's description: its front matter's when it has one, the front page's
 * from its hero, any other page's from its first paragraph (meta.mjs).
 */
function descriptionOf(pageData) {
    const { frontmatter } = pageData;
    if (frontmatter.description) {
        return frontmatter.description;
    }
    if (frontmatter.layout === "home" && frontmatter.hero) {
        return `${frontmatter.hero.text}. ${frontmatter.hero.tagline}`;
    }
    const source = fs.readFileSync(
        path.join(docsDir, pageData.filePath),
        "utf8",
    );

    return describe(source) || `${pageData.title}. ${SITE_DESCRIPTION}`;
}

/** The tags that tell search engines and link previews what a page is. */
function headOf({ pageData, title, description }) {
    if (pageData.isNotFound || pageData.relativePath === "404.md") {
        return [["meta", { name: "robots", content: "noindex" }]];
    }
    const url = absoluteUrl(routeOfPage(pageData.relativePath));
    const image = absoluteUrl(OG_IMAGE.path);
    const meta = (key, name, content) => ["meta", { [key]: name, content }];
    const head = [
        // VitePress writes this tag unescaped when the head has none of its own.
        meta("name", "description", description),
        ["link", { rel: "canonical", href: url }],
        meta("property", "og:type", "website"),
        meta("property", "og:site_name", SITE_NAME),
        meta("property", "og:locale", "en_US"),
        meta("property", "og:title", title),
        meta("property", "og:description", description),
        meta("property", "og:url", url),
        meta("property", "og:image", image),
        meta("property", "og:image:type", "image/png"),
        meta("property", "og:image:width", String(OG_IMAGE.width)),
        meta("property", "og:image:height", String(OG_IMAGE.height)),
        meta("property", "og:image:alt", OG_IMAGE.alt),
        meta("name", "twitter:card", "summary_large_image"),
        meta("name", "twitter:title", title),
        meta("name", "twitter:description", description),
        meta("name", "twitter:image", image),
        meta("name", "twitter:image:alt", OG_IMAGE.alt),
    ];
    if (pageData.relativePath === "index.md") {
        head.push([
            "script",
            { type: "application/ld+json" },
            JSON.stringify({
                "@context": "https://schema.org",
                "@type": "SoftwareSourceCode",
                name: SITE_NAME,
                description: `Knossos is ${WHAT_IT_IS}.`,
                url: SITE_URL,
                codeRepository: REPOSITORY,
                license: "https://spdx.org/licenses/MIT",
                author: {
                    "@type": "Person",
                    name: "Tim Schipper",
                    url: "https://tim-schipper.nl/en",
                },
            }).replace(/</g, "\\u003c"),
        ]);
    }

    return head;
}

// No page has a diagram, so there is no Mermaid plugin: it made every page preload
// 1.4 MB. To draw one, add vitepress-plugin-mermaid and mermaid@11 and wrap this in withMermaid().
export default defineConfig({
    title: "Knossos",
    description: SITE_DESCRIPTION,
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
    // The site's own static files (the social card) live beside this config,
    // not in docs/, so docs/ stays the pages people read on GitHub too.
    vite: {
        plugins: [resolveFromSite],
        publicDir: path.join(repositoryRoot, "website/public"),
    },
    sitemap: { hostname: SITE_URL },
    transformPageData: (pageData) => ({
        description: descriptionOf(pageData),
    }),
    transformHead: headOf,
    // Dark first, as the pane: a reader's own choice in the switch still wins.
    appearance: "dark",
    head: [
        ["meta", { name: "color-scheme", content: "dark light" }],
        // The theme keeps this in step with the appearance switch.
        ["meta", { name: "theme-color", content: THEME_COLOR.dark }],
        ["link", { rel: "icon", type: "image/svg+xml", href: favicon }],
    ],
    markdown: {
        // GitHub's anchors, not VitePress's: see slug.mjs.
        anchor: { slugify: githubSlug },
        // Screenshots load as they scroll into view, and the variant for the
        // other appearance, hidden by the theme, does not load at all.
        image: { lazyLoading: true },
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
        logo: { light: logoOnLight, dark: logoOnDark, alt: "" },
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
        // VitePress shows the footer on pages without a sidebar: the front page.
        footer: {
            message:
                'Built by <a href="https://tim-schipper.nl/en">Tim Schipper</a> and released as open source under <a href="https://aranea.dev">aranea.dev</a>.',
            copyright:
                '<a href="https://aranea.dev/tools/knossos">Knossos on aranea.dev</a> · Released under the MIT license.',
        },
    },
});
