/**
 * The live watcher and the scans: the watcher child and its events, whose a
 * scanned change was (change attribution), the changes since the session
 * began as the scan ledger keeps them, the turn's brief, and the pane's
 * rescan.
 */
import type { LiveState, RescanState, WatchEvent } from '../../types'
import { activeBetween, FOLLOWED_SCAN_MS, lookbackMs, scanWindow } from '../lib/activity'
import type { JobState } from '../lib/band'
import { parseRescan, parseSessionLedger, parseTurnBrief, rescanReason } from '../lib/envelopes'
import type { TurnBrief } from '../lib/envelopes'
import { ledgerFlashKeys } from '../lib/flash'
import { changesInput } from '../lib/changes'
import { accumulate } from '../lib/session-changes'
import { cdFor } from '../lib/test-commands'
import { isWatching, LIVE_OFF, liveAfter, liveChanged, snapshotOf, watchLines } from '../lib/live'
import { SingleFlight } from '../lib/scheduler'
import { deliverNote, turnEndNote } from './agent'
import { refreshDashboard } from './loaders'
import { readHeadRev } from './session-baseline'
import { disable, keepScan, light, shownChanges, sleep, wrapper } from './port'
import type { Port } from './port'
import { startMotion } from './motion'
import { exclusively, mod } from './state'

/** What `session-changes` is asked: the changes since the snapshot the session began at, or (`null`, the web host) what git says is not committed. */
export const ledgerArgs = (since: string | null): string[] => [since === null ? '--working-tree' : `--since=${since}`]

/** What a turn brief is asked: the turn's files, the snapshot it began at, whether the watcher's scan is reused, and whether policies count. */
export const briefArgs = (files: string[], base: string | null, watching: boolean, enforce: boolean): string[] => [
  ...files.map(f => `--files=${f}`),
  ...(base === null ? [] : [`--since=${base}`]),
  ...(watching ? ['--reuse-scan'] : []),
  ...(enforce ? [] : ['--no-policies']),
]

/** What the live watcher is started with: its poll interval. */
export const watchArgs = (pollMs: number): string[] => [`--poll-ms=${pollMs}`]

/** The wrapper bounds a turn brief at 60 s, plus room for it to exit on its own. */
const BRIEF_TIMEOUT_MS = 70_000

/**
 * The wrapper bounds a rescan at 5 minutes, the bound of a watch scan, plus room
 * for it to exit on its own: the rescan after an allow is a project's first full
 * scan, which can take far longer than an incremental one.
 */
const RESCAN_TIMEOUT_MS = 310_000

/** The watcher's debounce (its default): how long it waits after a change before it scans. */
const WATCH_DEBOUNCE_MS = 300

/** The longest a turn's brief waits for the watcher to take the turn's edits in before it scans them itself. */
const WATCH_SETTLE_MAX_MS = 20_000

/** How often a watcher that ended on its own is started again, and the pause before each try (times the try's number). */
const WATCH_RESTARTS = 3
const WATCH_RESTART_MS = 30_000

/** The wrapper bounds session-changes at 15 s. */
const LEDGER_TIMEOUT_MS = 20_000

/**
 * Asks for the changes since the session began again (from the scan ledger),
 * coalesced with a read in flight. In Claude Code only while a watcher keeps
 * that ledger: without one the Changes tab shows the turn briefs. On the web
 * the read is git's, which needs no watcher. Called from the watcher's loop,
 * after a turn's brief and from {@link followHead}, never inside a render.
 */
function requestLedger(io: Port): void {
  if (mod.session.disabled || (mod.watcher.stream === null && mod.session.host !== 'web')) return
  void (mod.loads.ledgerFlight ??= new SingleFlight(() => loadLedger(io))).request().catch(() => undefined)
}

/**
 * One read of the changes since the session began; silence or an error keeps
 * the last one. On the web host there is no session: the read is what is not
 * committed yet, and nothing can begin again under it. There `no-git` is
 * kept, and so is an error with nothing read before it, so the Changes tab
 * says why it lists nothing.
 */
