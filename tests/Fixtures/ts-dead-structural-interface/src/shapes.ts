/** Fits `Stamped` with a member it inherits: called through `Stamped`, it is reached. */
export interface Stamped {
  stamp(): number
  label(): string
}

export class Base {
  stamp(): number {
    return 1
  }
}

export class Child extends Base {
  label(): string {
    return 'child'
  }
}

export function show(item: Stamped): string {
  return item.label() + item.stamp()
}

/** Lacks `name`, which `Tracked` takes from `Named`: it fits neither, and stays dead. */
export interface Named {
  name(): string
}

export interface Tracked extends Named {
  track(): void
}

export class Partial {
  track(): void {}
}

export function follow(item: Tracked): void {
  item.track()
}

/** Keeps `record` private, so no `Checks` can be this: its `checkedAt` stays dead. */
export class Secret {
  async checkedAt(name: string): Promise<number | undefined> {
    return name.length
  }

  private async record(name: string, at: number): Promise<void> {
    void name
    void at
  }
}
