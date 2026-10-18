/**
 * One `knossos web` per project: the server that runs for a project holds a
 * lock file in the data directory, `web/<sha256 of the project's real
 * path>.json`, holding its pid and port. A second start finds it and points
 * at the running server instead of starting another. A lock that holds
 * nothing any more (its process gone, or its pid now another process's
 * after a restart, so its address answers no one) is taken over by the next
 * start.
 *
 * Beside it, `<same>.host.json` keeps the project's token and the port it
 * was last served on, so the address stays the same from one start to the
 * next, and an installed app keeps working. The token is in no other file:
 * an address is made from the two. Both files, and `web/` itself, are the
 * owner's alone.
 */
import { createHash, randomBytes } from 'node:crypto'
import { chmodSync, existsSync, mkdirSync, readFileSync, realpathSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

export type Claim = { kind: 'mine'; record(port: number): void; release(): void } | { kind: 'running'; url: string }

/** The address of the server on `port` that holds `token`: the one `knossos web` prints. */
export const addressOf = (port: number, token: string): string => `http://127.0.0.1:${port}/?t=${token}`

/** The data directory's `web/`, made (or kept) the owner's alone: the address in it is the server's key. */
function webDir(dataDir: string): string {
  const dir = join(dataDir, 'web')
  mkdirSync(dir, { recursive: true, mode: 0o700 })
  try {
    // One made before, under a looser mode, is narrowed too.
    chmodSync(dir, 0o700)
  } catch {
    // Not this user's to change: its files are still made owner-only.
  }
  return dir
}

/** The lock file of the project at `root`, by its real path, so a link to it is the same project. */
export function lockFile(dataDir: string, root: string): string {
  let real = root
  try {
    real = realpathSync(root)
  } catch {
    // A project that is not there has only the path it was given.
  }
  return join(dataDir, 'web', `${createHash('sha256').update(real).digest('hex')}.json`)
}

/** How long a server that answers is given to answer. */
const PROBE_MS = 300
/** How long a lock may wait for its server's URL: a start takes far less. */
const STARTING_MS = 10_000

/** Whether a process `pid` runs: one this user may not signal runs too. */
function alive(pid: unknown): boolean {
  if (typeof pid !== 'number' || !Number.isInteger(pid) || pid <= 0) return false
  try {
    process.kill(pid, 0)
    return true
  } catch (error) {
    return (error as NodeJS.ErrnoException).code === 'EPERM'
  }
}

/** The lock's `{ pid, port }`, or null for none or one that cannot be read. */
function held(file: string): { pid: unknown; port: unknown } | null {
  try {
    const parsed: unknown = JSON.parse(readFileSync(file, 'utf8'))
    return parsed !== null && typeof parsed === 'object' ? (parsed as { pid: unknown; port: unknown }) : null
  } catch {
    return null
  }
}

/** Whether a server answers at `url` (its token in it) with its page. */
async function answers(url: string): Promise<boolean> {
  try {
    const res = await fetch(url, { signal: AbortSignal.timeout(PROBE_MS) })
    await res.body?.cancel()
    return res.status === 200
  } catch {
    return false
  }
}

const isPort = (port: unknown): port is number => typeof port === 'number' && Number.isInteger(port) && port > 0 && port < 65_536

/** Whether the lock in `file`, held by the live process it names, still holds: its server answers at `url`, or is still starting. */
async function holds(file: string, url: string | null): Promise<boolean> {
  if (url !== null) return answers(url)
  try {
    return Date.now() - statSync(file).mtimeMs < STARTING_MS
  } catch {
    return false
  }
}

/**
 * The project at `root` for this process (`mine`: `record` its port once the
 * server listens, `release` when it stops), or the address of the server
 * that already runs for it, made with the project's `token` (empty while
 * that one is still starting). The file is created exclusively, so of two
 * starts at once only one takes it.
 */
export async function claim(dataDir: string, root: string, token: string): Promise<Claim> {
  const file = lockFile(dataDir, root)
  webDir(dataDir)
  const write = (port: number | null, flag: 'wx' | 'w') => writeFileSync(file, JSON.stringify({ pid: process.pid, port }), { flag, mode: 0o600 })
  for (let attempt = 0; attempt < 2; attempt++) {
    try {
      write(null, 'wx')
      let released = false
      return {
        kind: 'mine',
        record: port => write(port, 'w'),
        release: () => {
          if (released) return
          released = true
          rmSync(file, { force: true })
        },
      }
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code !== 'EEXIST') throw error
    }
    const other = held(file)
    const url = other !== null && isPort(other.port) ? addressOf(other.port, token) : null
    if (other !== null && other.pid !== process.pid && alive(other.pid) && (await holds(file, url))) return { kind: 'running', url: url ?? '' }
    // Its process is gone, or its pid is another process's now: the lock is stale.
    rmSync(file, { force: true })
  }
  throw new Error(`could not take the lock ${file}`)
}

/** A project's address that outlives a start: its token, and the port it was last served on. */
export type Identity = { token: string; port: number | null; remember(port: number): void }

/** What the identity file holds, when it holds a token: null for none, unreadable, or not one of ours. */
function kept(file: string): { token: string; port: number | null } | null {
  try {
    const parsed = JSON.parse(readFileSync(file, 'utf8')) as { token?: unknown; port?: unknown }
    if (typeof parsed.token !== 'string' || !/^[0-9a-f]{48}$/.test(parsed.token)) return null
    return { token: parsed.token, port: isPort(parsed.port) ? parsed.port : null }
  } catch {
    return null
  }
}

/** The project's {@link Identity}: read from its file, made (owner-only) when there is none it can use. */
export function identity(dataDir: string, root: string): Identity {
  const file = lockFile(dataDir, root).replace(/\.json$/, '.host.json')
  webDir(dataDir)
  const save = (token: string, at: number | null, flag: 'wx' | 'w') => {
    writeFileSync(file, JSON.stringify({ token, port: at }), { mode: 0o600, flag })
    // A file that was there keeps its mode on a write: set it, whoever made it.
    chmodSync(file, 0o600)
  }
  let found = kept(file)
  if (found === null) {
    const made = randomBytes(24).toString('hex')
    try {
      // Made exclusively: of two first starts at once, both end up with the token that was written first.
      save(made, null, existsSync(file) ? 'w' : 'wx')
      found = { token: made, port: null }
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code !== 'EEXIST') throw error
      found = kept(file) ?? { token: made, port: null }
    }
  }
  const { token, port } = found
  return { token, port, remember: at => save(token, at, 'w') }
}
