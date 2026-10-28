/**
 * Takes a project's web server, or finds the one that already runs: the
 * start-up `knossos web` and the terminal pane share. It claims the
 * project's lock, starts the server on the project's kept port and token,
 * and records the port it got. A host that is not a terminal's stderr
 * routes the server's log lines through `log`.
 */
import { claim, identity } from './lock'
import { startServer } from './server'

/** Why `error` happened, as text. */
const messageOf = (error: unknown): string => (error instanceof Error ? error.message : String(error))

/** A server this process runs (stop it with `close`, then `release`), or the address of the one that already runs. */
export type Hosted =
  | { kind: 'mine'; url: string; token: string; port: number; close(): Promise<void>; release(): void }
  | { kind: 'running'; url: string }

/**
 * The server of the project at `root`: started here on the kept port (a
 * free one when that is taken, unless `port` names one), or the running
 * one's address. Throws "could not take the project's lock: ..." or "could
 * not start: ..." when it can do neither.
 */
export async function hostProject(o: {
  root: string
  dataDir: string
  pluginRoot: string
  editor: string | null
  port: number | null
  log?: (text: string) => void
}): Promise<Hosted> {
  let kept: ReturnType<typeof identity>
  let lock: Awaited<ReturnType<typeof claim>>
  try {
    kept = identity(o.dataDir, o.root)
    lock = await claim(o.dataDir, o.root, kept.token)
  } catch (error) {
    throw new Error(`could not take the project's lock: ${messageOf(error)}`, { cause: error })
  }
  if (lock.kind === 'running') return { kind: 'running', url: lock.url }

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
    })
  } catch (error) {
    lock.release()
    throw new Error(`could not start: ${messageOf(error)}`, { cause: error })
  }
  try {
    lock.record(server.port)
    kept.remember(server.port)
  } catch (error) {
    // The server is up but nothing says so: stop it and free the lock rather than leave both held.
    await server.close().catch(() => undefined)
    lock.release()
    throw error
  }
  return { kind: 'mine', url: server.url, token: server.token, port: server.port, close: server.close, release: lock.release }
}
