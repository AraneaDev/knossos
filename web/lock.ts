/**
 * One `knossos web` per project: the server that runs for a project holds a
 * lock file in the data directory, `web/<sha256 of the project's real
 * path>.json`, holding its pid and port. A second start finds it and points
 * at the running server instead of starting another. A lock that holds
 * nothing any more (its process gone, its pid now another process's after a
 * restart so that no pane server answers at its address, or a server that
 * stays silent through a bounded wait, as a suspended pane does) is taken
 * over by the next start. A server whose lock another took over sees it at
 * its next tick and steps down (see `holder`).
 *
 * Beside it, `<same>.host.json` keeps the project's token and the port it
 * was last served on, so the address stays the same from one start to the
 * next, and an installed app keeps working. The token is in no other file:
 * an address is made from the two. Both files, and `web/` itself, are the
 * owner's alone.
 */
import { createHash, randomBytes } from 'node:crypto'
import { chmodSync, existsSync, linkSync, mkdirSync, readFileSync, realpathSync, renameSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

/**
 * `mine`: `record` the port once the server listens, `release` when it stops, and `holder` to learn whether
 * another pane server took the project over: its port once the lock names another live process and a pane server
 * answers there, null otherwise. `replaced` is the port of the stale lock this claim removed, if it named one: where
 * pages of the server it replaced may still come looking.
 */
export type Claim = { kind: 'mine'; record(port: number): void; release(): void; holder(): Promise<number | null>; replaced: number | null } | { kind: 'running'; url: string }

/** A live process that holds the lock but whose server has not answered yet, and whether it is stopped (a pane suspended with Ctrl-Z). */
export type Silent = { pid: number; stopped: boolean }

/** The header a pane server sends with every answer, so that a probe can tell it from another program on the same port. Not a secret. */
export const PANE_HEADER = 'x-knossos-pane'

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

/**
 * How long a probe waits for the lock's server, try after try, before its silence makes the lock stale. A fresh
 * pane server on a loaded machine (8 cores under 12 to 16 busy processes) answered in 70 to 460 ms, half of them
 * within 140 ms: the first try stays short for the common case, the second covers that tail twice over, and the
 * third gives over six times it. About 4.3 s of silence is a process that does not serve (stopped, or stuck),
 * and taking it over is safe: a live server that was only slow sees the lock name the new one at its next tick
 * and steps down.
 */
export const PATIENCE_MS = [300, 1_000, 3_000] as const
/** How long a holder's server is given to show that it took the lock over: a step-down needs a server that answers. */
const HOLDER_PROBE_MS = 1_000
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

/** What every pane server's page has loaded since the first one: how a server from before {@link PANE_HEADER} is still known. */
const OLD_PAGE_MARK = '/static/app.mjs'
/** The most of an answer read to look for {@link OLD_PAGE_MARK}: the old page is well under 1 KB, and a program that streams forever is cut off here. */
const OLD_PAGE_MAX = 64 * 1024

/** Whether the first {@link OLD_PAGE_MAX} bytes of `body` hold the old page's mark; the rest is never read. */
async function oldPage(body: ReadableStream<Uint8Array> | null): Promise<boolean> {
  if (body === null) return false
  const reader = body.getReader()
  const decoder = new TextDecoder()
  let text = ''
  let size = 0
  try {
    while (size < OLD_PAGE_MAX) {
      const { done, value } = await reader.read()
      if (done) break
      size += value.byteLength
      text += decoder.decode(value, { stream: true })
      if (text.includes(OLD_PAGE_MARK)) return true
    }
    return false
  } finally {
    await reader.cancel().catch(() => undefined)
  }
}

/**
 * What answers at `url` (its token in it) within `ms`: a pane server (`pane`), something else or nothing
 * (`other`: a refusal, another status, an answer that is not the page), or nothing yet (`silent`: it accepted
 * and did not answer in time, as a busy server does, and a stopped or stuck one too). A pane server says so with
 * {@link PANE_HEADER}; one from before that header is known by its page, which a newer pane may meet on one
 * machine during an update.
 */
async function probe(url: string, ms: number): Promise<'pane' | 'other' | 'silent'> {
  try {
    const res = await fetch(url, { signal: AbortSignal.timeout(ms) })
    if (res.status !== 200) {
      await res.body?.cancel()
      return 'other'
    }
    if (res.headers.has(PANE_HEADER)) {
      await res.body?.cancel()
      return 'pane'
    }
    return (await oldPage(res.body)) ? 'pane' : 'other'
  } catch (error) {
    return (error as Error).name === 'TimeoutError' ? 'silent' : 'other'
  }
}

/** Whether process `pid` is stopped (`T` in /proc, a pane suspended with Ctrl-Z, or `t` under a tracer); false where there is no /proc. */
function stopped(pid: number): boolean {
  try {
    const stat = readFileSync(`/proc/${pid}/stat`, 'utf8')
    return /^[Tt]$/.test(stat.slice(stat.lastIndexOf(')') + 2).split(' ')[0] ?? '')
  } catch {
    return false
  }
}

/**
 * Whether the pane server at `url`, run by the live process `pid`, holds the lock: it answers as a pane server
 * within {@link PATIENCE_MS}, each try waiting longer. Silence through every try is a server that does not serve,
 * and its lock is stale; `silent` is told once, when the first try goes unanswered, so that the person learns
 * what the start waits for.
 *
 * A known limit: a server that is alive but answers only after more than about 4 s is taken over. That is safe:
 * it steps down at its next tick, once it sees the lock name the new server.
 */
async function serves(url: string, pid: number, silent?: (holder: Silent) => void): Promise<boolean> {
  for (const [i, ms] of PATIENCE_MS.entries()) {
    const answer = await probe(url, ms)
    if (answer !== 'silent') return answer === 'pane'
    if (i === 0) silent?.({ pid, stopped: stopped(pid) })
  }
  return false
}

/** The errors of a file system that has no hard links. */
const NO_HARD_LINKS = new Set(['EPERM', 'ENOTSUP', 'EOPNOTSUPP', 'EXDEV', 'ENOSYS'])

/** How far ahead of this process's clock a file's time may be and still be now: a file system's clock runs on finer ticks, or a little apart. */
const CLOCK_SLACK_MS = 1_000

/** How long a lock that does not read is taken to be still being written: the exclusive create of a file system without hard links makes it empty first. */
const WRITING_MS = 2_000

/** Whether the lock in `file` is young enough to be one another start is still writing: a time in the future is not young, it is wrong. */
function writing(file: string): boolean {
  try {
    const age = Date.now() - statSync(file).mtimeMs
    return age > -CLOCK_SLACK_MS && age < WRITING_MS
  } catch {
    return false
  }
}

/** How often a start or a release tries for the removal guard another process holds, and how long it waits between tries. */
const GUARD_TRIES = 50
const GUARD_WAIT_MS = 10
/** How old a removal guard is when the process that took it must have died holding it. */
const GUARD_STALE_MS = 5_000

/** Waits `ms` without giving up the thread: for the exit path, which cannot wait any other way. */
const pause = (ms: number): void => void Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms)

