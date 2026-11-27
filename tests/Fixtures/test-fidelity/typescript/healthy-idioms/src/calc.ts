export class Calc {
  rate(x: number): number { return x * 2; }
  total(xs: number[]): number { return xs.reduce((a, b) => a + this.rate(b), 0); }
}
export function useCalc(c: Calc, xs: number[]): number { return c.total(xs); }