async function loadLedger(io: Port): Promise<void> {
  const web = mod.session.host === 'web'
  const since = web ? null : await io.state.sessionStart.read()
  if ((!web && since === null) || mod.session.disabled) return
  const parsed = parseSessionLedger(await wrapper(io, 'session-changes', ledgerArgs(web ? null : since), LEDGER_TIMEOUT_MS))
  if (parsed?.status === 'no-binary') return disable(io)
  if (web && (parsed?.status === 'no-git' || (parsed?.status === 'error' && (await io.state.sessionLedger.read())?.status !== 'ok'))) {
    await io.state.sessionLedger.update(() => parsed)
    return
  }
  if (parsed?.status !== 'ok') return
  // A session that began again (a /clear) while this read ran reads since its own start.
  if (!web && (await io.state.sessionStart.read()) !== since) return
  await light(io, ledgerFlashKeys(await io.state.sessionLedger.read(), parsed), await io.clock.now())
  await io.state.sessionLedger.update(() => parsed)
  await settleUntested(io)
}

/**
 * Reads where the checkout stands and, when its commit is not the one the
 * Changes tab counts from, counts from it: the commit is kept as the one the
 * session began at, the open diff (drawn against the old one) is dropped and
 * the changes are read again. True when the commit moved. With no live
 * watcher to say when a file changed, the changes are read again on every
 * call, moved or not. Only the web host calls it; Claude Code counts from
 * where its session began.
 */
export async function followHead(io: Port): Promise<boolean> {
  const head = await readHeadRev(io)
  const known = await io.state.sessionRev.read()
  const moved = head?.status === 'ok' && !(known?.status === 'ok' && known.rev === head.rev)
  if (moved) {
    await io.state.sessionRev.update(() => head)
    await io.state.fileDiff.update(() => null)
  }
  if (moved || mod.watcher.stream === null || !isWatching(await io.state.live.read())) requestLedger(io)
  return moved
}

/**
 * The Changes list narrowed to the files no test reaches goes back to every
 * file once none is left, so a later file none reaches does not narrow it
 * again unasked.
 */
async function settleUntested(io: Port): Promise<void> {
  if (!(await io.state.untestedOnly.read())) return
  const d = await io.state.dashboard.read()
  const root = d?.status === 'ok' ? (d.project_root ?? null) : null
  if (changesInput(await shownChanges(io, root), root).untested === 0) await io.state.untestedOnly.update(() => false)
}

/** Stores what a turn brief says, by its status; resolves true when it is a fresh `ok`. */
async function settleBrief(io: Port, parsed: TurnBrief | null, now: number): Promise<boolean> {
  if (parsed?.status === 'no-binary') {
    await io.state.job.update((): JobState => ({ phase: 'idle', lastAttemptAt: now }))
    await disable(io)
    return false
  }
  if (parsed === null || parsed.status === 'error') {
    await io.state.job.update((): JobState => ({ phase: 'failed', lastAttemptAt: now }))
    return false
  }
  if (parsed.status === 'scan-failed') {
    // The first failure shows its reason; later ones keep the last good figures with their age.
    const previous = await io.state.brief.read()
    if (previous?.status !== 'ok') await io.state.brief.update(() => parsed)
    await io.state.job.update((): JobState => ({ phase: 'failed', lastAttemptAt: now }))
    return false
  }
  await io.state.brief.update(() => parsed)
  if (parsed.status === 'ok') {
    await io.state.changes.update(c => accumulate(c, parsed))
    await settleUntested(io)
  }
  await io.state.job.update((): JobState => ({ phase: 'idle', lastAttemptAt: now }))
  return parsed.status === 'ok'
}

/** One run of the scan job: the turn brief for every path edited since the last run. */
async function scan(io: Port): Promise<void> {
  // Drained at the start of each run, so a coalesced rerun scans every path reported since.
  const files = [...mod.turn.edited]
  mod.turn.edited = new Set()
  // The turn's start goes with its files: a later turn's first edit takes the snapshot of its own.
  const base = mod.turn.base
  mod.turn.base = null
  let parsed: TurnBrief | null = null
  try {
    await io.state.job.update((j): JobState => ({ ...j, phase: 'scanning' }))
    // With a watcher keeping the graph current, the turn's edits are (or are about to be) scanned already:
    // the brief waits for that and reads them from the ledger instead of scanning again.
    // Only a watcher that is running now: the header's state alone may outlive it.
    const watching = mod.watcher.stream !== null && isWatching(await io.state.live.read())
    if (watching) await settleWatcher(io)
    // What the turn's shell commands wrote, as the watcher took it in, counts as the edits do.
    for (const file of await shellFiles(io)) if (!files.includes(file)) files.push(file)
    const args = briefArgs(files, base, watching, mod.session.enforce)
    parsed = parseTurnBrief(await exclusively(() => wrapper(io, 'turn-brief', args, BRIEF_TIMEOUT_MS)))
  } finally {
    // A brief that never ran its scan (silence, an error, a refused root) saw none of these edits:
    // the next one must report them, since only reported files count toward the policy verdict.
    if (parsed === null || parsed.status !== 'ok') {
      for (const file of files) mod.turn.edited.add(file)
      mod.turn.base ??= base
    }
  }
  if (parsed?.status === 'ok') mod.session.snapshot = parsed.snapshot_id ?? mod.session.snapshot
  // A brief that scanned took in the turn's own edits: its scan is the session's.
  if (parsed?.status === 'ok' && parsed.scanned === true && parsed.snapshot_id !== null) await keepScan(io, parsed.snapshot_id)
  if (!(await settleBrief(io, parsed, await io.clock.now())) || parsed === null) return
  const note = turnEndNote(parsed, cdFor(parsed.project_root, await io.state.sessionRoot.read()))
  if (note !== null) await deliverNote(io, note)
  // The brief's own scan is in the ledger too.
  requestLedger(io)
  await refreshDashboard(io)
}

