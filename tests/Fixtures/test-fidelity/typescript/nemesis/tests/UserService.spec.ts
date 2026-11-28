import { describe, expect, it, vi } from 'vitest';
import { UserService } from '../src/UserService.js';

describe('UserService contract drift fixtures', () => {
  it('ghost method: renamed method still stubbed', async () => {
    const service = new UserService();
    const spy = vi.spyOn(service, 'getProfil').mockResolvedValue({
      id: '1',
      displayName: 'A',
      email: 'a@b.c',
    } as any);
    expect(spy).toBeDefined();
  });

  it('return drift: stub returns wrong shape', async () => {
    const service = new UserService();
    vi.spyOn(service, 'getProfile').mockResolvedValue({ id: '1' } as any);
    expect(await service.getProfile('1')).toBeTruthy();
  });

  it('visibility breach: private method stubbed directly', () => {
    const service = new UserService() as any;
    vi.spyOn(service, 'purgeCache').mockReturnValue(undefined);
    expect(service).toBeDefined();
  });

  it('arity mismatch: updateProfile called with too many args', () => {
    const service = new UserService();
    const spy = vi.spyOn(service, 'updateProfile');
    spy.mockImplementation(((...args: unknown[]) => true) as any);
    (spy as any).toHaveBeenCalledWith('1', {}, 'extra');
    expect(true).toBe(true);
  });

  it('clean: correct contract', async () => {
    const service = new UserService();
    vi.spyOn(service, 'getProfile').mockResolvedValue({
      id: '1',
      displayName: 'A',
      email: 'a@b.c',
    });
    expect(await service.getProfile('1')).toMatchObject({ id: '1' });
  });
});
