declare function debounce<T>(fn: T): T;

export interface Options {
    depth: number;
}

export function scan(root: string, limit?: number, mode = 1, ...rest: string[]): Promise<void> {
    return Promise.resolve();
}

export function untyped(a, b) {
    return a;
}

export const load = async ({ depth }: Options, [first]: string[]): Promise<Options> => ({ depth });

export const wrapped = debounce(() => {});

export class Store {
    get(this: Store, id: number): Options | undefined {
        return undefined;
    }

    handler = (event: Event): void => {};

    wide(
        value: {
            alpha: string;
            beta: string;
            gamma: string;
            delta: string;
            epsilon: string;
            zeta: string;
        },
    ): void {}

    many(a, b, c, d, e, f, g, h, i, j, k, l, m): void {}
}
