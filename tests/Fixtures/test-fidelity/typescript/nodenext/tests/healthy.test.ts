import { it, vi } from 'vitest';
import { run } from '../src/app.js';

vi.mock('../src/Store.js', () => ({ Store: vi.fn() }));
vi.mock('../src/Mailer.mjs', () => ({ send: vi.fn() }));
vi.mock('../src/Badge.js', () => ({ Badge: vi.fn() }));

it('runs against the mocked modules', () => {
  run();
});
