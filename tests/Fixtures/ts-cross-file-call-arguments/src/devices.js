// Two values with members of the same names.
export function recorder() {
    return {
        hold() {},
        stop() {},
        idle() {},
    };
}

export function camera() {
    return {
        hold() {},
        stop() {},
    };
}
