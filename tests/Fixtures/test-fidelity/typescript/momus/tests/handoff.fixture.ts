import { describe, expect, it, vi } from 'vitest';

interface Engine {
  run(id: string): Promise<{ ok: boolean }>;
}

const MockEngine = { mockImplementation: vi.fn() };

describe('handoff scope isolation', () => {
  it('binds a chained-config mock and hands it off by name', async () => {
    const mockRun = vi.fn().mockResolvedValue({ ok: true });
    MockEngine.mockImplementation(() => ({ run: mockRun }) as unknown as Engine);
    expect(mockRun).toBeDefined();
  });

  it('reuses the same name in a separate scope without colliding', async () => {
    const mockRun = vi.fn().mockReturnValue(1);
    MockEngine.mockImplementation(() => ({ run: mockRun }) as unknown as Engine);
    expect(mockRun).toBeDefined();
  });

  it('hands off an inline mock created inside an object literal', async () => {
    const engine: Engine = {
      run: vi.fn().mockResolvedValue({ ok: false }),
    };
    MockEngine.mockImplementation(() => engine);
    expect(engine.run).toBeDefined();
  });

  it('hands off a mock through an array element', async () => {
    const mockRun = vi.fn();
    const list = [mockRun];
    expect(list).toHaveLength(1);
  });

  it('marks a spy reachable when its target object is handed off', async () => {
    const controller = new AbortController();
    const removeListener = vi.spyOn(controller.signal, 'removeEventListener');
    const opts = { signal: controller.signal };
    expect(opts.signal).toBe(controller.signal);
    expect(removeListener).toHaveBeenCalled();
  });
});

describe('hand-off through installation, not invocation', () => {
  it('installs a configured mock onto a collaborator the subject owns', async () => {
    // The stub is never called by name here; the subject reaches it through `host`.
    const host: { load: () => Promise<number> } = { load: async () => 0 };
    host.load = vi.fn().mockResolvedValue(7);
    expect(host).toBeDefined();
  });

  it('hands a spied-on double to the subject as a configured return value', async () => {
    const double = { fetch: async () => 1 };
    const fetchSpy = vi.spyOn(double, 'fetch').mockResolvedValue(2);
    const factory = { make: () => double };
    vi.spyOn(factory, 'make').mockReturnValue(double);
    expect(fetchSpy).toBeDefined();
  });
});
