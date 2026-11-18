export interface UserProfile {
  id: string;
  displayName: string;
  email: string;
}

export class UserService {
  private cache: Map<string, UserProfile> = new Map();

  async getProfile(id: string): Promise<UserProfile> {
    return { id, displayName: 'Test', email: 'test@example.com' };
  }

  updateProfile(id: string, patch: Partial<UserProfile>): boolean {
    return this.cache.has(id) && patch !== null;
  }

  private purgeCache(): void {
    this.cache.clear();
  }

  purgeAll(): number {
    this.purgeCache();
    return this.cache.size;
  }
}