/** The scan job's body: a run that throws leaves the job marked failed instead of rejecting. */
export async function scanSafely(io: Port): Promise<void> {
  try {
    await scan(io)
  } catch {
    const now = await io.clock.now().catch(() => null)
    await io.state.job.update((j): JobState => ({ phase: 'failed', lastAttemptAt: now ?? j.lastAttemptAt })).catch(
      () => undefined,
    )
  }
}

/**
 * Whether the scan that produced `snapshot`, begun at `started` and landed
 * at `ended`, took in changes made while the session's tools ran; keeps it
 * as the session's when it did. Remembered as the last scan either way.
 */
async function attributeScan(io: Port, snapshot: string, started: number, ended: number): Promise<void> {
  const { from, to } = scanWindow(started, ended, lookbackMs(mod.watch.pollMs, WATCH_DEBOUNCE_MS), mod.scans.last)
  mod.scans.last = { start: started, end: ended }
  if (activeBetween(mod.activity.calls, from, to)) await keepScan(io, snapshot)
  // A shell command's changes: the files this scan changed are the turn's, as an edit's are (see mod.activity.shell).
  if (activeBetween(mod.activity.shell, from, to) && !fenced(from, to)) {
    mod.activity.shellScans.add(snapshot)
    shellChanged()
  }
}

/**
 * Whether a scan's window, `from` to `to`, reaches over a shell command that
 * moved HEAD by more than a commit: what it took in may be the checkout's, so
 * none of it is a shell command's, whatever else ran in the window.
 */
function fenced(from: number, to: number): boolean {
  return mod.activity.shellFences.some(f => f.start <= to && f.end >= from)
}

/**
 * A change a shell command made marks the turn for its brief, from the graph
 * as it stood before (the turn's start, when no edit set one). Marked as soon
 * as the watcher notices the change, not only once its scan lands, so a turn
 * that ends while the scan runs still briefs it; one noticed after the turn
 * ended is the next turn's.
 */
function shellChanged(): void {
  mod.turn.dirty = true
  mod.turn.base ??= mod.session.snapshot
}

/**
 * The files the scans that took in a shell command's changes changed, as the
 * scan ledger lists them (read afresh: the watcher has just scanned); none
 * when the ledger cannot say. The scans are drained: each is reported once.
 */
async function shellFiles(io: Port): Promise<string[]> {
  if (mod.activity.shellScans.size === 0) return []
  const scans = mod.activity.shellScans
  mod.activity.shellScans = new Set()
  await loadLedger(io)
  const ledger = await io.state.sessionLedger.read()
  if (ledger?.status !== 'ok') return []
  return Object.entries(ledger.files)
    .filter(([, file]) => (file.scans ?? []).some(s => scans.has(s)))
    .map(([path]) => path)
}

/**
 * Starts the pane's rescan once the press has resolved. A press while one is
 * queued or running adds nothing: the scan it asks for is already coming.
 */
export function requestRescan(io: Port): void {
  const flight = (mod.scans.rescanFlight ??= new SingleFlight(() => rescanSafely(io)))
  if (mod.scans.rescanQueued || flight.isRunning) return
  mod.scans.rescanQueued = true
  io.clock.after(0, () => {
    mod.scans.rescanQueued = false
    void flight.request()
  })
}

/**
 * The rescan of `root` right after the person allowed it: the first scan, which
 * creates the project there. Shown like any rescan; never throws.
 */
export async function rescanRoot(io: Port, root: string): Promise<void> {
  await rescanSafely(io, root)
}

