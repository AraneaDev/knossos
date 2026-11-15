// Links that leave the page tree.
//
// A page under docs/ may link to a file in the repository (`../../schemas/x.json`,
// `../README.md`), which works on GitHub and has nowhere to go on the site. At
// build time such a link is pointed at the repository on GitHub, at the ref the
// site documents. The same goes for a link to a file or directory inside docs/
// that is not a page, such as the CI examples. Everything else is left to
// VitePress, whose dead-link check stays on.
import fs from "node:fs";
import path from "node:path";

export const REPOSITORY = "https://github.com/AraneaDev/knossos";

const EXTERNAL = /^(?:[a-z][a-z0-9+.-]*:|\/\/|#)/i;

/**
 * Where a link written in `fromFile` should point instead, or null to keep it.
 * Throws for a target outside the page tree that does not exist, so a typo
 * fails the build instead of publishing a dead GitHub link.
 */
export function repositoryLink(href, fromFile, docsDir, ref) {
    if (EXTERNAL.test(href)) {
        return null;
    }
    const match = /^([^?#]*)(.*)$/.exec(href);
    const target = path.resolve(
        path.dirname(fromFile),
        decodeURIComponent(match[1]),
    );
    const inside = path.relative(docsDir, target);
    const leavesDocs = inside.startsWith("..") || path.isAbsolute(inside);
    const root = path.dirname(docsDir);
    if (!leavesDocs && match[1] === "") {
        return null;
    }
    if (!fs.existsSync(target)) {
        if (leavesDocs) {
            throw new Error(
                `${fromFile}: ${href} points outside docs/ at a path that does not exist`,
            );
        }

        return null;
    }
    const directory = fs.statSync(target).isDirectory();
    if (!leavesDocs) {
        const isPage =
            target.endsWith(".md") ||
            (directory && fs.existsSync(path.join(target, "index.md")));
        if (isPage) {
            return null;
        }
    }
    const repositoryPath = path
        .relative(root, target)
        .split(path.sep)
        .map(encodeURIComponent)
        .join("/");
    const kind = directory ? "tree" : "blob";

    return `${REPOSITORY}/${kind}/${ref}/${repositoryPath}${match[2]}`;
}

/**
 * docs/README.md is published as /all-pages (see rewrites in config.mjs), and
 * VitePress does not follow that rewrite when a page links to it. Returns the
 * route to use for a link to it, or null for any other link.
 */
export function indexRoute(href, fromFile, docsDir) {
    if (EXTERNAL.test(href)) {
        return null;
    }
    const match = /^([^?#]*)(.*)$/.exec(href);
    const target = path.resolve(
        path.dirname(fromFile),
        decodeURIComponent(match[1]),
    );

    return match[1] !== "" && target === path.join(docsDir, "README.md")
        ? `/all-pages${match[2]}`
        : null;
}

/** A markdown-it plugin that applies `repositoryLink` to every link of a page. */
export function repositoryLinks({ docsDir, ref }) {
    return (md) => {
        md.core.ruler.push("knossos-repository-links", (state) => {
            const file = state.env.realPath ?? state.env.path;
            for (const block of state.tokens) {
                for (const token of block.children ?? []) {
                    if (token.type !== "link_open") {
                        continue;
                    }
                    const href = token.attrGet("href");
                    const rewritten =
                        href === null
                            ? null
                            : (indexRoute(href, file, docsDir) ??
                              repositoryLink(href, file, docsDir, ref));
                    if (rewritten !== null) {
                        token.attrSet("href", rewritten);
                    }
                }
            }
        });
    };
}
