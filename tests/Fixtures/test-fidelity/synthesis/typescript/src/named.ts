// Momus: resolves named interface returns, incl. Promise<named> and nested members.
export interface Address { city: string; zip?: number }
export interface User { id: number; name: string; address: Address; active: boolean }
export interface Session { token: string; close(): void; refresh(): Promise<string> }
export class Named {
  find(): Promise<User> { return Promise.resolve({} as User); }
  home(): Address { return {} as Address; }
  onlyMethods(): { run(): void } { return { run() {} }; }
  session(): Session { return {} as Session; }
}
