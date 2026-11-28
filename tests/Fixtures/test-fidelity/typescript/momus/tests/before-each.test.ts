import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { Db } from '../src/services/db';
import { LedgerService } from '../src/services/ledger';

const eachMock = { getTotal: vi.fn() };
const allMock = { getTotal: vi.fn() };

beforeEach(() => {
  eachMock.getTotal.mockReturnValue(42);
});

beforeAll(() => {
  allMock.getTotal.mockReturnValue(7);
});

describe('setup-scoped mocks', () => {
  it('uses the beforeEach configuration (TAUT-002)', () => {
    expect(eachMock.getTotal()).toBe(42);
  });

  it('uses the beforeAll configuration (TAUT-002)', () => {
    expect(allMock.getTotal()).toBe(7);
  });

  it('keeps a production-derived assertion healthy', async () => {
    const service = new LedgerService({} as Db);
    await expect(service.totalFor('inv-1')).resolves.toBeDefined();
  });
});

const nestedMock = { getTotal: vi.fn() };

describe('first nested setup scope', () => {
  beforeEach(() => {
    nestedMock.getTotal.mockReturnValue(11);
  });

  it('uses only the first nested setup', () => {
    expect(nestedMock.getTotal()).toBe(11);
  });
});

describe('second nested setup scope', () => {
  beforeEach(() => {
    nestedMock.getTotal.mockReturnValue(22);
  });

  it('uses only the second nested setup', () => {
    expect(nestedMock.getTotal()).toBe(22);
  });
});
