import { describe, expect, it, vi } from 'vitest';
import { Db } from '../src/services/db';
import { LedgerService } from '../src/services/ledger';

vi.mock('../src/services/db', () => ({ Db: vi.fn(() => ({ query: vi.fn() })) }));

describe('LedgerService', () => {
  it('echoes the stub value against itself (TAUT-002)', () => {
    const mocked = { getTotal: vi.fn() };
    mocked.getTotal.mockReturnValue(42);
    expect(mocked.getTotal()).toBe(42);
  });

  it('spies on a member that does not exist (DRIFT-001)', async () => {
    const service = new LedgerService({} as Db);
    const spy = vi.spyOn(service, 'totalForX');
    await expect(service.totalFor('inv-1')).resolves.toBeDefined();
    expect(spy).toHaveBeenCalled();
  });

  it('healthy: flows through production', async () => {
    const dbMock = vi.mocked(new Db());
    dbMock.query.mockResolvedValue([{ id: 'inv-1', totalCents: 4200, status: 'open' }]);
    const service = new LedgerService(dbMock as unknown as Db);
    vi.spyOn(service, 'totalFor');
    const invoice = await service.totalFor('inv-1');
    expect(invoice.totalCents).toBe(4200);
  });
});

describe('assertions that run no production code', () => {
  // planted: the spy is unconfigured, nothing in this test calls production, so nothing
  // could ever have reached it — `toHaveBeenCalled` can only fail (TAUT-006), and every
  // operand is mock-derived (TAUT-004). A spy in a test that DOES exercise production is
  // a different case: the SUT can reach it without ever naming it.
  it('asserts an unconfigured spy without exercising the subject', () => {
    const stub = { totalFor: (id: string) => id };
    const spy = vi.spyOn(stub, 'totalFor');
    expect(spy).toHaveBeenCalled();
  });
});
