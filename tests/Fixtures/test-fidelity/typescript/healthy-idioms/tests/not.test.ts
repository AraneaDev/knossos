import { it, expect, vi } from 'vitest';
import { Calc, useCalc } from '../src/calc';

it('never totals with nothing', () => {
  const c = new Calc();
  const spy = vi.spyOn(c, 'rate');
  c.total([]);
  // Vacuous (G3-02): no call lists no arguments, so this cannot fail; no arity drift.
  expect(spy).not.toHaveBeenCalledWith();
  expect(spy).not.toHaveBeenCalled();
});

it('rates once', () => {
  const c = new Calc();
  const spy = vi.spyOn(c, 'rate');
  c.total([1]);
  // Genuine: rate needs one argument, and the matcher lists none.
  expect(spy).toHaveBeenCalledWith();
});

// Healthy (G3-02): `.rejects` is read as `.resolves` is.
it('rejects', async () => {
  await expect(Promise.reject(new Error('x'))).rejects.toThrow('x');
  expect(useCalc(new Calc(), [1])).toBe(2);
});
