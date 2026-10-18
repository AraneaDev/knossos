/**
 * One `knossos web` per project: the server that runs for a project holds a
 * lock file in the data directory, `web/<sha256 of the project's real
 * path>.json`, holding its pid and URL. A second start finds it and points
 * at the running server instead of starting another. A lock that holds
 * nothing any more (its process gone, or its pid now another process's
 * after a restart, so its URL answers no one) is taken over by the next
 * start.
 *
 * Beside it, `<same>.host.json` (readable by its owner only) keeps the
 * project's token and the port it was last served on, so the address stays
 * the same from one start to the next, and an installed app keeps working.
 */
import { createHash, randomBytes } from 'node:crypto'
import { chmodSync, mkdirSync, readFileSync, realpathSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

export type Claim = { kind: 'mine'; record(url: string): void; release(): void } | { kind: 'running'; url: string }

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

/** The lock's `{ pid, url }`, or null for none or one that cannot be read. */
function held(file: string): { pid: unknown; url: unknown } | null {
  try {
    const parsed: unknown = JSON.parse(readFileSync(file, 'utf8'))
    return parsed !== null && typeof parsed === 'object' ? (parsed as { pid: unknown; url: unknown }) : null
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

/** Whether the lock in `file`, held by the live process it names, still holds: its server answers, or is still starting. */
async function holds(file: string, url: unknown): Promise<boolean> {
  if (typeof url === 'string' && url !== '') return answers(url)
  try {
    return Date.now() - statSync(file).mtimeMs < STARTING_MS
  } catch {
    return false
  }
}

/**
 * The project at `root` for this process (`mine`: `record` its URL once the
 * server listens, `release` when it stops), or the URL of the server that
 * already runs for it (empty while that one is still starting). The file is
 * created exclusively, so of two starts at once only one takes it.
 */
export async function claim(dataDir: string, root: string): Promise<Claim> {
  const file = lockFile(dataDir, root)
  mkdirSync(join(dataDir, 'web'), { recursive: true })
  const write = (url: string, flag: 'wx' | 'w') => writeFileSync(file, JSON.stringify({ pid: process.pid, url }), { flag })
  for (let attempt = 0; attempt < 2; attempt++) {
    try {
      write('', 'wx')
      let released = false
      return {
        kind: 'mine',
        record: url => write(url, 'w'),
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
    if (other !== null && other.pid !== process.pid && alive(other.pid) && (await holds(file, other.url))) return { kind: 'running', url: typeof other.url === 'string' ? other.url : '' }
    // Its process is gone, or its pid is another process's now: the lock is stale.
    rmSync(file, { force: true })
  }
  throw new Error(`could not take the lock ${file}`)
}

/** A project's address that outlives a start: its token, and the port it was last served on. */
export type Identity = { token: string; port: number | null; remember(port: number): void }

/** The project's {@link Identity}: read from its file, made (owner-only) when there is none it can use. */
export function identity(dataDir: string, root: string): Identity {
  const file = lockFile(dataDir, root).replace(/\.json$/, '.host.json')
  mkdirSync(join(dataDir, 'web'), { recursive: true })
  let kept: { token?: unknown; port?: unknown } = {}
  try {
    kept = JSON.parse(readFileSync(file, 'utf8')) as typeof kept
  } catch {
    // None yet, or unreadable: a new one is made below.
  }
  const valid = typeof kept.token === 'string' && /^[0-9a-f]{48}$/.test(kept.token)
  const token = valid ? (kept.token as string) : randomBytes(24).toString('hex')
  const port = valid && typeof kept.port === 'number' && Number.isInteger(kept.port) && kept.port > 0 && kept.port < 65_536 ? kept.port : null
  const save = (at: number | null) => {
    writeFileSync(file, JSON.stringify({ token, port: at }), { mode: 0o600 })
    // A file that was there keeps its mode on a write: set it, whoever made it.
    chmodSync(file, 0o600)
  }
  if (!valid) save(null)
  return { token, port, remember: at => save(at) }
}
