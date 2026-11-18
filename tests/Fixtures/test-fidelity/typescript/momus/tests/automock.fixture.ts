import { vi } from 'vitest';

const vitestModule = vi.importMock('../src/services/db');
const jestModule = jest.requireMock('../src/services/db');
const createdModule = jest.createMockFromModule('../src/services/db');
vi.stubGlobal('fetch', vi.fn());

const healthyImport = import('../src/services/db');
void vitestModule;
void jestModule;
void createdModule;
void healthyImport;