/**
 * Runs `act` while holding the removal guard of the lock `file` (`<file>.removing`, created exclusively, which
 * any file system can do): true when it ran, false when another process holds the guard. One left behind by a
 * process that died holding it is removed once it is old, for a later try to take.
 */
function guarded(file: string, act: () => void): boolean {
  const guard = `${file}.removing`
  try {
    writeFileSync(guard, String(process.pid), { flag: 'wx', mode: 0o600 })
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code !== 'EEXIST') throw error
    try {
      if (Date.now() - statSync(guard).mtimeMs > GUARD_STALE_MS) rmSync(guard, { force: true })
    } catch {
      // Gone already: the next try takes it.
    }
    return false
  }
  try {
    act()
  } finally {
    rmSync(guard, { force: true })
  }
  return true
}

const isPort = (port: unknown): port is number => typeof port === 'number' && Number.isInteger(port) && port > 0 && port < 65_536

/** Whether the lock in `file`, held by the live process `pid` it names, still holds: its server answers at `url`, or is still starting. */
async function holds(file: string, url: string | null, pid: number, silent?: (holder: Silent) => void): Promise<boolean> {
  if (url !== null) return serves(url, pid, silent)
  try {
    const age = Date.now() - statSync(file).mtimeMs
    return age > -CLOCK_SLACK_MS && age < STARTING_MS
  } catch {
    return false
  }
}

/**
 * The project at `root` for this process (`mine`: `record` its port once the
 * server listens, `release` when it stops), or the address of the server
 * that already runs for it, made with the project's `token` (empty while
 * that one is still starting). The file is created exclusively, so of two
 * starts at once only one takes it. `silent` is told when the lock's server does not answer at once.
 */
