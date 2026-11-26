// Momus packages/cli/test/synthesize.test.ts fixture().
export class Svc {
  totalFor(id: string, opts?: number): number { return 0; }
  get label(): string { return "x"; }
  private hidden(): void {}
}
export class Other {
  ping(): void {}
}
