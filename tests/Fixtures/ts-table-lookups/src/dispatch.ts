import { commands, handlers, type Message } from './handlers';

export function dispatch(message: Message): void {
    handlers[message.type]?.(message);
}

export function start(): number {
    return commands['start']();
}
