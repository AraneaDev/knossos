// The site's theme: VitePress's default theme, dressed as the web pane.
//
// The look lives in style.css, set through VitePress's own CSS variables. This
// module adds two behaviours: a press on a screenshot opens it over the page as
// wide as the window, and a second press shows it at the size it was captured,
// since a pane shot at column width is too small to read; and the browser's
// chrome colour (theme-color) follows the appearance switch.
import DefaultTheme from "vitepress/theme-without-fonts";
import { THEME_COLOR } from "../meta.mjs";
import "./style.css";

const VIEWER_ID = "knossos-viewer";

/** Close the open viewer, if any, and give the focus back. */
function closeViewer() {
    const viewer = document.getElementById(VIEWER_ID);
    if (!viewer) {
        return;
    }
    const opener = viewer.opener;
    viewer.remove();
    document.documentElement.classList.remove("knossos-viewing");
    opener?.focus?.();
}

/** Open src over the page, as wide as the window, with a press to see it larger. */
function openViewer(src, alt, opener) {
    closeViewer();
    const viewer = document.createElement("div");
    viewer.id = VIEWER_ID;
    viewer.setAttribute("role", "dialog");
    viewer.setAttribute("aria-modal", "true");
    viewer.setAttribute("aria-label", alt || "Screenshot");
    viewer.opener = opener;

    const close = document.createElement("button");
    close.type = "button";
    close.className = "knossos-viewer-close";
    close.textContent = "x: close";
    close.addEventListener("click", closeViewer);

    const image = document.createElement("img");
    image.src = src;
    image.alt = alt;
    // It opens as wide as the window allows. A press on it shows it at the size
    // it was captured at (half its pixels: most shots are taken at twice the
    // CSS size), to scroll around; a second press fits it again.
    const fitted = () =>
        Math.min(image.naturalWidth, document.documentElement.clientWidth - 32);
    const captured = () => Math.max(image.naturalWidth / 2, fitted());
    const show = (width, zoomed) => {
        image.style.width = `${Math.round(width)}px`;
        image.classList.toggle("zoomed", zoomed);
    };
    image.addEventListener("load", () => show(fitted(), false));
    image.addEventListener("click", () => {
        const zoomed = !image.classList.contains("zoomed");
        show(zoomed ? captured() : fitted(), zoomed);
    });

    viewer.append(close, image);
    viewer.addEventListener("click", (event) => {
        if (event.target === viewer) {
            closeViewer();
        }
    });
    document.body.append(viewer);
    document.documentElement.classList.add("knossos-viewing");
    close.focus();
}

/** The screenshot an image stands for: a reduced copy names it in data-full. */
function fullSize(image) {
    return image.dataset.full || image.currentSrc || image.src;
}

/** Let the keyboard reach every screenshot on the page. */
function focusable() {
    for (const image of document.querySelectorAll(".vp-doc img")) {
        if (!image.closest("a")) {
            image.tabIndex = 0;
        }
    }
}

/** One listener for the whole page, so screenshots on pages loaded later work too. */
function listen() {
    document.addEventListener("click", (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const link = target.closest("a[data-viewer]");
        if (link) {
            event.preventDefault();
            openViewer(link.href, link.dataset.viewer, link);
            return;
        }
        const image = target.closest(".vp-doc img");
        if (image && !image.closest("a")) {
            openViewer(fullSize(image), image.alt, image);
        }
    });
    document.addEventListener("keydown", (event) => {
        const target = event.target;
        if (
            event.key === "Enter" &&
            target instanceof HTMLImageElement &&
            target.closest(".vp-doc")
        ) {
            openViewer(fullSize(target), target.alt, target);
            return;
        }
        if (
            document.getElementById(VIEWER_ID) &&
            (event.key === "Escape" || event.key === "x")
        ) {
            closeViewer();
        }
    });
}

/** Keep theme-color on the page's background as the appearance switches. */
function followAppearance() {
    const root = document.documentElement;
    const sync = () => {
        const meta = document.querySelector('meta[name="theme-color"]');
        meta?.setAttribute(
            "content",
            root.classList.contains("dark")
                ? THEME_COLOR.dark
                : THEME_COLOR.light,
        );
    };
    new MutationObserver(sync).observe(root, {
        attributes: true,
        attributeFilter: ["class"],
    });
    sync();
}

export default {
    extends: DefaultTheme,
    enhanceApp({ router }) {
        if (typeof document === "undefined") {
            return;
        }
        listen();
        followAppearance();
        const previous = router.onAfterRouteChange;
        router.onAfterRouteChange = async (to) => {
            await previous?.(to);
            closeViewer();
            setTimeout(focusable, 100);
        };
        setTimeout(focusable, 100);
    },
};
