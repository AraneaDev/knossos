import { describe, it, expect, vi } from 'vitest';

describe('echo', () => {
  // Healthy (G1-09): the `return 1` is the inner map's; the stub returns ys.length * 5.
  it('nested return via impl', () => {
    const total = vi.fn();
    total.mockImplementation((xs: number[]) => { const ys = xs.map(() => { return 1; }); return ys.length * 5; });
    expect(total([3, 4])).toBe(10);
  });
  // Healthy (G1-09): two returns, two values; the stub pins neither.
  it('conditional impl', () => {
    const total = vi.fn();
    total.mockImplementation((xs: number[]) => { if (xs.length === 0) { return 0; } return xs.length * 3; });
    expect(total([])).toBe(0);
  });
  // Genuine: every return of the stub is 4, and the assertion reads 4 back.
  it('every return the same literal', () => {
    const total = vi.fn();
    total.mockImplementation((xs: number[]) => { if (xs.length === 0) { return 4; } return 4; });
    expect(total([])).toBe(4);
  });
});
