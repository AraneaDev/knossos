/**
 * The mod's own state for one load, and what it is reset to: the module
 * variables every other module reads and writes (`mod`), the constants more
 * than one of them needs, and the few helpers that touch only that state.
 * Nothing here reaches the engine.
 */
import type { EngineInterface, PluginOptions, Timer } from 'claude-code'

import type { Dashboard, SessionChanges } from '../../types'
import { noActivity } from '../lib/activity'
import { betweenTypes, cycleSearchStopped } from '../lib/envelopes'
import type { Activity } from '../lib/activity'
import { watchPollMsOf } from '../lib/live'
import { SingleFlight } from '../lib/scheduler'
import type { Row } from '../lib/types'
import type { Dig } from './dig'

/** The pane's id, as the engine opens and draws it. */
export const PANE = 'knossos'

/**
 * The key a field (the finder's, a note's, the hubs filter's) is drawn under,
 * and the one `$.ui.focus` names to move into it. Never the field's own id:
 * the Button that opens a field shares that id, and Claude Code resolves a
 * focus call by key against the drawing it holds when the call arrives, the
 * first element under the key in document order. That can be the Button,
 * still drawn: the ring lands on it, the field never gets the keys, and once
 * the next drawing drops the Button the keyboard goes back to the prompt.
 */
export const fieldKey = (id: string): string => `field:${id}`

/**
 * Scrolls the element `key` into the pane's window, then focuses it: a field
 * at the end of a long detail is otherwise below the window, and what is
 * typed goes elsewhere. A scroll the engine refuses still focuses.
 */
export async function revealThenFocus(io: { ui: Pick<EngineInterface['ui'], 'scroll' | 'focus'> }, key: string): Promise<void> {
  await io.ui.scroll({ in: PANE, to: { key } }).catch(() => undefined)
  await io.ui.focus({ requestId: PANE, key })
}

/** The fan-in threshold when the options give none the dashboard command accepts. */
const DEFAULT_THRESHOLD = 20
/** The largest threshold the dashboard command accepts. */
const MAX_THRESHOLD = 100_000

/** The fan-in threshold from the options: an integer the dashboard command accepts (1 to 100000), else the default. */
function thresholdOf(value: unknown): number {
  const n = Number(value)
  return Number.isInteger(n) && n >= 1 && n <= MAX_THRESHOLD ? n : DEFAULT_THRESHOLD
}

/** Where the pane runs: inside Claude Code, or in a browser (where nothing asks Claude to do anything). */
export type Host = 'claude' | 'web'

/** The session as the mod knows it (`session.ts`, `loaders.ts`): where it runs, what the options set, what was asked of the checkout. */
export function initialSession(options: PluginOptions, host: Host) {
  return {
    threshold: thresholdOf(options.fanInThreshold),
    enforce: options.enforcePolicies !== false,
    host,
    /**
     * Set when the wrapper answers `no-binary`: nothing to run, so the mod stays
     * out of the way. Silence (a timeout, a crash) never sets it; that is asked
     * again at the next dirty turn.
     */
    disabled: false,
    /**
     * The session id whose baseline is in state, once it was read back or
     * recorded (see `lib/baseline.ts`); null until then, and after a session
     * ends. The ending session's id, while the process may still answer it.
     */
    baselineOf: null as string | null,
    ended: null as string | null,
    /** Whether this session's commit was asked for: a silent answer is not asked again. */
    headAsked: false,
    /** Whether where the checkout stands was asked for this load: start-up asks only when the baseline's own read did not. */
    gitAsked: false,
    /** The newest snapshot the mod knows of: from the dashboard, a turn brief or the watcher. */
    snapshot: null as string | null,
  }
}

/** The `/knossos` command's registration (`session.ts`). */
export function initialRegistration() {
  return {
    /** Whether `/knossos` is registered; until it is, retries run on a timer and at each turn's end. */
    registered: false,
    /** The pending registration retry, if any. */
    timer: null as Timer | null,
    /** Whether a refused registration was logged this load: once is enough. */
    failureLogged: false,
  }
}

