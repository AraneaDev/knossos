export class Odd {
  '#x'(): number { return 1; }
  #hidden(): number { return 2; }
  run(): number { return this['#x']() + this.#hidden(); }
}
