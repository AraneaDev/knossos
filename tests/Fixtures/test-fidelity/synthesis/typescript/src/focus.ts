// Review Focus 3: what the tool cannot answer fully.
import { Remote } from 'remote-lib';

export class Gen<T> {
  value!: T;
  get(): T { throw new Error(); }
  name(): string { return ''; }
}
export class NumGen extends Gen<number> {}

export class Overloaded {
  pick(a: string): string;
  pick(a: number): number;
  pick(a: unknown): unknown { return a; }
  plain(): void {}
}

export class Wide {
  many(a1: number, a2: number, a3: number, a4: number, a5: number, a6: number, a7: number, a8: number, a9: number, a10: number, a11: number, a12: number, a13: number): void {}
  fine(): number { return 0; }
}

export class FromElsewhere extends Remote {
  own(): string { return ''; }
}

export class Async {
  async untyped(id: string) { return id; }
  static make(): Async { return new Async(); }
}

export interface Complete { id: number; save(id: number): Promise<void> }

export function helper(): void {}