/** The turn under way: what it edited and ran, and the snapshot it started from (`agent.ts`, `watcher.ts`). */
export function initialTurn() {
  return {
    /** Set by an edit inside the project, cleared when a turn's scan is scheduled. */
    dirty: false,
    /** Paths edited since the last scan began: project-relative, or absolute before the first dashboard. */
    edited: new Set<string>(),
    /**
     * The snapshot the graph was at when the turn's first edit landed: the turn
     * brief's `--since`, so it still reports the turn when the watcher (or
     * anyone) scanned its edits first. Null between turns.
     */
    base: null as string | null,
    /** When the last edit was seen (mod clock, ms): the watcher needs a moment to notice it. */
    lastEditAt: 0,
    /** Bash commands run since the turn's last edit; at the turn's end they become `ran`, what its scan's note checks. */
    ranCommands: [] as string[],
    ran: [] as string[],
  }
}

/** When the session's tool calls ran (`register.tsx`), and whose a scanned change was (`watcher.ts`). */
export function initialActivity() {
  return {
    /** When the session's tool calls ran, every loop's: whose a scanned change was. */
    calls: noActivity() as Activity,
    /** Numbers each call the activity keeps, so two calls never share an id. */
    callSeq: 0,
    /**
     * When the session's shell commands ran that left HEAD where it was: a
     * watcher scan whose changes were noticed while one ran took in what the
     * command wrote (`sed -i`, a heredoc, a formatter), and those files count
     * toward the turn's brief as an edit's do. A command that moved HEAD by
     * more than a commit (a checkout, a reset, a pull) is left out: the files
     * it brought are not the model's to answer for.
     */
    shell: noActivity() as Activity,
    /** The snapshots of the watcher scans that took in a shell command's changes since the last brief: their files are the turn's. */
    shellScans: new Set<string>(),
    /** When the shell commands ran that moved HEAD by more than a commit: no scan reaching back over one is a shell command's. */
    shellFences: [] as { start: number; end: number }[],
  }
}

/** The scans the mod runs: a turn's and the pane's rescans, queued one after another (`watcher.ts`, {@link exclusively}). */
export function initialScans() {
  return {
    /** A turn's scans, one at a time. */
    flight: null as SingleFlight | null,
    /** The pane's rescans, one at a time. */
    rescanFlight: null as SingleFlight | null,
    /** Set between a rescan press and the timer that starts it. */
    rescanQueued: false,
    /** The tail of the scans queued so far: a turn's scan and a rescan never write one graph at once. */
    queue: Promise.resolve() as Promise<unknown>,
    /** When the scan on its way began (the watcher's `scan_started`, or the leader's while following); null when none is. */
    startedAt: null as number | null,
    /** When the last scan the mod heard of began and landed: a change made while it ran is noticed only after it. */
    last: null as { start: number; end: number } | null,
  }
}

/** The pane's reads (`loaders.ts`): the dashboard, the ledger, component lookups and the reads that wait out a pause. */
export function initialLoads() {
  return {
    /** Dashboard loads, one at a time: an older, slower load can never land over a newer one. */
    dashboardFlight: null as SingleFlight | null,
    /** Whether the latest dashboard load stored what it read. */
    dashboardStored: false,
    /** When the last dashboard load started, or, for one that failed, when its answer came (mod clock, ms). */
    dashboardTriedAt: 0,
    /** Dashboard loads in a row that failed: the pane with no figures asks again sooner after the first ones. */
    dashboardMisses: 0,
    /** Reads of the changes since the session began, one at a time, a request during one coalescing into one more. */
    ledgerFlight: null as SingleFlight | null,
    /**
     * Component lookups in flight, by snapshot and name: a press while one runs
     * starts no second. Checked and set with no await between, so two presses
     * in the same tick cannot both start one.
     */
    fetching: new Set<string>(),
    /** The pending reads that wait out a pause (the lookup of the marked row beside the tab), by loader. */
    timers: new Map<string, Timer>(),
    /** The row (by `peekKey`) last found to have nothing to show beside the tab, so the tick does not look again. */
    peekNone: null as string | null,
  }
}

