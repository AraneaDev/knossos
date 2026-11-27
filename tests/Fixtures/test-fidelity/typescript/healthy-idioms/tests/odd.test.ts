import { it, expect, vi } from 'vitest';
import { Odd } from '../src/odd';

// Healthy (G1-27): '#x' is a public method named by a string.
it('stubs a quoted public member', () => {
  const o = new Odd();
  vi.spyOn(o, '#x').mockReturnValue(5);
  expect(o.run()).toBe(7);
});

// Genuine: #hidden is an ECMAScript private member, which no double can replace.
it('stubs a real private member', () => {
  const o = new Odd();
  vi.spyOn(o as any, '#hidden');
  expect(o.run()).toBe(3);
});
