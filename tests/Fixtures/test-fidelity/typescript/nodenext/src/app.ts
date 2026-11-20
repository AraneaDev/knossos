import { Store } from './Store.js';
import { send } from './Mailer.mjs';
import { Badge } from './Badge.js';

export const run = (): [string, boolean, string] => [new Store().load('1'), send('a@b.c'), Badge()];
