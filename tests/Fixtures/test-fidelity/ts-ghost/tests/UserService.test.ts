import { expect, it, vi } from 'vitest';
import { UserService } from '../src/UserService';

it('stubs a method the service no longer has', () => {
  const service = new UserService();
  vi.spyOn(service, 'getProfil').mockReturnValue('stub');
  expect(service.getProfile('1')).toBe('1');
});