export async function claim(dataDir: string, root: string, token: string, silent?: (holder: Silent) => void): Promise<Claim> {
  const file = lockFile(dataDir, root)
  webDir(dataDir)
  // Whole at the moment it appears: a start that read it half written would take it for a stale lock and remove a live one.
  const staged = (port: number | null): string => {
    const part = `${file}.${process.pid}.part`
    writeFileSync(part, JSON.stringify({ pid: process.pid, port }), { mode: 0o600 })
    return part
  }
  const create = () => {
    const part = staged(null)
    try {
      // A link fails when the lock exists, as an exclusive create does.
      linkSync(part, file)
    } catch (error) {
      if (!NO_HARD_LINKS.has((error as NodeJS.ErrnoException).code ?? '')) throw error
      // A file system without hard links: the exclusive create of the lock itself, written in one call.
      writeFileSync(file, JSON.stringify({ pid: process.pid, port: null }), { flag: 'wx', mode: 0o600 })
    } finally {
      rmSync(part, { force: true })
    }
  }
  /**
   * Removes the lock if `doomed` says the one in the file is to go, and only that one: it is read again and
   * removed while this process holds the lock's removal guard, so no other start can replace it in between.
   * False when another process holds the guard, so that nothing was looked at.
   */
  const removeIf = (doomed: (seen: { pid: unknown; port: unknown } | null) => boolean): boolean =>
    guarded(file, () => {
      if (existsSync(file) && doomed(held(file))) rmSync(file, { force: true })
    })
  /** The port of the last stale lock this claim removed: see {@link Claim}. */
  let replaced: number | null = null
  /** Removes the stale lock that read as `seen`, and only that one. */
  const removeStale = (seen: { pid: unknown; port: unknown } | null, patient: boolean) =>
    removeIf(now => {
      const doomed = now === null ? seen === null && !(patient && writing(file)) : seen !== null && now.pid === seen.pid && now.port === seen.port
      if (doomed) replaced = now !== null && isPort(now.port) ? now.port : replaced
      return doomed
    })
  /** When this start first found the lock unreadable, by its own clock: how long it waits on one is bounded by that, not by the file. */
  let unreadSince: number | null = null
  /**
   * Writes the port into the lock, under the removal guard, and only while the lock still names this process:
   * a removal cannot fall between the check and the write. Throws when another start took the lock over first
   * (this one took too long to start), so that the caller stops its server rather than run a second one.
   */
  const record = (port: number): void => {
    for (let tries = 0; ; tries++) {
      let lost = false
      const ran = guarded(file, () => {
        if (held(file)?.pid !== process.pid) lost = true
        else renameSync(staged(port), file)
      })
      if (ran && lost) throw new Error(`another start took the lock ${file} over before this server listened`)
      if (ran) return
      if (tries >= GUARD_TRIES) throw new Error(`could not record the port in the lock ${file}`)
      pause(GUARD_WAIT_MS)
    }
  }
  const silenced = new Set<string>()
  for (let attempt = 0; attempt < GUARD_TRIES; attempt++) {
    try {
      create()
      let released = false
      return {
        kind: 'mine',
        record,
        replaced,
        // Only while it is still this process's: a start that took a lock this one no longer answered for keeps it.
        release: () => {
          if (released) return
          released = true
          // Said at exit, where nothing can wait: a guard held by another start is waited on briefly, synchronously.
          for (let tries = 0; tries < GUARD_TRIES && !removeIf(now => now?.pid === process.pid); tries++) pause(GUARD_WAIT_MS)
        },
        // Only positive evidence counts: the lock names another live process and a pane server answers at its port.
        // A lock that is missing or does not read (in the middle of a write), names a dead process, or names a live
        // one that serves nothing there (a pid used again) is no sign of another holder.
        holder: async () => {
          const now = held(file)
          if (now === null || now.pid === process.pid || !alive(now.pid) || !isPort(now.port)) return null
          return (await probe(addressOf(now.port, token), HOLDER_PROBE_MS)) === 'pane' ? now.port : null
        },
      }
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code !== 'EEXIST') throw error
    }
    const other = held(file)
    // One that does not read yet may be another start's, created but not yet written: it is waited on, not taken, for a while.
    if (other === null) unreadSince ??= performance.now()
    const patient = unreadSince === null || performance.now() - unreadSince < WRITING_MS + GUARD_WAIT_MS * 10
    if (other === null && patient && writing(file)) {
      await new Promise(done => setTimeout(done, GUARD_WAIT_MS))
      attempt--
      continue
    }
    const url = other !== null && isPort(other.port) ? addressOf(other.port, token) : null
    // A holder already found silent through every try is not waited on again in this claim (a removal that lost the guard comes back here).
    const key = other === null ? '' : `${String(other.pid)}:${String(other.port)}`
    if (other !== null && other.pid !== process.pid && alive(other.pid) && !silenced.has(key)) {
      if (await holds(file, url, other.pid as number, silent)) return { kind: 'running', url: url ?? '' }
      if (url !== null) silenced.add(key)
    }
    // Its process is gone, or its pid is another process's now: the lock is stale.
    if (!removeStale(other, patient)) await new Promise(done => setTimeout(done, GUARD_WAIT_MS))
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