/** The live watcher's settings and its restarts (`watcher.ts`); the running child itself is {@link mod}`.watcher`. */
export function initialWatch(options: PluginOptions) {
  return {
    /** Whether the live watcher runs at all (userConfig `watch`), and its poll interval (`watchPollMs`). */
    on: options.watch !== false,
    pollMs: watchPollMsOf(options.watchPollMs),
    /**
     * Set when the watcher said this project is not its to watch (not allowed,
     * not scanned) or ended without a word (none offered, as with a wrapper
     * that has no watch case): not asked again until a root is allowed.
     */
    refused: false,
    /** Restarts of a watcher that ended on its own, since the last one that came up. */
    failures: 0,
    /** Not started again before this (mod clock, ms): the pause after a watcher ended on its own. */
    retryAt: 0,
    /** Set between deciding to start the watcher and the timer that starts it: one start at a time. */
    starting: false,
  }
}

/** The clocks that redraw (`motion.ts`, the age tick in `session.ts`). */
export function initialMotion() {
  return {
    /** Keeps the band's age current while the mod is on. */
    ticker: null as Timer | null,
    /** The frames the pane's motion draws (see `motion.ts`): running only while something moves. */
    timer: null as Timer | null,
    /** Bumped by every start of the motion, so a frame that read state across a restart does not stop the new one. */
    gen: 0,
    /** Frames ticked: while only a scan runs, every other one draws. */
    ticks: 0,
    /** While the pane draws: the motion's frame its spinners show. */
    spinFrame: 0,
  }
}

/** What the band and the pane last drew (`render.tsx`, `frame.ts`): the age tick and the motion compare against it. */
export function initialPane() {
  return {
    /** What the band last drew, or null when it drew nothing: the age tick compares against it. */
    bandText: null as string | null,
    /** The pane's header status as last drawn, or null when the pane drew none: the age tick compares against it too. */
    text: null as string | null,
    /** Whether the pane last drew its no-figures state: the age tick retries a failed load while it shows. */
    emptyShown: false,
    /** Whether the pane was last drawn wide (master-detail), as the render saw it. */
    wide: false,
    /** Whether a pane too large to draw however few rows it gave was logged: once a load. */
    tooLargeLogged: false,
    /** Whether the pane last drew a line that says something is on its way: the motion's frames turn its spinner. */
    busy: false,
    /** When the pane began to draw lines under way (mod clock, ms): they spin for {@link BUSY_SPIN_MS} at most. */
    busySince: 0,
  }
}

/** The finder's searches (`actions.ts`). */
export function initialSearch() {
  return {
    /** The finder's searches, one at a time, a keystroke during one coalescing into one more; and the pending debounce. */
    flight: null as SingleFlight | null,
    timer: null as Timer | null,
    /** Set by Enter before the answer for what is typed landed: its first match opens when it does. */
    openWhenFound: false,
    /** Where the tab's marker stood when the finder opened over it: closing the finder puts it back. */
    findFrom: 0,
  }
}

/** What the model was told (`agent.ts`), so nothing is said twice. */
export function initialAgent(options: PluginOptions) {
  return {
    /** Whether the model gets notes at all (userConfig `agentNotes`). */
    notesOn: options.agentNotes !== false,
    /**
     * What the model was told this session, so nothing is said twice. Keyed by
     * loop (`''` for the main one, else the agent id) and a NUL: a subagent's
     * context never held what the main loop read. `noted` holds files given
     * context (on Read or edit), `ruled` boundaries whose rules were stated.
     */
    noted: new Set<string>(),
    ruled: new Set<string>(),
    /** Tests a turn-end note named, and violations one reported; whether a truncated check was mentioned. */
    testsNamed: new Set<string>(),
    violationsSeen: new Set<string>(),
    truncationSaid: false,
    /** Whether a failed note after a tool call was logged this session: once is enough. */
    noteFailureLogged: false,
    /** Notes after tool results this turn, by loop; cleared when that loop's turn ends. */
    turnNotes: new Map<string, number>(),
    /** Whether each loop dug past a Knossos answer this turn (see ./dig); cleared when that loop's turn ends. */
    digs: new Map<string, Dig>(),
    /** Commit notes already given, by loop and text: the same note is never said twice. */
    commitNoted: new Set<string>(),
  }
}

