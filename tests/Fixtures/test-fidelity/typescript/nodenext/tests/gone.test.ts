import { it, vi } from 'vitest';

vi.mock('../src/Gone.js', () => ({}));

it('mocks a module that is not there', () => {});
