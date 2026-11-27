import { describe, it, expect } from 'vitest';
import { Calc, useCalc } from '../src/calc';

describe('calc', () => {
  // Healthy (G1-10): `it.fails` passes only because its assertion fails.
  it.fails('fails constant', () => { expect(1).toBe(2); });
  it.skipIf(false)('skipIf runs production', () => { expect(useCalc(new Calc(), [1])).toBe(2); });
  // Genuine (G1-17): always fails, so it cannot pass.
  it('always fails', () => {
    expect(true).not.toBe(true);
  });
  // Genuine (G1-10): a concurrent test is a test, and both tests of one line are read.
  it.concurrent('concurrent constant', () => { expect(1).toBe(1); });
  it('one', () => { expect(2).toBe(2); }); it('two', () => { expect(3).toBe(3); });
});
