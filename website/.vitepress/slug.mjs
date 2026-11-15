// The anchor of a heading, by GitHub's rule: lower case, then everything that is
// not a letter, a number, a combining mark, `_`, a space or `-` is dropped, and
// each space becomes a hyphen. The pages are written for GitHub, so a link such
// as `page.md#one-files-context` has to land on the same heading on the site.
// tools/documentation-check.php applies the same rule to the links.
export function githubSlug(text) {
    return text
        .toLowerCase()
        .replace(/[^\p{L}\p{M}\p{N}_ -]/gu, "")
        .replace(/ /g, "-");
}
