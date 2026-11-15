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
import { createHash, createHmac, randomBytes, timingSafeEqual } from 'node:crypto'
import { chmodSync, existsSync, linkSync, mkdirSync, readFileSync, realpathSync, renameSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

/**
 * `mine`: `record` the port once the server listens, `release` when it stops, and `holder` to learn whether
 * another pane server took the project over: its port once the lock names another live process and a pane server
 * answers there, null otherwise. `replaced` is the holder of the stale lock this claim removed, when that holder was
 * alive and stayed silent (a pane suspended, or stuck): its pages are the ones that may still come looking on its
 * port, and nothing else will answer them there. Null for any other stale lock, and for a port below 1024.
 */
export type Claim = { kind: 'mine'; record(port: number): void; release(): void; holder(): Promise<number | null>; replaced: Replaced | null } | { kind: 'running'; url: string; proven: true }
  | { kind: 'running'; url: string; proven: false; holder: Replaced }

/** A holder replaced while it was alive and silent: its process and the port it served on. */
export type Replaced = { pid: number; port: number }

/** Whether a port may be pointed from at all: never a privileged one, which no pane server is given by default. */
export const pointable = (port: number): boolean => port >= 1024

/** A lock's holder as a key. */
const keyOf = (held: { pid: unknown; port: unknown }): string => `${String(held.pid)}:${String(held.port)}`

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
export function alive(pid: unknown): boolean {
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

/** The header a probe sends its challenge in, and the one a pane server answers it in. Neither holds a secret. */
export const CHALLENGE_HEADER = 'x-knossos-challenge'
export const PROOF_HEADER = 'x-knossos-proof'

/**
 * What a pane server answers a probe's `challenge` with, on `port` (the port the probe reached): an HMAC keyed with
 * the project's token. Only a process that knows the token can make it, and the token never travels: it is kept in
 * the data directory, readable by the person alone. The challenge is fresh for each probe, so an answer cannot be
 * replayed, and the port is in it, so a listener elsewhere cannot pass a probe on to the real server and hand its
 * answer back. `kind` tells the pane's own port from the one it gives directions on.
 */
export function proofOf(token: string, challenge: string, port: number, kind: 'pane' | 'directions' = 'pane'): string {
  return createHmac('sha256', token).update(`knossos-${kind}:${challenge}:${port}`).digest('hex')
}

/** Whether `given` is the proof of `challenge` on `port`, compared in constant time. */
export function proves(given: string | null, token: string, challenge: string, port: number, kind: 'pane' | 'directions' = 'pane'): boolean {
  const want = proofOf(token, challenge, port, kind)
  return given !== null && given.length === want.length && timingSafeEqual(Buffer.from(given), Buffer.from(want))
}

/** What a server from 0.49.0 or before answers a request without the token: how one is still known, as it sends no marker. */
const OLD_REFUSAL = 'This address is out of date or incomplete.'
/** The most of an answer read to look for {@link OLD_REFUSAL}: the refusal is one line, and a program that streams forever is cut off here. */
const OLD_REFUSAL_MAX = 64 * 1024

/** Whether the first {@link OLD_REFUSAL_MAX} bytes of `body` hold `mark`; the rest is never read. */
async function begins(body: ReadableStream<Uint8Array> | null, mark: string): Promise<boolean> {
  if (body === null) return false
  const reader = body.getReader()
  const decoder = new TextDecoder()
  let text = ''
  let size = 0
  try {
    while (size < OLD_REFUSAL_MAX) {
      const { done, value } = await reader.read()
      if (done) break
      size += value.byteLength
      text += decoder.decode(value, { stream: true })
      if (text.includes(mark)) return true
    }
    return false
  } finally {
    await reader.cancel().catch(() => undefined)
  }
}

/**
 * Whether the socket listening on loopback `port` belongs to this user, from /proc/net/tcp: true or false, null where
 * the system does not say. Another user cannot make a socket of theirs pass for one of this user's.
 */
export function ownListener(port: number): boolean | null {
  let table: string
  try {
    table = readFileSync('/proc/net/tcp', 'utf8')
  } catch {
    return null
  }
  const hex = port.toString(16).toUpperCase().padStart(4, '0')
  const uid = process.getuid?.()
  if (uid === undefined) return null
  // Each row: sl, local address:port, remote, state (0A is LISTEN), queues, timer, retransmits, then the owner's uid.
  for (const row of table.split('\n').slice(1)) {
    const fields = row.trim().split(/\s+/)
    const local = fields[1]
    if (local === undefined || fields[3] !== '0A') continue
    if ((local === `0100007F:${hex}` || local === `00000000:${hex}`) && Number(fields[7]) === uid) return true
  }
  return false
}

/**
 * What answers on loopback `port` within `ms`, asked without the token, which no probe ever carries: what listens
 * there may be anyone's. `pane` is a pane server of this project, proven by its answer to a fresh challenge (see
 * {@link proofOf}). `old` is a pane server from before that proof (one from 0.49.1 sends {@link PANE_HEADER}, one
 * from before that only its refusal), which a newer pane may meet during an update: counted as `pane` when the
 * socket is shown to be this user's, and left `old`, unproven, where it cannot be. `other` is anything else, a
 * refusal of the connection included, and `silent` an answer that did not come in time (a busy server, and a
 * stopped or stuck one too).
 */
export async function probe(port: number, token: string, ms: number): Promise<'pane' | 'old' | 'other' | 'silent'> {
  const challenge = randomBytes(16).toString('hex')
  try {
    const res = await fetch(`http://127.0.0.1:${port}/`, { headers: { [CHALLENGE_HEADER]: challenge }, signal: AbortSignal.timeout(ms) })
    const given = res.headers.get(PROOF_HEADER)
    const proven = proves(given, token, challenge, port)
    // A proof that does not hold is a pane server of another project (its own token), not an older one of this.
    const marked = res.headers.has(PANE_HEADER) && given === null
    const old = !proven && given === null && (marked || (res.status === 403 && (await begins(res.body, OLD_REFUSAL))))
    await res.body?.cancel().catch(() => undefined)
    if (proven) return 'pane'
    if (!old) return 'other'
    return ownListener(port) === true ? 'pane' : 'old'
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
 * Whether the pane server on `port`, run by the live process `pid`, holds the lock: `pane` when it answers as a pane
 * server within {@link PATIENCE_MS}, each try waiting longer, `old` when it answers as one from before the proof that
 * cannot be shown to be this user's (see {@link probe}), `other` for any other answer or none, and `silent` when it
 * stayed silent through every try, a server that does not serve (suspended, or stuck): the lock is stale then too. `silent` is told once, when the first try goes unanswered, so that the person learns what the start
 * waits for.
 *
 * A known limit: a server that is alive but answers only after more than about 4 s is taken over. That is safe:
 * it steps down at its next tick, once it sees the lock name the new server.
 */
async function serves(port: number, token: string, pid: number, silent?: (holder: Silent) => void): Promise<'pane' | 'old' | 'other' | 'silent'> {
  for (const [i, ms] of PATIENCE_MS.entries()) {
    const answer = await probe(port, token, ms)
    if (answer !== 'silent') return answer
    if (i === 0) silent?.({ pid, stopped: stopped(pid) })
  }
  return 'silent'
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

/** Whether the lock in `file`, held by the live process `pid` it names, still holds (`pane`): its server answers on `port`, or is still starting. */
async function holds(file: string, port: number | null, token: string, pid: number, silent?: (holder: Silent) => void): Promise<'pane' | 'old' | 'other' | 'silent'> {
  if (port !== null) return serves(port, token, pid, silent)
  try {
    const age = Date.now() - statSync(file).mtimeMs
    return age > -CLOCK_SLACK_MS && age < STARTING_MS ? 'pane' : 'other'
  } catch {
    return 'other'
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
  /** The holder of the last stale lock this claim removed, when it was a live one that stayed silent: see {@link Claim}. */
  let replaced: Replaced | null = null
  /** The live holders found silent through every try, by `pid:port`: the only ones whose port may be pointed from. */
  const silentHolders = new Set<string>()
  /** Removes the stale lock that read as `seen`, and only that one. */
  const removeStale = (seen: { pid: unknown; port: unknown } | null, patient: boolean) =>
    removeIf(now => {
      const doomed = now === null ? seen === null && !(patient && writing(file)) : seen !== null && now.pid === seen.pid && now.port === seen.port
      if (doomed && now !== null && silentHolders.has(keyOf(now)) && alive(now.pid) && isPort(now.port) && pointable(now.port)) replaced = { pid: now.pid as number, port: now.port }
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
          return (await probe(now.port, token, HOLDER_PROBE_MS)) === 'pane' ? now.port : null
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
    const port = other !== null && isPort(other.port) ? other.port : null
    const url = port === null ? '' : addressOf(port, token)
    // A holder already found silent through every try is not waited on again in this claim (a removal that lost the guard comes back here).
    const key = other === null ? '' : keyOf(other)
    if (other !== null && other.pid !== process.pid && alive(other.pid) && !silenced.has(key)) {
      const verdict = await holds(file, port, token, other.pid as number, silent)
      // Proven, or still starting: the token may go there. An older server that cannot be shown to be this user's is named, not trusted.
      if (verdict === 'pane') return { kind: 'running', url, proven: true }
      if (verdict === 'old') return { kind: 'running', url, proven: false, holder: { pid: other.pid as number, port: port! } }
      if (port !== null) silenced.add(key)
      if (verdict === 'silent') silentHolders.add(key)
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
