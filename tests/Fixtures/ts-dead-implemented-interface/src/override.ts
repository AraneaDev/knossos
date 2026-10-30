/** A contract only the subclass implements, with a method of its own. */
export interface Measured {
  area(): number
}

export class Plain {
  area(): number {
    return 1
  }
}

/** Overrides the base's `area`: the base's callers never reach this one. */
export class Framed extends Plain implements Measured {
  override area(): number {
    return 2
  }
}

/** Calls the base's method, typed as the base: says nothing of the contract. */
export function plainArea(): number {
  return new Plain().area()
}
