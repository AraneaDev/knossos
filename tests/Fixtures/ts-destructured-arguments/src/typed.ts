import { recorder } from './recorder.js';

type Context = { tape: ReturnType<typeof recorder> };

// The parameter's declared type gives the binding its type.
export function stopTyped({ tape }: Context): void {
    tape.stop();
}
