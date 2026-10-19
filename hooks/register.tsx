/**
 * The hooks module: the session state's atoms, the engine port the mod's
 * modules reach the engine through (see `mod/port.ts` for why), and the
 * hooks, each wired to what `mod/` does. The engine loads this file and
 * every file it imports from the plugin.
 */
import { atom, read, update } from 'claude-code'
import type { EngineInterface, Register } from 'claude-code'

import { begin, counts, finish } from './lib/activity'
import { committedSince, movedTree } from './lib/agent'
import { copyCommand, focusLanded, focusMoved, handlersOf, wheelScrolled } from './mod/actions'
import { commitNoteFor, headAt, noteEdit, noteRead, noteSafely, projectRoot, reflogAt } from './mod/agent'
import { cellChange, INITIAL } from './mod/cells'
import { freshDig, observe } from './mod/dig'
import type { Port } from './mod/port'
import { drawBand, drawPane } from './mod/render'
import { endSession, endTurn, openPane, registerCommand, retryRegister, runCommand, startUp } from './mod/session'
import { editedPath, fence, mod, PANE, reset, takeNoteSlot } from './mod/state'

const EDIT_TOOLS = ['Edit', 'Write', 'NotebookEdit'] as const
/** The Bash commands kept per turn to tell whether it ran the tests. */
const COMMANDS_KEPT = 50
// Each atom starts as ./mod/cells says: the engine takes `read` and `update` only on atoms declared in this file.
const brief = atom({ plugin: 'knossos', key: 'brief' } as const, INITIAL.brief)
const dashboard = atom({ plugin: 'knossos', key: 'dashboard' } as const, INITIAL.dashboard)
const job = atom({ plugin: 'knossos', key: 'job' } as const, INITIAL.job)
const view = atom({ plugin: 'knossos', key: 'view' } as const, INITIAL.view)
const detail = atom({ plugin: 'knossos', key: 'detail' } as const, INITIAL.detail)
const refresh = atom({ plugin: 'knossos', key: 'refresh' } as const, INITIAL.refresh)
const rescan = atom({ plugin: 'knossos', key: 'rescan' } as const, INITIAL.rescan)
const allow = atom({ plugin: 'knossos', key: 'allow' } as const, INITIAL.allow)
const theme = atom({ plugin: 'knossos', key: 'theme' } as const, INITIAL.theme)
const changes = atom({ plugin: 'knossos', key: 'changes' } as const, INITIAL.changes)
const sessionRoot = atom({ plugin: 'knossos', key: 'sessionRoot' } as const, INITIAL.sessionRoot)
const live = atom({ plugin: 'knossos', key: 'live' } as const, INITIAL.live)
const sessionLedger = atom({ plugin: 'knossos', key: 'sessionLedger' } as const, INITIAL.sessionLedger)
const sessionStart = atom({ plugin: 'knossos', key: 'sessionStart' } as const, INITIAL.sessionStart)
const sessionBegan = atom({ plugin: 'knossos', key: 'sessionBegan' } as const, INITIAL.sessionBegan)
const sessionEdits = atom({ plugin: 'knossos', key: 'sessionEdits' } as const, INITIAL.sessionEdits)
const sessionScans = atom({ plugin: 'knossos', key: 'sessionScans' } as const, INITIAL.sessionScans)
const sessionRev = atom({ plugin: 'knossos', key: 'sessionRev' } as const, INITIAL.sessionRev)
const fileDiff = atom({ plugin: 'knossos', key: 'fileDiff' } as const, INITIAL.fileDiff)
const diffFold = atom({ plugin: 'knossos', key: 'diffFold' } as const, INITIAL.diffFold)
const untestedOnly = atom({ plugin: 'knossos', key: 'untestedOnly' } as const, INITIAL.untestedOnly)
const gitHead = atom({ plugin: 'knossos', key: 'gitHead' } as const, INITIAL.gitHead)
const couplings = atom({ plugin: 'knossos', key: 'couplings' } as const, INITIAL.couplings)
const feedback = atom({ plugin: 'knossos', key: 'feedback' } as const, INITIAL.feedback)
const search = atom({ plugin: 'knossos', key: 'search' } as const, INITIAL.search)
const peek = atom({ plugin: 'knossos', key: 'peek' } as const, INITIAL.peek)
const branch = atom({ plugin: 'knossos', key: 'branch' } as const, INITIAL.branch)
const flash = atom({ plugin: 'knossos', key: 'flash' } as const, INITIAL.flash)
const motion = atom({ plugin: 'knossos', key: 'motion' } as const, INITIAL.motion)
const churn = atom({ plugin: 'knossos', key: 'churn' } as const, INITIAL.churn)
const notes = atom({ plugin: 'knossos', key: 'notes' } as const, INITIAL.notes)
const rings = atom({ plugin: 'knossos', key: 'rings' } as const, INITIAL.rings)
const route = atom({ plugin: 'knossos', key: 'route' } as const, INITIAL.route)
const note = atom({ plugin: 'knossos', key: 'note' } as const, INITIAL.note)
const startCycles = atom({ plugin: 'knossos', key: 'startCycles' } as const, INITIAL.startCycles)

