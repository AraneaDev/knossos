import { recorder } from './recorder.js';

/** @param {{ tape: ReturnType<typeof recorder> }} context */
export function holdDocumented({ tape }) {
    return tape.hold(1);
}
