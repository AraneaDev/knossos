// A table of methods, reached by a key only the run time knows.
export const STEP_HANDLERS = {
    async type(step) {
        return step.text;
    },
    wait(step) {
        return step.ms;
    },
    label: 'not a function',
};

export async function run(step) {
    return STEP_HANDLERS[step.do](step);
}

// Picked both ways: the literal key makes `type` certain.
export function typeAgain(step) {
    STEP_HANDLERS[step.do](step);
    return STEP_HANDLERS['type'](step);
}
