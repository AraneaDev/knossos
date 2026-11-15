/**
 * Takes a project's web server, or finds the one that already runs: the
 * start-up `knossos web` and the terminal pane share. It claims the
 * project's lock, starts the server on the project's kept port and token,
 * and records the port it got. A host that is not a terminal's stderr
 * routes the server's log lines through `log`.
 */
import { claim, identity } from './lock'
import type { Replaced, Silent } from './lock'
import { startServer } from './server'

/** Why `error` happened, as text. */
const messageOf = (error: unknown): string => (error instanceof Error ? error.message : String(error))

/**
 * A server this process runs (stop it with `close`, then `release`; `ready` settles once its start-up is done,
 * `stepped` once it closed itself because another server took the project's lock over), or the address of the
 * one that already runs.
 */
export type Hosted =
  | { kind: 'mine'; url: string; token: string; port: number; kept: number | null; ready: Promise<void>; stepped: Promise<void>; close(): Promise<void>; release(): void }
  | { kind: 'running'; url: string; proven: true }
  | { kind: 'running'; url: string; proven: false; holder: Replaced }

/**
 * The server of the project at `root`: started here on the kept port (a
 * free one when that is taken, unless `port` names one), or the running
 * one's address (`kept` is the port it was last served on, to say when
 * it moved). Throws "could not take the project's lock: ..." or "could
 * not start: ..." when it can do neither.
 */
export async function hostProject(o: {
  root: string
  dataDir: string
  pluginRoot: string
  editor: string | null
  port: number | null
  log?: (text: string) => void
  /** Told when the server that holds the project does not answer at once, with what the start now waits for (see {@link silentText}). */
  silent?: (holder: Silent) => void
}): Promise<Hosted> {
  let kept: ReturnType<typeof identity>
  let lock: Awaited<ReturnType<typeof claim>>
  try {
    kept = identity(o.dataDir, o.root)
    lock = await claim(o.dataDir, o.root, kept.token, o.silent)
  } catch (error) {
    throw new Error(`could not take the project's lock: ${messageOf(error)}`, { cause: error })
  }
  if (lock.kind === 'running') return lock.proven ? { kind: 'running', url: lock.url, proven: true } : { kind: 'running', url: lock.url, proven: false, holder: lock.holder }

  let server: Awaited<ReturnType<typeof startServer>>
  try {
    server = await startServer({
      root: o.root,
      pluginRoot: o.pluginRoot,
      dataDir: o.dataDir,
      port: o.port ?? kept.port ?? 0,
      movable: o.port === null,
      token: kept.token,
      editor: o.editor,
      ...(o.log === undefined ? {} : { log: o.log }),
      holder: lock.holder,
      former: lock.replaced,
    })
  } catch (error) {
    lock.release()
    throw new Error(`could not start: ${messageOf(error)}`, { cause: error })
  }
  try {
    lock.record(server.port)
    kept.remember(server.port)
  } catch (error) {
    // The server is up but nothing says so: stop it and free the lock rather than leave both held. Its start-up is
    // let finish first, or it would go on after the close and start a watcher for a server that no longer runs.
    // It has no bound of its own: each step of the start-up is a wrapper call with its own timeout.
    await server.ready
    await server.close().catch(() => undefined)
    lock.release()
    throw error
  }
  return { kind: 'mine', url: server.url, token: server.token, port: server.port, kept: kept.port, ready: server.ready, stepped: server.stepped, close: server.close, release: lock.release }
}

/**
 * What a start says while the server that holds the project does not answer: a pane suspended in another terminal
 * (resumed with `fg`, it steps down for the one that took over), or a server that is not serving; either way it is
 * taken over once the wait is over.
 */
export function silentText(holder: Silent): string {
  if (holder.stopped) return `Knossos in another terminal (pid ${holder.pid}) is suspended. Resume it there with fg, or wait a few seconds and this one takes over.`
  return `The Knossos server of pid ${holder.pid} does not answer. Waiting a few seconds, then this one takes over.`
}

/**
 * What a start says of the lock's holder it will not send the token to: an older Knossos server that cannot be shown
 * to be the person's, or a program that poses as one on a pid used again. What it needs to go on is named: the
 * process and port in the lock, and the lock file, whose removal lets Knossos start afresh when that process is not
 * theirs.
 */
export function unprovenText(holder: Replaced, lock: string): string {
  return `The lock of this project names process ${holder.pid} on port ${holder.port}, which answers as an older Knossos server and cannot be shown to be yours, so it is sent nothing. If it is yours, stop it. If it is not, remove ${lock} and start again.`
}
