import { STEP_HANDLERS } from './steps.js';

const COMMANDS = {
    start() {
        return 1;
    },
    stop() {
        return 2;
    },
};

// A literal key names the one entry it reaches.
export function begin() {
    return COMMANDS['start']();
}

// An imported table, picked by a literal key.
export function waitOnce() {
    return STEP_HANDLERS['wait']({ ms: 1 });
}
