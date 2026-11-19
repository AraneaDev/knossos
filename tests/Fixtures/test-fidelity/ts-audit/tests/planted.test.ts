import { expect, it, vi } from 'vitest';

it('is always true', () => {
  expect(true).toBe(true);
});

it('asserts a spy nothing calls', () => {
  const target = { run: (): number => 1 };
  const spy = vi.spyOn(target, 'run');
  expect(spy).toHaveBeenCalled();
});
