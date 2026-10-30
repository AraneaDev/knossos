/**
 * The session's life as the mod follows it: registering `/knossos`, start-up, the age tick, the end of a turn and of a session,
 * and the `/knossos` command with the pane it opens.
 */
import type { ConfigRow, UiPane } from 'claude-code'

import { bandModel } from '../lib/band'
import { NO_CHANGES } from '../lib/session-changes'
import { noGraphOf } from '../lib/empty-state'
import { paneStatus } from '../lib/pane-status'
import { SingleFlight } from '../lib/scheduler'
import { declaredOf, huesOf } from '../lib/palette'
import { currentSession, peekKey, refreshDashboard, request, showComponent } from './loaders'
import { readGitHead, settleBaseline } from './session-baseline'
import { endWatcher, placed } from './port'
import type { Port } from './port'
import { cyclesOf, mod, PANE } from './state'
import { ensureWatcher, scanSafely } from './watcher'

/** What `/knossos` answers to anything it does not take. */
const USAGE = 'Usage: /knossos to toggle the architecture pane; /knossos inspect <component> to open it on one component.'

/** formatAge's finest step is a second; the tick redraws only when the text would change. */
const AGE_TICK_MS = 1_000

/**
 * How long the pane with no figures waits, after the n-th load in a row that
 * failed, before it asks for the dashboard again; past the last, the last.
 * The first waits are short: a knossos still starting with the session
 * answers within seconds, and the pane should not stand empty for long.
 */
const EMPTY_RETRY_MS = [2_000, 5_000, 15_000] as const

/** How long to wait before each retry of a refused registration, in milliseconds; after the last, turn ends retry. */
const REGISTER_RETRY_MS = [500, 1_000, 2_000, 4_000, 8_000, 16_000, 30_000] as const

/**
 * Registers `/knossos`; false when the engine refuses, as it does when no
 * session is bound in the process yet (the moment after a hot reload). The
 * first refusal leaves one debug line.
 *
 * The mod registers no tool for the model: a tool registered here is served
 * under the plugin's name, `knossos`, and the engine keeps one server per
 * name, so it would hide the Knossos MCP server and every tool it has. The
 * server answers `file_context` itself.
 */
export async function registerCommand(io: Port): Promise<boolean> {
  if (mod.registration.registered) return true
  try {
    await io.command.register({
      name: 'knossos',
      description: 'Toggle the Knossos architecture pane; /knossos inspect <component> to drill in',
    })
    mod.registration.registered = true
    return true
  } catch (err) {
    if (!mod.registration.failureLogged) {
      mod.registration.failureLogged = true
      io.ui.log(`knossos: could not register /knossos yet, retrying (${err instanceof Error ? err.message : String(err)})`, { to: 'debug' })
    }
    return false
  }
}

/** Retries a refused registration after the `attempt`-th delay, then the next; past the last, the end of a turn tries again. */
export function retryRegister(io: Port, attempt: number, gen = mod.generations.register): void {
  const delay = REGISTER_RETRY_MS[attempt]
  if (delay === undefined || mod.registration.registered || mod.session.disabled || gen !== mod.generations.register) return
  try {
    mod.registration.timer = io.clock.after(delay, () => {
      mod.registration.timer = null
      if (gen !== mod.generations.register) return
      void registerCommand(io)
        .then(done => (done ? undefined : retryRegister(io, attempt + 1, gen)))
        .catch(() => undefined)
    })
  } catch {
    mod.registration.timer = null
  }
}

/**
 * Redraws the band when the text it would draw now differs from what it drew:
 * a render answer is reused until state changes, so without this an age such
 * as "as of 12s ago" would stand still while the figures grow old.
 */
