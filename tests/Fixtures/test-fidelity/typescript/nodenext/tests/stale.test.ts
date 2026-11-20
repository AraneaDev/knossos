import { it, vi } from 'vitest';

vi.mock('../src/Store.js', () => ({ Stor: vi.fn() }));

it('mocks a member the module no longer has', () => {});
