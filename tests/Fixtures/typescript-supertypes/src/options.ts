interface Options { handler(code: number): void; setHeaders(path: string): void }
interface AsyncOptions { loader(): Promise<unknown> }
type Loader = () => Promise<unknown>
declare function limit(options?: Partial<Options>): void
declare function serve(root: string, options?: Options): void
declare function lazy(source: Loader | AsyncOptions): void
declare function loose<T>(options: T | undefined): T
export const limiter = limit({ handler: (code) => { void code } })
serve('.', { handler() {}, setHeaders(path) { void path } })
export const terminal = lazy({ loader: () => Promise.resolve(1) })
loose({ run(): number { return 1 } })