/** One rescan; a run that throws leaves the header saying it failed rather than scanning forever. */
async function rescanSafely(io: Port, root?: string): Promise<void> {
  try {
    await runRescan(io, root)
  } catch {
    await io.state.rescan.update((): RescanState => ({ phase: 'failed', reason: null })).catch(() => undefined)
  }
}

/**
 * Rescans the project through the wrapper's `scan` (incrementally, or the first
 * full scan when `root` was only just allowed), then
 * reloads the dashboard so the pane draws the new snapshot. The header shows
 * `scanning…` meanwhile and the reason when it does not land.
 */
async function runRescan(io: Port, root?: string): Promise<void> {
  if (mod.session.disabled) return
  await io.state.rescan.update((): RescanState => ({ phase: 'scanning', reason: null }))
  await startMotion(io, null)
  const started = await io.clock.now()
  const parsed = parseRescan(await exclusively(() => wrapper(io, 'scan', [], RESCAN_TIMEOUT_MS, root)))
  if (parsed?.status === 'ok' && typeof parsed.snapshot_id === 'string') await attributeScan(io, parsed.snapshot_id, started, await io.clock.now())
  if (parsed?.status === 'no-binary') {
    await io.state.rescan.update((): RescanState => ({ phase: 'idle', reason: null }))
    await disable(io)
    return
  }
  if (parsed?.status !== 'ok') {
    // A refused root is kept, so the pane can offer to allow it.
    const refused = parsed?.status === 'not-allowed' ? (parsed.refused_root ?? null) : null
    await io.state.rescan.update((): RescanState => ({ phase: 'failed', reason: rescanReason(parsed), refusedRoot: refused }))
    return
  }
  await refreshDashboard(io)
  await io.state.rescan.update((): RescanState => ({ phase: 'idle', reason: null }))
}

/**
 * Starts the live watcher when it should run and does not: switched on, not
 * refused, past any pause before a restart, and with a dashboard of an
 * allowed, scanned project to watch. Called at start-up and on every age
 * tick, so a watcher that ended is started again from here.
 */
export async function ensureWatcher(io: Port): Promise<void> {
  if (!mod.watch.on || mod.session.disabled || mod.watcher.stream !== null || mod.watch.starting || mod.watch.refused) return
  if ((await io.clock.now()) < mod.watch.retryAt) return
  const d = await io.state.dashboard.read()
  if (d?.status !== 'ok' || d.project_root === null) return
  mod.watch.starting = true
  const gen = ++mod.generations.watch
  // The loop runs for the session's life: never inside a hook's dispatch.
  io.clock.after(0, () => {
    mod.watch.starting = false
    void runWatcher(io, gen, d.project_root!).catch(() => undefined)
  })
}

/**
 * One watcher child, `knossos watch --shared` through the wrapper, read to
 * its end. Each event moves the header's phase; one that names a newer
 * snapshot reloads the dashboard (one load at a time). A watcher that ends
 * on its own after coming up is started again a few times; one that never
 * said a word is not offered here and is not asked again.
 */
async function runWatcher(io: Port, gen: number, root: string): Promise<void> {
  if (gen !== mod.generations.watch) return
  let stream: AsyncGenerator<{ stream: string; text: string }, unknown>
  try {
    stream = io.process.spawn({ argv: ['sh', `${io.plugin.root}/hooks/scripts/knossos-run.sh`, 'watch', root, ...watchArgs(mod.watch.pollMs)] })
  } catch {
    mod.watch.refused = true
    return
  }
  mod.watcher.stream = stream
  await io.state.live.update((): LiveState => ({ phase: 'starting' }))
  let rest = ''
  let heard = false
  try {
    for await (const piece of stream) {
      if (gen !== mod.generations.watch) break
      if (piece.stream !== 'stdout') continue
      const got = watchLines(rest, piece.text)
      rest = got.rest
      for (const event of got.events) {
        heard = true
        await onWatchEvent(io, event)
      }
      // Its last word: the child ends on its own after it.
      if (got.events.some(e => e.event === 'refused' || e.event === 'stopped' || e.status === 'no-binary' || (e.status === 'refused' && e.by === 'wrapper'))) break
    }
  } catch {
    // A child that could not start, or a stream torn down under the loop: what follows decides.
  } finally {
    if (mod.watcher.stream === stream) {
      mod.watcher.stream = null
      mod.watcher.pid = null
    }
  }
  if (gen !== mod.generations.watch) {
    // Stopped from outside: the header says nothing is live unless a newer watcher took over.
    if (mod.watcher.stream === null && !mod.watch.starting) await io.state.live.update(() => LIVE_OFF)
    return
  }
  mod.watcher.pending = false
  await io.state.live.update(() => LIVE_OFF)
  if (!heard) mod.watch.refused = true
  if (mod.watch.refused || mod.session.disabled) return
  // Started again by the age tick once the pause is over; past the last try, not at all.
  mod.watch.failures++
  mod.watch.retryAt = mod.watch.failures > WATCH_RESTARTS ? Number.POSITIVE_INFINITY : (await io.clock.now()) + WATCH_RESTART_MS * mod.watch.failures
}