/**
 * The engine as the mod's modules reach it, from this dispatch's `$`. The
 * engine follows `$` only into functions of this file and takes `read` and
 * `update` only on an atom declared here, so every call the modules make is
 * spelled out once below, and every atom is handed over as a cell.
 */
function portOf($: EngineInterface): Port {
  return {
    clock: { now: () => $.clock.now(), after: (ms, run) => $.clock.after(ms, run), every: (ms, run) => $.clock.every(ms, run) },
    ui: {
      log: (text, options) => $.ui.log(text, options),
      toast: text => $.ui.toast(text),
      copy: args => $.ui.copy(args),
      focus: args => $.ui.focus(args),
      scroll: args => $.ui.scroll(args),
      panes: () => $.ui.panes(),
      invalidate: event => $.ui.invalidate(event),
      open: args => $.ui.open(args),
      close: args => $.ui.close(args),
    },
    session: { root: () => $.session.root(), id: () => $.session.id(), append: args => $.session.append(args) },
    process: { run: (argv, options) => $.process.run(argv, options), spawn: args => $.process.spawn(args) },
    fs: { stat: (path, options) => $.fs.stat(path, options) },
    store: { get: key => $.store.get(key), set: (key, value) => $.store.set(key, value) },
    config: { list: () => $.config.list() },
    command: { register: args => $.command.register(args) },
    prompt: { submit: args => $.prompt.submit(args) },
    plugin: { root: $.plugin.root },
    state: {
      brief: { read: () => read($, brief), update: change => update($, brief, change) },
      dashboard: { read: () => read($, dashboard), update: change => update($, dashboard, change) },
      job: { read: () => read($, job), update: change => update($, job, change) },
      // A move of the marker, the tab or what is open lets the wheel's windows go (withScrollsKept).
      view: { read: () => read($, view), update: change => update($, view, cellChange('view', change)) },
      detail: { read: () => read($, detail), update: change => update($, detail, change) },
      refresh: { read: () => read($, refresh), update: change => update($, refresh, change) },
      rescan: { read: () => read($, rescan), update: change => update($, rescan, change) },
      allow: { read: () => read($, allow), update: change => update($, allow, change) },
      theme: { read: () => read($, theme), update: change => update($, theme, change) },
      changes: { read: () => read($, changes), update: change => update($, changes, change) },
      sessionRoot: { read: () => read($, sessionRoot), update: change => update($, sessionRoot, change) },
      live: { read: () => read($, live), update: change => update($, live, change) },
      sessionLedger: { read: () => read($, sessionLedger), update: change => update($, sessionLedger, change) },
      sessionStart: { read: () => read($, sessionStart), update: change => update($, sessionStart, change) },
      sessionBegan: { read: () => read($, sessionBegan), update: change => update($, sessionBegan, change) },
      sessionEdits: { read: () => read($, sessionEdits), update: change => update($, sessionEdits, change) },
      sessionScans: { read: () => read($, sessionScans), update: change => update($, sessionScans, change) },
      sessionRev: { read: () => read($, sessionRev), update: change => update($, sessionRev, change) },
      fileDiff: { read: () => read($, fileDiff), update: change => update($, fileDiff, change) },
      diffFold: { read: () => read($, diffFold), update: change => update($, diffFold, change) },
      untestedOnly: { read: () => read($, untestedOnly), update: change => update($, untestedOnly, change) },
      gitHead: { read: () => read($, gitHead), update: change => update($, gitHead, change) },
      couplings: { read: () => read($, couplings), update: change => update($, couplings, change) },
      feedback: { read: () => read($, feedback), update: change => update($, feedback, change) },
      search: { read: () => read($, search), update: change => update($, search, change) },
      peek: { read: () => read($, peek), update: change => update($, peek, change) },
      branch: { read: () => read($, branch), update: change => update($, branch, change) },
      flash: { read: () => read($, flash), update: change => update($, flash, change) },
      motion: { read: () => read($, motion), update: change => update($, motion, change) },
      churn: { read: () => read($, churn), update: change => update($, churn, change) },
      notes: { read: () => read($, notes), update: change => update($, notes, change) },
      rings: { read: () => read($, rings), update: change => update($, rings, change) },
      route: { read: () => read($, route), update: change => update($, route, change) },
      note: { read: () => read($, note), update: change => update($, note, change) },
      startCycles: { read: () => read($, startCycles), update: change => update($, startCycles, change) },
    },
  }
}

