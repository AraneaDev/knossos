// Momus: derives type-appropriate placeholder return values from the return type.
export class Typed {
  num(): number { return 0; }
  str(): string { return ''; }
  flag(): boolean { return false; }
  list(): string[] { return []; }
  maybe(): number | undefined { return undefined; }
  late(): Promise<number> { return Promise.resolve(0); }
  custom(): Widget { return {} as Widget; }
  voidy(): void {}
}
export interface Widget { id: number }