/** A scan's toasts (`loaders.ts`). */
export function initialAlerts(options: PluginOptions) {
  return {
    /** Whether a scan's new cycles and violations are toasted (userConfig `notifications`), and the ones already said. */
    on: options.notifications !== false,
    toasted: new Set<string>(),
  }
}

/** What the presses that move about the pane keep (`navigate-actions.ts`). */
export function initialNavigation(options: PluginOptions) {
  return {
    /** The editor command `e` and a click on a place run (userConfig `editor`), as set: {@link editorCommand} reads it. */
    editor: options.editor as unknown,
    /** The pane element the focus ring last landed on: a tab's hidden twin is walked past by where it came from. */
    focused: null as string | null,
  }
}

/** Allowing a refused root (`allow-actions.ts`). */
export function initialAllow() {
  return {
    /**
     * Set from the confirming press until its allow-root run settles: a second
     * `y` in the same tick, before the state says running, starts nothing.
     */
    inFlight: false,
  }
}

/** The Notes tab (`notes-actions.ts`, `loaders.ts`). */
export function initialNotes() {
  return {
    /** Moved by each note the pane writes, so the Notes tab reads its notes again. */
    version: 0,
  }
}

/** The state of one load, by area: what {@link reset} starts over from the factories above. */
function initialSlices(options: PluginOptions, host: Host) {
  return {
    session: initialSession(options, host),
    registration: initialRegistration(),
    turn: initialTurn(),
    activity: initialActivity(),
    scans: initialScans(),
    loads: initialLoads(),
    watch: initialWatch(options),
    motion: initialMotion(),
    pane: initialPane(),
    search: initialSearch(),
    agent: initialAgent(options),
    alerts: initialAlerts(options),
    navigation: initialNavigation(options),
    allow: initialAllow(),
    notes: initialNotes(),
  }
}

/**
 * The mod's own state for one load, shared by its modules: what the session
 * state (`$.state`) does not hold because nothing draws from it. `register`
 * resets it ({@link reset}), as a reload starts the module's variables over.
 * Each slice keeps its identity across a reset, so a module may hold one.
 */
export const mod = {
  ...initialSlices({}, 'claude'),
  /**
   * Not reset: counters that only grow. A retry or a watcher loop of an older
   * load compares against them, so starting them over could let it act again.
   */
  generations: {
    /**
     * Bumped by each session start and by turning the mod off: a retry belongs to
     * the generation it was scheduled in, so one still in flight when a new
     * session starts ends there instead of chaining beside the new session's own.
     */
    register: 0,
    /** Bumped by every start and stop of the watcher: a loop of an older generation ends at its next piece instead of acting. */
    watch: 0,
  },
  /** Not reset: the running watcher child, which only {@link stopWatcher} lets go of (a reset calls it). */
  watcher: {
    /** The watcher child's stream while it runs: leaving its loop, or `return()` on it, ends the child. */
    stream: null as AsyncGenerator<unknown, unknown> | null,
    /** The running watcher's process id, as it said: signalled when it is stopped. */
    pid: null as number | null,
    /** Whether the watcher has seen changes it has not taken in yet. */
    pending: false,
  },
  /** Not reset: the pane's body rows as last drawn, and the first row its window showed, for the wheel; the next draw replaces them. */
  drawn: {
    rows: [] as readonly Row[],
    offset: 0,
  },
}

