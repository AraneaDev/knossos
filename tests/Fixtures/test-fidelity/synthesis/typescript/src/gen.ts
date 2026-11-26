// Momus: method- and class-level generics.
export class Box<T> {
  identity<U>(x: U): U { return x; }
  get(): T { throw new Error(); }
  put(v: T): void {}
}