export const register: Register = (on, options) => {
  if (options.enabled === false) return
  reset(options)
  const openOnStart = options.openPaneOnStart === true

  on('session.start', async ($, e, next) => {
    const io = portOf($)
    // A refused registration (no session bound yet, after a hot reload) is retried; start-up goes on regardless.
    const gen = ++mod.registerGen
    mod.registerTimer?.cancel()
    mod.registerTimer = null
    if (!(await registerCommand(io))) retryRegister(io, 0, gen)
    // A start-up that outlives the session (torn down under it) fails quietly, never as a stray rejection.
    $.clock.after(0, () => void startUp(io, openOnStart).catch(() => undefined))
    return next(e)
  })

  on('session.end', async ($, e, next) => {
    await endSession(portOf($), e.reason, e.sessionId)
    return next(e)
  })

  /**
   * The call's result, with the dig reminder (see ./mod/dig) added when this call completes the pattern. A call that
   * was denied or failed answered nothing, so it neither starts, counts toward nor answers a dig. The reminder takes
   * no slot of the turn's note cap: it comes once a turn at most, and a capped one would be lost for the whole turn.
   */
  const dug = <R extends { deny?: unknown; isError?: boolean; context?: string[] }>(e: { tool: string; agentId?: string }, ran: R): R => {
    if (mod.disabled || !mod.notesOn || ran.deny !== undefined || ran.isError === true) return ran
    const loop = e.agentId ?? ''
    const seen = observe(mod.digs.get(loop) ?? freshDig(), e.tool, e)
    mod.digs.set(loop, seen.dig)
    return seen.remind === null ? ran : { ...ran, context: [...(ran.context ?? []), seen.remind] }
  }

  // Every call of the session's, in every loop. One handler, since the engine takes one without a matcher:
  // - by any tool that can write rather than wait or read: while one runs (and a moment after), what the watcher
  //   scans is the session's own, whatever route the change took;
  // - by any tool at all: past a Knossos answer, a loop that keeps looking things up is asked, once a turn, to write
  //   down what it found, on the result of the call that completes the pattern.
  on('tool.call', async ($, e, next) => {
    const timed = !mod.disabled && counts(e.tool)
    const id = `${++mod.callSeq}`
    if (timed) begin(mod.activity, id, await $.clock.now())
    try {
      return dug(e, await next(e))
    } finally {
      // A clock torn down under the call (the session ending) ends it where it began.
      if (timed) finish(mod.activity, id, await $.clock.now().catch(() => mod.activity.running.get(id) ?? 0))
    }
  })

  on('tool.call', { tool: EDIT_TOOLS }, async ($, e, next) => {
    const io = portOf($)
    // Before the edit lands: the snapshot the turn's brief diffs against, whoever scans the edit first.
    // It becomes the turn's start only once the edit is known to have landed inside the project.
    const base = mod.snapshot
    const ran = await next(e)
    if (!mod.disabled) mod.lastEditAt = await $.clock.now()
    if (mod.disabled || ran.deny !== undefined || ran.isError === true) return ran
    // Tests run before this edit ran the old code.
    mod.ranCommands = []
    return noteSafely(io, ran, async () => {
      const path = editedPath(e)
      const note = path === null ? null : await noteEdit(io, path, base)
      const key = note === null ? '' : `${e.agentId ?? ''}\u0000${note.path}`
      // Told already, on its Read or an earlier edit.
      if (note === null || mod.noted.has(key)) return ran
      // Held back by this turn's cap: not delivered, so not noted; a later edit or Read says it.
      if (mod.notesOn && !takeNoteSlot(e.agentId ?? '')) return ran
      mod.noted.add(key)
      $.ui.toast(note.text)
      return mod.notesOn ? { ...ran, context: [...(ran.context ?? []), note.text] } : ran
    })
  })

  // Before an edit: what the file is to the rest of the project, so the edit keeps to its rules.
  on('tool.call', { tool: 'Read' }, async ($, e, next) => {
    const io = portOf($)
    const ran = await next(e)
    if (mod.disabled || ran.deny !== undefined || ran.isError === true) return ran
    return noteSafely(io, ran, async () => {
      const path = (e as { file_path?: unknown }).file_path
      const note = typeof path === 'string' ? await noteRead(io, path, e.agentId ?? '') : null
      return note === null ? ran : { ...ran, context: [...(ran.context ?? []), note] }
    })
  })

  // Every command is kept for the turn-end note, which leaves out the tests the turn already ran.
  // A command marks the turn for its brief only on a cheap signal, never by a scan of its own (an incremental scan of
  // a real project takes seconds): a change the live watcher noticed while it ran (see mod.shellActivity and
  // ./mod/watcher), or HEAD moved by more than a commit.
  on('tool.call', { tool: 'Bash' }, async ($, e, next) => {
    const io = portOf($)
    // Where the project's HEAD stood before the command: only a command that moved it can have committed there.
    const repo = mod.disabled ? null : await projectRoot(io)
    const before = repo === null ? null : await headAt(io, repo)
    const id = `${++mod.callSeq}`
    // A clock torn down under the call (the session ending) keeps no span for it.
    const startedAt = mod.disabled ? null : await $.clock.now().catch(() => null)
    if (startedAt !== null) begin(mod.shellActivity, id, startedAt)
    let ran: Awaited<ReturnType<typeof next>>
    try {
      ran = await next(e)
    } catch (err) {
      mod.shellActivity.running.delete(id)
      throw err
    }
    const command = (e as { command?: unknown }).command
    if (ran.deny === undefined && typeof command === 'string' && mod.ranCommands.length < COMMANDS_KEPT) mod.ranCommands.push(command)
    // HEAD after it, and its reflog only when it moved: read once, for the turn and for the commit note.
    const after = repo === null || before === null ? null : await headAt(io, repo)
    const reflog = repo === null || after === null || after === before ? '' : await reflogAt(io, repo)
    if (ran.deny !== undefined || movedTree(before, after, reflog, ran.text ?? '')) {
      // A denied command wrote nothing. One that brought another tree in (a checkout, a reset, a pull) leaves its
      // files out of the verdict, along with anything a scan took in while it ran; the turn's brief still runs, so
      // the band and the Changes tab show where the checkout stands now.
      mod.shellActivity.running.delete(id)
      if (ran.deny === undefined && !mod.disabled) {
        mod.shellScans.clear()
        mod.dirty = true
        // A scan that lands later and reaches back over this command is the checkout's, even when an earlier command
        // ran inside the same window: fenced off (see fenced in ./mod/watcher).
        const endedAt = await $.clock.now().catch(() => null)
        if (startedAt !== null && endedAt !== null) fence(startedAt, endedAt)
      }
    } else {
      finish(mod.shellActivity, id, await $.clock.now().catch(() => mod.shellActivity.running.get(id) ?? 0))
    }
    // A commit, in any loop: what it carries that the graph knows of, said once.
    if (mod.disabled || !mod.notesOn || ran.deny !== undefined || repo === null || before === null) return ran
    return noteSafely(io, ran, async () => {
      if (!committedSince(before, after, reflog, ran.text ?? '')) return ran
      const note = await commitNoteFor(io, e.agentId ?? '')
      return note === null ? ran : { ...ran, context: [...(ran.context ?? []), note] }
    })
  })

  on('turn.complete', async ($, e, next) => {
    const result = await next(e)
    mod.digs.delete(e.agentId ?? '')
    await endTurn(portOf($), e.agentId)
    return result
  })

  on('ui.render', { component: 'AbovePrompt' }, async ($, e, next) => {
    const io = portOf($)
    const band = await drawBand(io, () => $.ui.resolve(e), e.props, {
      details: () => openPane(io),
      copy: (command, surface) => void copyCommand(io, command, surface).catch(() => undefined),
      hide: () => update($, view, v => ({ ...v, isBandHidden: true })),
    })
    return band ?? next(e)
  })

  // A theme picked in /config redraws the heat map in its colours.
  on('config.set', { key: 'theme' }, async ($, e, next) => {
    const set = await next(e)
    const value = set.value
    if (set.deny === undefined && typeof value === 'string') await update($, theme, () => value)
    return set
  })

  on('command.run', { command: 'knossos' }, async ($, e) => ({ text: await runCommand(portOf($), e.args) }))

  // The arrows, Tab or a click moving the focus onto a listed row move the marker with it.
  on('ui.focus', { requestId: PANE }, async ($, e, next) => {
    const io = portOf($)
    // A person's move names the element; a `$.ui.focus` call may arrive as its own arguments, naming it `key`.
    const asked = e.element ?? (e as { key?: unknown }).key
    const moved = await focusMoved(io, asked)
    if (moved !== null && 'refuse' in moved) return {}
    // Redirected in the field the move named: a person's move its `element`, a `$.ui.focus` call its `key`.
    const redirected = moved === null ? e : e.element === undefined ? ({ ...e, key: moved.to } as typeof e) : { ...e, element: moved.to }
    const landed = await next(redirected)
    const element = moved === null ? asked : moved.to
    if (landed.deny === undefined && typeof element === 'string') await focusLanded(io, element)
    return landed
  })

  // The wheel over a list longer than its window scrolls that list, the marker left where it is; anywhere else the page scrolls.
  on('ui.scroll', { component: 'Pane', requestId: PANE }, async ($, e, next) => {
    if (e.origin.kind !== 'person' || e.pointer === undefined) return next(e)
    // At a list's end, or over none, the page takes the wheel, as a browser hands it on.
    return (await wheelScrolled(portOf($), mod.paneRows, mod.paneOffset, e.pointer, e.by)) ? {} : next(e)
  })

  on('ui.render', { component: 'Pane', requestId: PANE }, async ($, e) => {
    const io = portOf($)
    return drawPane(io, $.ui.resolve(e), e.surface, e.props, handlersOf(io))
  })
}
