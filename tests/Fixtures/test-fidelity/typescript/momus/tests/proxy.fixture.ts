import { vi } from 'vitest';
import { LedgerService } from '../src/services/ledger';

const proxyMock = new Proxy({}, {
  get: () => vi.fn(),
}) as unknown as LedgerService;

const healthyProxy = new Proxy({}, {
  get: (_target, key) => Reflect.get({}, key),
}) as unknown as LedgerService;

void proxyMock;
void healthyProxy;
