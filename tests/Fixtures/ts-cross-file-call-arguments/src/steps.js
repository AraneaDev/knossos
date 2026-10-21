// Untyped functions that take what they need out of their argument.
export function hold({ tape }) {
    tape.hold();
}

export default function cut({ tape }) {
    tape.stop();
}

export function tally({ items }) {
    items.clear();
}
