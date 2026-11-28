// Momus: emits typed vi.fn generics and object-shape return values.
export class Shape {
  build(label: string, n?: number): { ok: boolean; count: number } {
    return { ok: true, count: n ?? 0 };
  }
  pair(): [string, number] { return ["x", 1]; }
}