/** What one watcher event changes: the phase, whether changes wait, and the dashboard when the snapshot moved. */
async function onWatchEvent(io: Port, event: WatchEvent): Promise<void> {
  if (event.status === 'no-binary') return disable(io)
  // The wrapper refused the watcher's own call: it never runs, so it is not asked again, and the log says why.
  if (event.status === 'refused' && event.by === 'wrapper') {
    mod.watch.refused = true
    await io.ui.log(`knossos: the wrapper refused watch (${event.reason ?? 'no reason given'}); no live watcher this session`, { to: 'debug' })
    return
  }
  if (event.event === 'refused') mod.watch.refused = true
  if (event.event === 'ready' || event.event === 'following') mod.watch.failures = 0
  if (event.event === 'changes') mod.watcher.pending = true
  // Noticed while a shell command ran, or a moment after: the command's.
  if (event.event === 'changes') {
    const now = await io.clock.now()
    const from = now - lookbackMs(mod.watch.pollMs, WATCH_DEBOUNCE_MS)
    if (activeBetween(mod.activity.shell, from, now) && !fenced(from, now)) shellChanged()
  }
  if (event.event === 'scan_completed' || event.event === 'absorbed' || event.event === 'stopped') mod.watcher.pending = false
  if (typeof event.pid === 'number' && Number.isInteger(event.pid) && event.pid > 1) mod.watcher.pid = event.pid
  const before = await io.state.live.read()
  const after = liveAfter(event, before)
  if (liveChanged(before, after)) await io.state.live.update(() => after)
  // A scan under way turns the spinner in the pill until it is done.
  if (after.phase === 'scanning' && before.phase !== 'scanning') await startMotion(io, null)
  const snapshot = snapshotOf(event)
  const moved = snapshot !== null && snapshot !== mod.session.snapshot
  await attributeEvent(io, event, snapshot, moved)
  if (moved) {
    mod.session.snapshot = snapshot
    // Coalesced with any load in flight: the last to land is the newest.
    void refreshDashboard(io).catch(() => undefined)
  }
  // What changed since the session began: read once the watcher is up, and again after each scan it saw.
  if (moved || event.event === 'ready' || event.event === 'leading' || event.event === 'following') requestLedger(io)
}

/**
 * Whose changes a watcher event's scan took in. `scan_started` (or, while
 * following, `leader_scanning`) marks when a scan began; the watcher's own
 * `scan_completed` lands it, and a `snapshot` or `absorbed` that moves the
 * graph lands another writer's, begun at the mark or, unseen, a scan's time
 * before the poll that saw it.
 */
async function attributeEvent(io: Port, event: WatchEvent, snapshot: string | null, moved: boolean): Promise<void> {
  const now = await io.clock.now()
  if (event.event === 'scan_started' || event.event === 'leader_scanning') {
    mod.scans.startedAt = now
    return
  }
  const landed = event.event === 'scan_completed' || (moved && (event.event === 'snapshot' || event.event === 'absorbed'))
  if (!landed || snapshot === null) return
  const started = mod.scans.startedAt ?? (event.event === 'scan_completed' ? now : now - mod.watch.pollMs - FOLLOWED_SCAN_MS)
  mod.scans.startedAt = null
  await attributeScan(io, snapshot, started, now)
}

/**
 * Waits, at most {@link WATCH_SETTLE_MAX_MS}, for the watcher to take the
 * turn's last edits in: until it has had a poll and its debounce to notice
 * them, and is neither scanning nor holding changes. The brief then finds
 * its files already in the graph and scans nothing itself.
 */
async function settleWatcher(io: Port): Promise<void> {
  const quiet = mod.watch.pollMs + WATCH_DEBOUNCE_MS + 250
  const until = (await io.clock.now()) + WATCH_SETTLE_MAX_MS
  for (;;) {
    const now = await io.clock.now()
    const busy = (await io.state.live.read()).phase === 'scanning' || mod.watcher.pending
    if (now >= until || (!busy && now - mod.turn.lastEditAt >= quiet)) return
    await sleep(io, 100)
  }
}
