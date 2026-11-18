import { describe, expect, it, vi } from 'vitest';
import { InvoiceService } from '../src/services/invoice';

describe('InvoiceService', () => {
  it('planted: configures a return value not assignable to the production type (DRIFT-003)', () => {
    const svc = new InvoiceService();
    const spy = vi.spyOn(svc, 'totalCents');
    // planted: 'nope' is not assignable to the number return type (intentionally a type error)
    spy.mockReturnValue('nope');
    expect(svc.totalCents()).toBeDefined();
  });

  it('healthy: configures a return value assignable to the production type', () => {
    const svc = new InvoiceService();
    const spy = vi.spyOn(svc, 'totalCents');
    spy.mockReturnValue(42);
    expect(svc.totalCents()).toBeDefined();
  });

  it('healthy: mockRejectedValue sets a rejection reason, not a return value', () => {
    const svc = new InvoiceService();
    const spy = vi.spyOn(svc, 'fetch');
    spy.mockRejectedValue(new Error('boom'));
    expect(svc.fetch()).toBeDefined();
  });

  it('planted: implementation callback returns a type not assignable to the production return', () => {
    const svc = new InvoiceService();
    const spy = vi.spyOn(svc, 'totalCents');
    spy.mockImplementation(() => 'nope');
    expect(svc.totalCents()).toBeDefined();
  });

  it('planted: mockReturnValueOnce is checked like the persistent variant', () => {
    const svc = new InvoiceService();
    const spy = vi.spyOn(svc, 'totalCents');
    spy.mockReturnValueOnce('nope');
    expect(svc.totalCents()).toBeDefined();
  });
});
