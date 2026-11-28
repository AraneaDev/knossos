import { it, expect, vi } from 'vitest';
import { Leg, Router, send } from '../src/route';

// Healthy (G3-19): a string enum's case and a string-literal union's member pinned in a matcher.
it('routes by sea in the fast lane', () => {
  const router = new Router();
  const reroute = vi.spyOn(router, 'reroute').mockReturnValue(true);
  const lane = vi.spyOn(router, 'lane').mockReturnValue(true);
  expect(send(router, Leg.Sea, 'fast')).toBe(true);
  expect(reroute).toHaveBeenCalledWith(Leg.Sea);
  expect(lane).toHaveBeenCalledWith('fast');
});