async function tickAge(io: Port): Promise<void> {
  // After a /clear or a resume the process may name the new session only later: its baseline is settled once it does.
  if (mod.session.baselineOf === null && !mod.session.disabled && (await currentSession(io)) !== null) await settleBaseline(io)
  // The watcher is kept running from here, never from what its own events start: no call loops back on itself.
  await ensureWatcher(io)
  await retryEmpty(io)
  // The footer's word after an action fades once its time is up: a change of state, so the pane redraws without it.
  const said = await io.state.feedback.read()
  if (said !== null && (await io.clock.now()) >= said.until) await io.state.feedback.update(() => null)
  // The rows a scan lit go back once their moment is over.
  const lit = await io.state.flash.read()
  if (lit !== null && (await io.clock.now()) >= lit.until) await io.state.flash.update(() => null)
  // A pane drawn wide shows the marked row's detail beside the tab: looked up from here the first time it is drawn so.
  // A row already found to have nothing to show is not looked at again each tick: that would lay the pane out every second.
  if (mod.pane.wide && (await io.state.peek.read()) === null && mod.loads.peekNone !== peekKey(await io.state.view.read(), (await io.state.dashboard.read())?.snapshot_id ?? null)) await request(io, 'peek')
  // A pane closed by any means (the command, its own close key) stops drawing; stop ticking for it, and it is no longer wide.
  if ((mod.pane.text !== null || mod.pane.busy) && !(await io.ui.panes()).some((pane: UiPane) => pane.id === PANE)) {
    mod.pane.text = null
    mod.pane.wide = false
    mod.pane.busy = false
  }
  if (mod.pane.bandText === null && mod.pane.text === null) return
  const now = await io.clock.now()
  const d = await io.state.dashboard.read()
  const model = bandModel(await io.state.brief.read(), await io.state.job.read(), now, d?.status === 'ok' ? declaredOf(d) : undefined, d?.status === 'ok' ? huesOf(d) : undefined)
  const pane = d?.status === 'ok' ? paneStatus(d, await io.state.refresh.read(), await io.state.rescan.read(), now, await io.state.live.read()).text : null
  const bandStale = mod.pane.bandText !== null && (model?.text ?? null) !== mod.pane.bandText
  const paneStale = mod.pane.text !== null && pane !== mod.pane.text
  if (bandStale || paneStale) io.ui.invalidate('ui.render')
}

/**
 * While the pane shows no figures because a load failed (silent, or an
 * error), asks for the dashboard again, sooner after the first failures
 * than after later ones ({@link EMPTY_RETRY_MS}): the pane says it asks
 * again, so it does.
 */
async function retryEmpty(io: Port): Promise<void> {
  if (!mod.pane.emptyShown || mod.session.disabled || mod.loads.dashboardFlight?.isRunning === true) return
  if (!(await io.ui.panes()).some((pane: UiPane) => pane.id === PANE)) {
    mod.pane.emptyShown = false
    return
  }
  const d = await io.state.dashboard.read()
  if (d?.status === 'ok' || noGraphOf(d, await io.state.refresh.read()) !== 'unreadable') return
  const wait = EMPTY_RETRY_MS[Math.min(Math.max(0, mod.loads.dashboardMisses - 1), EMPTY_RETRY_MS.length - 1)]!
  if ((await io.clock.now()) - mod.loads.dashboardTriedAt < wait) return
  void refreshDashboard(io).catch(() => undefined)
}

/**
 * Stores the person's theme from `/config`, for the colours a Raster cannot
 * take as theme keys. A config that cannot be read leaves the dark default.
 */
async function readTheme(io: Port): Promise<void> {
  const row = (await io.config.list().catch(() => [])).find((r: ConfigRow) => r.key === 'theme')
  if (typeof row?.value === 'string') await io.state.theme.update(() => row.value as string)
}

/**
 * The first dashboard. A silent one is not a reason to stop: the next dirty
 * turn asks again. Only the wrapper's `no-binary` turns the mod off.
 */
export async function startUp(io: Port, openOnStart: boolean): Promise<void> {
  // First: where the session began, read back for a session continued here, else the commit it begins at, before anything of it can be committed.
  await settleBaseline(io)
  // Where the checkout stands, for the header: the baseline's own head read, when there was one, said it already.
  if (!mod.session.gitAsked) await readGitHead(io)
  await readTheme(io)
  const root = await io.session.root().then((r: string) => placed(io, r)).catch(() => null)
  await io.state.sessionRoot.update(() => root)
  const stored = await refreshDashboard(io)
  if (mod.session.disabled) return
  mod.motion.ticker ??= io.clock.every(AGE_TICK_MS, () => void tickAge(io).catch(() => undefined))
  await ensureWatcher(io)
  // A refused or unplaced pane is the person's layout, not a failure of the mod.
  if (openOnStart) await openPane(io, !stored)
}

/**
 * Opens the pane and, unless the dashboard was just loaded, refreshes it on a
 * timer so the pane never presents old figures as current. A refused or
 * unplaced pane is the person's layout, not a failure of the mod.
 */
export async function openPane(io: Port, refreshFirst = true): Promise<void> {
  if (refreshFirst) io.clock.after(0, () => void refreshDashboard(io).catch(() => undefined))
  // Transparent: the pane lies on the terminal's own background, as the chat beside it does.
  await io.ui.open({ id: PANE, title: 'Knossos', backgroundColor: 'transparent' }).catch(() => undefined)
}

