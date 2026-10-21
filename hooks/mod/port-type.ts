/**
 * The engine as the mod's modules reach it ({@link Port}), as a type alone,
 * so a module that only takes a port imports nothing that runs.
 */
import type { EngineInterface, PluginState } from 'claude-code'

/**
 * One value of the session state as the mod's modules reach it: read, or
 * changed by a function of what it holds. The engine's `read` and `update`
 * take only an atom declared in the hooks module itself, so `register.tsx`
 * declares every atom and hands each one over as a cell.
 */
export type Cell<T> = { read(): Promise<T>; update(change: (value: T) => T): Promise<T> }

/** Every value the mod keeps in the session state, by its key in the contract (`types/index.d.ts`). */
type Cells = { [K in keyof PluginState['knossos']]-?: Cell<PluginState['knossos'][K]> }

type Engine = EngineInterface

/**
 * The engine as the mod's modules reach it: the calls the mod makes, each
 * passed straight through to `$`, and the session state as {@link Cells}.
 *
 * The engine follows `$` only into functions declared in the hooks module
 * itself, never across an import, and refuses `$.<noun>` used as a value:
 * `register.tsx` builds one of these from the hook's own `$` on every
 * dispatch (`portOf`), spelling each call out, and the modules take it
 * instead of `$`.
 */
export type Port = {
  clock: Pick<Engine['clock'], 'now' | 'after' | 'every'>
  ui: Pick<Engine['ui'], 'log' | 'toast' | 'copy' | 'focus' | 'scroll' | 'panes' | 'invalidate' | 'open' | 'close'>
  session: Pick<Engine['session'], 'root' | 'id' | 'append'>
  process: Pick<Engine['process'], 'run' | 'spawn'>
  fs: Pick<Engine['fs'], 'stat'>
  store: Pick<Engine['store'], 'get' | 'set'>
  config: Pick<Engine['config'], 'list'>
  command: Pick<Engine['command'], 'register'>
  prompt: Pick<Engine['prompt'], 'submit'>
  plugin: { root: string }
  state: Cells
}
