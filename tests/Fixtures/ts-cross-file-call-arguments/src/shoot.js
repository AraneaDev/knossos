import cut, { hold, tally } from './steps.js';
import { frame } from './lib/index.js';
import { HANDLERS } from './handlers.js';
import { recorder } from './devices.js';

export function shoot(step) {
    const tape = recorder();
    hold({ tape });
    cut({ tape: recorder() });
    frame({ tape });
    HANDLERS[step.do]({ tape });
    tally({ items: new Map() });
}
