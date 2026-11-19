import { describe, expect, it } from 'vitest';
import { LedgerService } from '@svc/ledger';

describe('alias import', () => {
  it('imports via the paths alias', () => {
    expect(typeof LedgerService).toBe('function');
  });
});
