import { it, expect, vi } from 'vitest';
import { Db } from '../src/db';
import { LegacyDb } from '../src/legacy/db';

// Healthy (G1-19): db.test.ts is named for src/db.ts as much as for
// src/legacy/db.ts, so the legacy module it mocks is a dependency.
vi.mock('../src/legacy/db');

it('queries through both', () => {
  expect(new Db().query() + new LegacyDb().query()).toBe(1);
});
