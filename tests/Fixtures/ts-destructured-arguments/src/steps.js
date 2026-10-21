import { recorder } from './recorder.js';

// Handlers that take what they need out of the context, untyped.
export const STEP_HANDLERS = {
    async hold(step, { tape }) {
        await tape.hold(step.ms);
    },
    cut: (step, { tape: recording }) => {
        recording.stop();
    },
};

function measure({ tape, count }) {
    count.toFixed();
    tape.stop();
    return tape.frames.length;
}

export async function play(step) {
    const tape = recorder();
    await STEP_HANDLERS[step.do](step, { tape, shot: step.shot });
    return measure({ tape, count: 1 });
}
