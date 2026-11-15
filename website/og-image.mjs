// Renders the site's social card, public/og-image.png (1200x630), from
// og-image.html with headless Chrome. Dev-only: the PNG is committed, so the
// site build needs no browser.
//
//   npm run og-image
//
// Run it again when docs/images/claude-code/overview.png is reshot, since the
// card shows a crop of the pane from it (CROP below frames the pane in that
// shot), and when the sigil in web/public/icon.svg or the tagline changes.
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import puppeteer from "puppeteer-core";
import { OG_IMAGE } from "./.vitepress/meta.mjs";

const here = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(here, "..");
const screenshot = path.join(
    repositoryRoot,
    "docs/images/claude-code/overview.png",
);
const output = path.join(here, "public", OG_IMAGE.path);

// The pane in overview.png (3672x1600): right of the session's divider, from
// its header row to its key hints, without the close button above it.
const CROP = { x: 2046, y: 66, width: 1592, height: 1360 };
// The frame the crop sits in on the card: its width and the gap around the crop.
const FRAME = { width: 548, padding: 14 };

const scale = (FRAME.width - 2 * FRAME.padding - 2) / CROP.width;
const frameHeight = Math.round(CROP.height * scale) + 2 * FRAME.padding + 2;
const crop = `<style>
.pane { top: ${Math.round((OG_IMAGE.height - frameHeight) / 2)}px; height: ${frameHeight}px; }
.pane .view { inset: ${FRAME.padding}px; }
.pane img {
    --crop-left: ${(-CROP.x * scale).toFixed(2)}px;
    --crop-top: ${(-CROP.y * scale).toFixed(2)}px;
    --crop-width: ${(3672 * scale).toFixed(2)}px;
}
</style>`;

const sigil = fs
    .readFileSync(path.join(repositoryRoot, "web/public/icon.svg"), "utf8")
    .replace(/<style>[\s\S]*?<\/style>/, "")
    .replace(/<title>[\s\S]*?<\/title>/, "");
const page = fs
    .readFileSync(path.join(here, "og-image.html"), "utf8")
    .replace("<!-- sigil -->", sigil)
    .replace("{{screenshot}}", pathToFileURL(screenshot).href)
    .replace("</head>", `${crop}</head>`);

const scratch = fs.mkdtempSync(path.join(os.tmpdir(), "knossos-og-"));
const filled = path.join(scratch, "card.html");
fs.writeFileSync(filled, page);

const browser = await puppeteer.launch({
    executablePath: "/usr/bin/google-chrome",
    args: ["--no-sandbox", "--allow-file-access-from-files"],
});
try {
    const tab = await browser.newPage();
    // Nothing leaves the machine: the card uses local files and system fonts only.
    await tab.setRequestInterception(true);
    tab.on("request", (request) =>
        request.url().startsWith("file:")
            ? request.continue()
            : request.abort(),
    );
    await tab.setViewport({
        width: OG_IMAGE.width,
        height: OG_IMAGE.height,
        deviceScaleFactor: 1,
    });
    await tab.goto(pathToFileURL(filled).href, { waitUntil: "load" });
    await tab.evaluate("document.fonts.ready");
    fs.mkdirSync(path.dirname(output), { recursive: true });
    await tab.screenshot({ path: output, type: "png" });
} finally {
    await browser.close();
    fs.rmSync(scratch, { recursive: true, force: true });
}
console.log(
    `${path.relative(here, output)}: ${OG_IMAGE.width}x${OG_IMAGE.height}, ${Math.round(fs.statSync(output).size / 1024)} KB`,
);
