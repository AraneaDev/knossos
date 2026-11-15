// The site's navigation, read from docs/README.md.
//
// That page already groups every documentation page under a heading, so the
// sidebar takes its groups and their order from it and nothing is written
// twice. A page under docs/ that README.md does not link to would be published
// with no way to reach it, so `unlistedPages` names it and the build refuses.
import fs from "node:fs";
import path from "node:path";

// Pages that are not part of the listing: the front page, and the index itself.
const NOT_LISTED = new Set(["index.md", "README.md"]);
// Untracked local scratch under docs/ (see .gitignore); never a page.
export const SCRATCH_DIRECTORIES = ["superpowers", "audits"];

/** Every Markdown page under `docsDir`, relative and with forward slashes, sorted. */
export function allPages(docsDir) {
    const found = [];
    const walk = (directory) => {
        for (const entry of fs.readdirSync(directory, {
            withFileTypes: true,
        })) {
            const full = path.join(directory, entry.name);
            const relative = path
                .relative(docsDir, full)
                .split(path.sep)
                .join("/");
            if (entry.isDirectory()) {
                if (!SCRATCH_DIRECTORIES.includes(relative)) {
                    walk(full);
                }
            } else if (entry.name.endsWith(".md")) {
                found.push(relative);
            }
        }
    };
    walk(docsDir);

    return found.sort();
}

/**
 * The groups of docs/README.md: each `## ` heading with the links that start a
 * list item under it. `link` is the target as written, `page` is the Markdown
 * file it names (no anchor), or null for a target that is not a page.
 *
 * @returns {{title: string, items: {text: string, link: string, page: string | null}[]}[]}
 */
export function readIndex(docsDir) {
    const source = fs.readFileSync(path.join(docsDir, "README.md"), "utf8");
    const groups = [];
    let fenced = false;
    for (const line of source.split("\n")) {
        if (/^\s*```/.test(line)) {
            fenced = !fenced;
        }
        if (fenced) {
            continue;
        }
        const heading = /^## (.+?)\s*$/.exec(line);
        if (heading) {
            groups.push({ title: heading[1], items: [] });
            continue;
        }
        const item = /^- \[(.+?)\]\(([^)\s]+)\)/.exec(line);
        if (item && groups.length > 0) {
            const link = item[2];
            const file = link.split("#")[0];
            groups[groups.length - 1].items.push({
                text: item[1],
                link,
                page: file.endsWith(".md") ? file : null,
            });
        }
    }

    return groups.filter((group) => group.items.length > 0);
}

/** Pages under docs/ that the index does not link to. */
export function unlistedPages(docsDir, groups) {
    const listed = new Set(
        groups.flatMap((group) => group.items.map((item) => item.page)),
    );

    return allPages(docsDir).filter(
        (page) => !NOT_LISTED.has(page) && !listed.has(page),
    );
}

/** Links in the index to a page that does not exist. */
export function brokenIndexLinks(docsDir, groups) {
    return groups
        .flatMap((group) => group.items)
        .filter((item) => item.page !== null)
        .filter((item) => !fs.existsSync(path.join(docsDir, item.page)))
        .map((item) => item.link);
}

/** The site route of a page: `a/b.md` is `/a/b`, `a/index.md` is `/a/`. */
export function routeOf(page) {
    const route = "/" + page.replace(/\.md$/, "");

    return route.endsWith("/index") ? route.slice(0, -"index".length) : route;
}

/**
 * The sidebar: one group per heading of the index. A page the index lists
 * twice (once with an anchor) appears once. A target that is not a page, such
 * as the examples directory, links to that directory in the repository (`treeUrl` is the
 * repository's tree at the build's ref).
 */
export function sidebarOf(groups, treeUrl) {
    const placed = new Set();

    return groups
        .map((group) => ({
            text: group.title,
            collapsed: true,
            items: group.items.flatMap((item) => {
                if (item.page === null) {
                    return [
                        {
                            text: item.text,
                            link: `${treeUrl}/docs/${item.link}`,
                        },
                    ];
                }
                if (placed.has(item.page)) {
                    return [];
                }
                placed.add(item.page);

                return [{ text: item.text, link: routeOf(item.page) }];
            }),
        }))
        .filter((group) => group.items.length > 0);
}
