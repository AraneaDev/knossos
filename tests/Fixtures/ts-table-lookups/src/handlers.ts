export type Message = { type: 'ping' } | { type: 'pong' } | { type: 'quit' };

export const handlers: Record<string, ((message: Message) => void) | undefined> = {
    ping: (message) => { void message; },
    pong: function (message) { void message; },
    label: undefined,
};

export const commands = {
    start: () => 1,
    stop: () => 2,
} satisfies Record<string, () => number>;

const idle = {
    nap: () => 3,
};

export function napLength(): number {
    return idle.nap();
}
