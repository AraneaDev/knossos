import { it, expect, vi } from 'vitest';
import { Db } from '../../src/db';
import { LegacyDb } from '../../src/legacy/db';

// Genuine (G1-19): legacy/db.test.ts is named for src/legacy/db.ts, which it mocks.
vi.mock('../../src/legacy/db');

it('queries the legacy db', () => {
  expect(new Db().query() + new LegacyDb().query()).toBe(1);
});
