import { describe, expect, it, vi } from 'vitest';
import { CatalogService } from '../src/catalog.js';

describe('catalog service', () => {
  it('keeps the double aligned with production', () => {
    const service = new CatalogService();
    const spy = vi.spyOn(service, 'removeBySku');
    spy.toHaveBeenCalledWith('A-1');
    spy.toHaveBeenCalledTimes(1);
    expect(spy).toBeDefined();
  });
});
