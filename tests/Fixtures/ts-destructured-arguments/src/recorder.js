export function recorder() {
    return {
        frames: [],
        async hold(ms) {
            return ms;
        },
        stop() {},
        mark: () => 0,
        idle() {},
    };
}
