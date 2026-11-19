import { expect, it, vi } from 'vitest';
import { Db } from '../src/services/db';
import { LedgerService } from '../src/services/ledger';

const service = new LedgerService({} as Db);
const overAritySpy = vi.spyOn(service, 'totalFor').mockImplementation(
  async (id: string, extra: boolean) => ({ id, totalCents: 0, status: 'open' }),
);
const wrongTypeSpy = vi.spyOn(service, 'totalFor').mockImplementation(
  async (id: number) => ({ id: String(id), totalCents: 0, status: 'open' }),
);
const healthyTypeSpy = vi.spyOn(service, 'totalFor').mockImplementation(
  async (id: string) => ({ id, totalCents: 0, status: 'open' }),
);

const wrongDb = {
  query: vi.fn((sql: string, id: string, extra: boolean) => []),
} as unknown as Db;

const healthyDb = {
  query: vi.fn((sql: string, id: string) => []),
} as unknown as Db;

it('uses the over-arity double (DRIFT-002)', () => {
  expect(wrongDb.query('sql', 'id', true)).toBeDefined();
});

it('keeps the matching-arity double healthy', () => {
  expect(healthyDb.query('sql', 'id')).toBeDefined();
});