/**
 * Lets the live watcher go: its loop ends at its next piece, and `return()`
 * ends the child once the read it waits on ends. Never leaves one running:
 * the engine also ends it when the module unloads, and the watcher stops
 * itself once the process that started it is gone. Resolves to the process
 * id the running child named, if any.
 */
export function stopWatcher(): number | null {
  mod.generations.watch++
  const running = mod.watcher.stream
  const pid = mod.watcher.pid
  mod.watcher.stream = null
  mod.watcher.pid = null
  mod.watcher.pending = false
  if (running === null) return null
  void running.return(undefined).catch(() => undefined)
  return pid
}

/**
 * Starts the module's own state over for a load with `options`, run by
 * `host`: a reload runs `register` again, and nothing of the old load's may
 * carry over. The old load's timers are cancelled and its watcher let go;
 * every slice {@link initialSlices} names starts over in place.
 */
export function reset(options: PluginOptions, host: Host = 'claude'): void {
  mod.motion.ticker?.cancel()
  mod.motion.timer?.cancel()
  mod.generations.register++
  mod.registration.timer?.cancel()
  // A reload starts the module over; the engine ends the old load's child with it, and this makes sure.
  stopWatcher()
  mod.search.timer?.cancel()
  mod.loads.timers.forEach(timer => timer.cancel())
  const fresh = initialSlices(options, host)
  for (const key of Object.keys(fresh) as (keyof typeof fresh)[]) Object.assign(mod[key], fresh[key])
}

/** The most notes the model reads after tool results in one turn of one loop; past it, notes wait for the next turn. */
const NOTES_PER_TURN = 3

/** The edited file's path from an edit tool's input: `notebook_path` for NotebookEdit. */
export function editedPath(e: object): string | null {
  const { file_path: file, notebook_path: notebook } = e as { file_path?: unknown; notebook_path?: unknown }
  if (typeof file === 'string') return file
  return typeof notebook === 'string' ? notebook : null
}

/** The cycles between types a dashboard holds, by members: what tells a cycle new since the session began. */
const cycleKeys = (d: Dashboard): string[] => betweenTypes(d.cycles.largest).map(c => [...c.members].sort().join('\u0000'))

/**
 * The cycles the graph holds as a session begins: the count, the listed ones,
 * whether the list is all of them, and whether the count is a whole count
 * rather than what a search that stopped early reached. `exact` is absent in
 * state recorded before it existed.
 */
export type StartCycles = { count: number; keys: string[]; complete: boolean; exact?: boolean }

export const cyclesOf = (d: Dashboard): StartCycles => ({ count: d.cycles.count, keys: cycleKeys(d), complete: !d.cycles.truncated && betweenTypes(d.cycles.largest).length >= d.cycles.count, exact: !cycleSearchStopped(d.cycles) })

/** Whether `path`'s change is this session's own: always, without the scan ledger's origins (the turns reported it). */
export const ownChange = (session: SessionChanges, path: string): boolean => session.origins === undefined || session.origins[path] === 'session'

/** Whether `loop` may get another note this turn; counts it when it may. */
export function takeNoteSlot(loop: string): boolean {
  const used = mod.agent.turnNotes.get(loop) ?? 0
  if (used >= NOTES_PER_TURN) return false
  mod.agent.turnNotes.set(loop, used + 1)
  return true
}

/**
 * Runs `job` once every scan queued before it has settled, so a turn's scan
 * and a rescan never write one graph at once. A failed job does not stall
 * the queue.
 */
export function exclusively<T>(job: () => Promise<T>): Promise<T> {
  const run = mod.scans.queue.then(job, job)
  mod.scans.queue = run.catch(() => undefined)
  return run
}

/** The most fences kept: far more than one lookback window holds. */
const FENCES_KEPT = 50

/** Fences off the moment a shell command moved HEAD by more than a commit, from `start` to `end` (the mod's clock). */
export function fence(start: number, end: number): void {
  mod.activity.shellFences.push({ start, end })
  if (mod.activity.shellFences.length > FENCES_KEPT) mod.activity.shellFences.shift()
}
