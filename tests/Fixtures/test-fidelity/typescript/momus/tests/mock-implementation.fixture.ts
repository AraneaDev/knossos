import { vi } from 'vitest';

const vitestFn = vi.fn();
vitestFn.mockImplementation(() => 42);

const jestFn = jest.fn();
jestFn.mockImplementation(() => 7);
