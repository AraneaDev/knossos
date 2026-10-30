/** A contract a value meets by shape: nothing names it in `implements`. */
export interface Checks {
  checkedAt(name: string): Promise<number | undefined>
  record(name: string, at: number): Promise<void>
}

export class Watch {
  constructor(private readonly shared: () => Checks | null) {}

  async check(name: string): Promise<void> {
    const checks = this.shared()
    if ((await checks?.checkedAt(name)) === undefined) await checks?.record(name, Date.now())
  }
}
