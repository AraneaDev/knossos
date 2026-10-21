import { hold } from './steps.js';
import { camera } from './devices.js';
import { stopTyped } from './typed.js';

export function flash() {
    hold({ tape: camera() });
    stopTyped({ tape: camera() });
}
