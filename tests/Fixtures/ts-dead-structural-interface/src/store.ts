/** Meets `Checks` by shape alone. */
export class Store {
  private readonly times = new Map<string, number>()

  async checkedAt(name: string): Promise<number | undefined> {
    return this.times.get(name)
  }

  async record(name: string, at: number): Promise<void> {
    this.times.set(name, at)
  }

  /** In no interface's shape and called by nothing: dead. */
  async unused(): Promise<void> {
    this.times.clear()
  }
}
