// Momus: unannotated parameters with default initializers (Knossos infers no type).
export class P {
  configure(name: string, timeoutMs = 1000, label = "x", flag = false, items: string[] = [], env: Record<string, string> = {}): void {}
  raw(x: number) {}
}