/** `/knossos` toggles the pane, `/knossos inspect <component>` opens it on one component. */
export async function runCommand(io: Port, args: string): Promise<string> {
  const verb = args.trim().split(/\s+/)[0] ?? ''
  if (verb === 'inspect') {
    const name = args.trim().slice('inspect'.length).trim()
    if (name === '') return USAGE
    // The snapshot first, so the lookup is keyed to the graph it reads.
    const loaded = (await io.state.dashboard.read()) === null && (await refreshDashboard(io))
    await showComponent(io, { name, label: name })
    await openPane(io, !loaded)
    return 'Knossos pane opened.'
  }
  if (verb !== '') return USAGE
  if ((await io.ui.panes()).some((pane: UiPane) => pane.id === PANE)) {
    await io.ui.close({ id: PANE }).catch(() => undefined)
    mod.pane.text = null
    mod.pane.wide = false
    mod.pane.busy = false
    return 'Knossos pane closed.'
  }
  await io.state.view.update(v => ({ ...v, inspect: null }))
  await openPane(io)
  return 'Knossos pane opened.'
}

/** Session ends after which the process goes no further with this project: no watcher is started again. */
const FINAL_ENDS: readonly string[] = ['prompt_input_exit', 'logout', 'other']

/**
 * The session ends (exit, /clear, resume): its watcher ends with it. After a
 * /clear or an in-process resume the process goes on (no session.start
 * fires), so the next tick starts a watcher again; after an exit, a logout or
 * a signal, none.
 */
export async function endSession(io: Port, reason: string, sessionId: string): Promise<void> {
  await endWatcher(io).catch(() => undefined)
  if (FINAL_ENDS.includes(reason)) {
    mod.watch.retryAt = Number.POSITIVE_INFINITY
    return
  }
  mod.watch.failures = 0
  mod.watch.retryAt = 0
  // A new session in the same process: its changes start now, at the graph as it stands, and its cycles too.
  await io.state.sessionStart.update(() => mod.session.snapshot)
  const now = await io.state.dashboard.read()
  await io.state.startCycles.update(() => (now?.status === 'ok' ? cyclesOf(now) : null))
  mod.agent.commitNoted = new Set()
  mod.alerts.toasted = new Set()
  // What the notes told the session that ended: the next one's model never read it, so it is news again.
  mod.agent.noted = new Set()
  mod.agent.ruled = new Set()
  mod.agent.testsNamed = new Set()
  mod.agent.violationsSeen = new Set()
  mod.agent.truncationSaid = false
  await io.state.sessionBegan.update(() => null)
  await io.state.sessionEdits.update(() => [])
  await io.state.sessionScans.update(() => [])
  // Its diffs are taken against the commit it begins at.
  await io.state.sessionRev.update(() => null)
  await io.state.fileDiff.update(() => null)
  await io.state.diffFold.update(() => null)
  await io.state.untestedOnly.update(() => false)
  // The session that ended keeps its baseline; the one that follows reads its own back (a resume) or records one.
  mod.session.ended = sessionId
  mod.session.baselineOf = null
  mod.session.headAsked = false
  io.clock.after(0, () => void settleBaseline(io).catch(() => undefined))
  await io.state.sessionLedger.update(() => null)
  await io.state.changes.update(() => NO_CHANGES)
}

/**
 * A turn of `agentId`'s loop has ended (the main loop's when undefined): its
 * notes are counted afresh, and after the main loop's turn the registration
 * is asked for again if it is still refused, the header's checkout and an
 * absent graph are read again, and the turn's scan is scheduled when it
 * edited anything.
 */
export async function endTurn(io: Port, agentId: string | undefined): Promise<void> {
  mod.agent.turnNotes.delete(agentId ?? '')
  // A subagent's turn ends inside the main one; its edits and commands ride the main turn's scan.
  if (agentId !== undefined) return
  mod.turn.ran = mod.turn.ranCommands
  mod.turn.ranCommands = []
  // Still refused once the timed retries ran out: each turn's end asks again, a session being bound by now.
  if (!mod.session.disabled && !mod.registration.registered && mod.registration.timer === null) await registerCommand(io)
  // The model's notes land during its turns, through MCP, which moves no snapshot: the Notes tab reads them again.
  if (!mod.session.disabled) {
    mod.notes.version += 1
    await request(io, 'notes').catch(() => undefined)
  }
  // The turn may have committed or switched branches: the header reads where the checkout stands again.
  if (!mod.session.disabled) io.clock.after(0, () => void readGitHead(io).catch(() => undefined))
  // No graph to draw yet (the person may just have asked Claude to scan): look again, once the turn is over.
  if (!mod.session.disabled && (await io.state.dashboard.read())?.status !== 'ok') io.clock.after(0, () => void refreshDashboard(io).catch(() => undefined))
  // Nothing to brief, now or held for later: no turn start may carry over to a later turn.
  if (!mod.turn.dirty && mod.turn.edited.size === 0) mod.turn.base = null
  if (mod.session.disabled || !mod.turn.dirty) return
  mod.turn.dirty = false
  const current = (mod.scans.flight ??= new SingleFlight(() => scanSafely(io)))
  // Never inside the hook's budget: the job starts once this dispatch has resolved.
  io.clock.after(0, () => void current.request())
}
