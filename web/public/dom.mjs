/**
 * What the page's view does to its document: the size of a character cell,
 * the element of a press or field, and the patching of a container's rows.
 * Only a browser loads this module; the pane's logic stays in app.mjs, which
 * the terminal client shares.
 */

/** One character cell of the page's grid, in pixels. */
export function cellOf(container) {
    const probe = Object.assign(document.createElement("span"), {
        className: "probe",
        textContent: "M".repeat(10),
    });
    container.append(probe);
    const box = probe.getBoundingClientRect();
    probe.remove();
    return { width: box.width / 10 || 8, height: box.height || 16 };
}

/** The element of the page keyed `key`, a press or a field. */
export const keyed = (root, key) =>
    key === null || key === undefined
        ? null
        : root.querySelector(
              `[data-press="${CSS.escape(key)}"], [data-field="${CSS.escape(key)}"]`,
          );

/**
 * Makes `container`'s children the `entries` (keyed HTML), drawing anew only
 * those that changed since the last call (see `patchPlan`). A row that holds
 * the focused field is changed around it, never replaced: the field keeps
 * its node, so its value, its caret and the focus stay as the person left
 * them, whatever a frame drawn before their last keystroke says.
 */
export function patcher(container, patchPlan) {
    let drawn = [];
    const make = (html) => {
        const holder = document.createElement("template");
        holder.innerHTML = html;
        return holder.content.firstElementChild;
    };
    /** `node` changed to `html` around `input`, which stays where it is; null when the new row has no field of its own. */
    const around = (node, html, input) => {
        const fresh = make(html);
        const twin = fresh.querySelector(
            `[data-field="${CSS.escape(input.dataset.field)}"]`,
        );
        if (twin === null || input.parentElement !== node) return null;
        for (const child of [...node.childNodes])
            if (child !== input) child.remove();
        const kids = [...fresh.childNodes];
        const at = kids.indexOf(twin);
        input.before(...kids.slice(0, at));
        input.after(...kids.slice(at + 1));
        for (const { name, value } of twin.attributes)
            if (name !== "value" && input.getAttribute(name) !== value)
                input.setAttribute(name, value);
        return node;
    };
    return (entries) => {
        const nodes = new Map(drawn.map((e) => [e.key, e.node]));
        const active = document.activeElement;
        const field =
            active instanceof HTMLInputElement && container.contains(active)
                ? active
                : null;
        // The rows gone go first, so none stands between two that stay and makes one of them move.
        const kept = new Set(entries.map((e) => e.key));
        for (const e of drawn) if (!kept.has(e.key)) e.node.remove();
        drawn = patchPlan(drawn, entries).map((step, i) => {
            let node = nodes.get(step.key);
            if (!step.reuse) {
                const old = node;
                node =
                    (old !== undefined &&
                        field !== null &&
                        old.contains(field) &&
                        around(old, step.html, field)) ||
                    make(step.html);
                // In the old one's place: the rows after it stay where they are, and are not moved (a move would blur a field).
                if (
                    old !== undefined &&
                    old !== node &&
                    old.parentNode === container
                )
                    old.replaceWith(node);
            }
            const here = container.children[i];
            if (here !== node) container.insertBefore(node, here ?? null);
            return { key: step.key, html: step.html, node };
        });
        while (container.children.length > drawn.length)
            container.lastElementChild.remove();
    };
}
