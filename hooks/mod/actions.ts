/**
 * What the person's presses and keystrokes on the pane do: the press table,
 * the fields' keystrokes, and the few presses that only hand something on
 * (the clipboard, a prompt to Claude). The flows behind the table live
 * beside it: `navigate-actions.ts`, `finder-actions.ts`, `annotate-actions.ts`,
 * `notes-actions.ts`, `allow-actions.ts` and `diff-actions.ts`.
 */
import type { AllowState, KnossosView, PaneTab } from '../../types'
import { askPrompt, SORTS, subjectOf } from '../lib/pane-list'
import { noGraphOf, SCAN_PROMPT } from '../lib/empty-state'
import { TABS } from '../lib/chrome'
import { nextFilter } from '../lib/notes-view'
import { confirmAllow, offerAllow } from './allow-actions'
import { confirmNote, startNote, submitNote, typeNote } from './annotate-actions'
import { openHunk, pageDiff } from './diff-actions'
import { closeFinder, openFinder, openOrMove, startRoute, submitQuery, typeQuery } from './finder-actions'
import { request } from './loaders'
import { moveCell, moveSelection, openEditTarget, openFile, openFilter, openPeeked, openRelated, openRow, openStat, showAllFiles, submitFilter, toggleUntested, typeFilter, unfoldCycle } from './navigate-actions'
import { askFlag, askMove, askRemove, confirm as confirmNotes, confirmMarked, editMarked, openNotesFilter, submitNotesFilter, submitReason, typeNotesFilter, typeReason } from './notes-actions'
import { say } from './port'
import type { Port } from './port'
import { currentInput } from './input'
import type { Handlers } from './input'
import { mod } from './state'
import { requestRescan } from './watcher'
import type { RenderSurface } from 'claude-code'

/** Copies a command the band offers (the allow-root command it could only name part of). */
export async function copyCommand(io: Port, command: string, surface?: RenderSurface): Promise<void> {
  const copied = await io.ui.copy({ text: command, ...(surface === undefined ? {} : { surface }) })
  io.ui.toast(copied.isCopied ? `Copied ${command}` : `Could not copy the command: ${copied.reason ?? 'the surface refused'}`)
}

/** Copies the command for the tests that reach this session's changes. */
async function copyTestCommand(io: Port, surface?: RenderSurface): Promise<void> {
  const input = await currentInput(io, true)
  const command = input?.changes.command ?? null
  if (command === null) return
  const copied = await io.ui.copy({ text: command, ...(surface === undefined ? {} : { surface }) })
  const tests = input?.changes.tests.length ?? 0
  await say(io, copied.isCopied ? `✓ copied the command for ${tests === 1 ? '1 test' : `${tests} tests`}` : '✗ the surface refused the copy', copied.isCopied ? 'ok' : 'alert')
}

/** Copies the marked row's canonical name (a file's path) onto the clipboard of the surface the press came from. */
async function copySubject(io: Port, surface?: RenderSurface): Promise<void> {
  const input = await currentInput(io, true)
  const subject = input === null ? null : subjectOf(input)
  if (subject === null) return
  // A cycle copies its chain, said by its name in the toast; anything else its full name.
  const text = subject.copy ?? subject.canonical
  const copied = await io.ui.copy({ text, ...(surface === undefined ? {} : { surface }) })
  await say(io, copied.isCopied ? `✓ copied ${subject.name}` : '✗ the surface refused the copy', copied.isCopied ? 'ok' : 'alert')
}

/**
 * "Ask Claude" about the marked component (a cycle: how to break it; a
 * boundary: what its dependencies are for). One of the two prompts the mod
 * submits, both only from the person's press (the other is the no-data
 * pane's scan, {@link askScan}): the person asked for it, so it does not
 * start a turn on the mod's own account. One press, one prompt.
 */
async function askClaude(io: Port): Promise<void> {
  const input = await currentInput(io, true)
  const subject = input === null ? null : subjectOf(input)
  if (subject !== null) await io.prompt.submit({ text: subject.ask ?? askPrompt(subject.canonical) })
}

/**
 * The no-data pane's `q`: asks Claude to scan the project, as "Ask Claude"
 * does, only on the person's press, and only while knossos says the
 * project was never scanned. A press that lands after the pane moved on (a
 * load started, or one failed) sends nothing.
 */
async function askScan(io: Port): Promise<void> {
  const d = await io.state.dashboard.read()
  if (d?.status === 'unscanned' && noGraphOf(d, await io.state.refresh.read()) === 'unscanned') await io.prompt.submit({ text: SCAN_PROMPT })
}

/** What a press on the pane does, then the couplings of the cell it leaves marked, when it marks one. */
async function pressPane(io: Port, id: string, surface?: RenderSurface): Promise<void> {
  await pressAction(io, id, surface)
  await request(io, 'couplings')
  await request(io, 'branch')
  await request(io, 'peek')
  await request(io, 'churn')
  await request(io, 'notes')
}

/** What one press does: `rest` is what follows the colon of a prefixed id (`row:3` gives `3`), `id` the whole id. */
type Press = (io: Port, rest: string, surface: RenderSurface | undefined, id: string) => unknown

/** A view change a press makes, as a press. */
const viewing = (change: (v: KnossosView, rest: string) => KnossosView): Press => (io, rest) => io.state.view.update(v => change(v, rest))

/**
 * What a press on the pane does, by the pressed element's id: an id that
 * names an action outright (`find`), or the part of one before its first
 * colon, the colon kept (`row:`), for an id that carries what it acts on.
 * No outright id holds a colon, so the two never meet.
 */
export const PRESSES: ReadonlyMap<string, Press> = new Map<string, Press>([
  [
    'tab:',
    async (io, rest) => {
      const tab = rest as PaneTab
      // The model writes notes through MCP, which moves no snapshot: entering the tab reads them again.
      if (tab === 'notes') mod.notes.version += 1
      if (TABS.some(t => t.id === tab)) await io.state.view.update(v => ({ ...v, tab, selected: 0, filtering: false, finding: false, drift: false, target: undefined, degree: null }))
    },
  ],
  ['file:', (io, rest) => openFile(io, rest)],
  ['find', io => openFinder(io)],
  ['find-close', io => closeFinder(io)],
  ['target', io => moveCell(io, null)],
  ['cell:', (io, rest) => moveCell(io, Number(rest))],
  ['drift', viewing(v => ({ ...v, drift: v.drift !== true, selected: 0 }))],
  [
    'row:',
    async (io, rest) => {
      // A boundary has nothing to open: pressing one marks it, starting on what it depends on most.
      if ((await io.state.view.read()).tab === 'boundaries') await io.state.view.update(v => ({ ...v, target: undefined }))
      return openRow(io, Number(rest))
    },
  ],
  ['rel:', (io, rest) => openRelated(io, Number(rest))],
  ['route', io => startRoute(io)],
  ['route-pick:', viewing((v, rest) => (v.route === null || v.route === undefined ? v : { ...v, route: { ...v.route, index: Math.max(0, Number(rest) || 0) }, selected: 0 }))],
  ['note', io => startNote(io)],
  ['note-yes', io => confirmNote(io)],
  ['note-no', io => io.state.note.update(() => null)],
  ['peek:', (io, rest) => openPeeked(io, Number(rest))],
  // On Cycles the layout names the row `j` and `k` (and a cycle's line in the list) move to: what the diagram shows depends on its width.
  ['next:', viewing((v, rest) => ({ ...v, selected: Math.max(0, Number(rest) || 0) }))],
  ['unfold:', (io, _, __, id) => unfoldCycle(io, id)],
  ['down', io => moveSelection(io, 1)],
  ['up', io => moveSelection(io, -1)],
  ['open', io => openOrMove(io)],
  ['edit', (io, _, surface) => openEditTarget(io, surface)],
  ['tests', (io, _, surface) => copyTestCommand(io, surface)],
  // The detail's diff: a folded hunk shown whole, the page of hunks moved, and from the panel beside a tab the full detail.
  ['diff-more:', (io, rest) => openHunk(io, Number(rest))],
  ['diff-from:', (io, rest) => pageDiff(io, Number(rest))],
  ['diff-open', io => openRow(io)],
  // The changed files no test reaches: listed alone (`u`, or a press on their count), and every file again.
  ['untested', io => toggleUntested(io)],
  ['untested-all', io => showAllFiles(io)],
  ['stat:', (io, rest) => openStat(io, rest)],
  // The line under a cut list: the marker onto the item it names, and the window with it. A boundary marked so starts on what it depends on most.
  ['more:', viewing((v, rest) => (Number.isInteger(Number(rest)) && rest !== '' && Number(rest) >= 0 ? { ...v, selected: Number(rest), target: undefined } : v))],
  // Back from a route goes to the detail it was picked from; from a detail, to the tab.
  ['back', viewing(v => ((v.route ?? null) !== null ? { ...v, route: null, selected: 0 } : { ...v, inspect: null, selected: v.opened ?? 0 }))],
  ['keys', viewing(v => ({ ...v, showKeys: !v.showKeys }))],
  ['keys-close', viewing(v => ({ ...v, showKeys: false }))],
  ['filter', io => openFilter(io)],
  ['notes-narrow', io => openNotesFilter(io)],
  ['notes-filter', viewing(v => ({ ...v, notesFilter: nextFilter(v.notesFilter ?? 'all'), selected: 0 }))],
  ['notes-clear', viewing(v => ({ ...v, notesFilter: 'all', notesText: '', filtering: false, selected: 0 }))],
  ['notes-edit', io => editMarked(io)],
  ['notes-remove', io => askRemove(io)],
  ['notes-flag', io => askFlag(io)],
  ['notes-confirm', io => confirmMarked(io)],
  ['notes-yes', io => confirmNotes(io)],
  ['notes-no', viewing(v => ({ ...v, notesAsk: null }))],
  ['notes-move:', (io, rest) => askMove(io, rest)],
  // A note on a missing name the finder found: the Notes tab, on the missing notes, narrowed to its name.
  ['finder-note:', viewing((v, rest) => ({ ...v, finding: false, picking: null, inspect: null, route: null, tab: 'notes', notesFilter: 'missing', notesText: rest, filtering: false, selected: 0 }))],
  ['clear', viewing(v => ({ ...v, filter: '', filtering: false, degree: null, selected: 0 }))],
  ['sort', viewing(v => ({ ...v, sort: SORTS[(SORTS.indexOf(v.sort) + 1) % SORTS.length] ?? 'in', selected: 0 }))],
  ['rescan', io => requestRescan(io)],
  ['copy', (io, _, surface) => copySubject(io, surface)],
  ['ask', io => askClaude(io)],
  // The no-data pane's one action: the person's press sends it, as "Ask Claude" does.
  ['scan-ask', io => askScan(io)],
  ['allow', io => offerAllow(io)],
  ['allow-yes', io => confirmAllow(io)],
  ['allow-no', io => io.state.allow.update((a): AllowState => (a.phase === 'confirming' ? { phase: 'idle', root: null, reason: null } : a))],
])

/**
 * Ids that do what another does: a tab's hidden hotkey twin, the drift line's
 * second button, the Cycles moves and folds, the untested count beside `u`,
 * the notes card's button beside the footer's `m`, and the empty pane's
 * `scan it` outside Claude Code, which is the rescan the pane's `r` runs.
 */
const SAME_AS: Readonly<Record<string, string>> = { 'tabkey:': 'tab:', drifted: 'drift', 'prev:': 'next:', 'mark:': 'next:', 'fold:': 'unfold:', 'untested-row': 'untested', 'untested-none': 'untested', 'note-card': 'note', scan: 'rescan' }

/** Which entry of {@link PRESSES} a press on `id` runs, and what follows the colon; null for an id that does nothing. */
export function pressOf(id: string): { key: string; rest: string } | null {
  const cut = id.indexOf(':')
  const named = cut < 0 ? id : id.slice(0, cut + 1)
  const key = Object.hasOwn(SAME_AS, named) ? SAME_AS[named]! : named
  return PRESSES.has(key) ? { key, rest: cut < 0 ? '' : id.slice(cut + 1) } : null
}

/** What a press on the pane does, by the pressed element's id; `surface` is where the press came from. */
async function pressAction(io: Port, id: string, surface?: RenderSurface): Promise<unknown> {
  const press = pressOf(id)
  if (press === null) return undefined
  // The key list stands in for the whole pane: any other press (a tab, find, a row) closes it first.
  if (press.key !== 'keys' && press.key !== 'keys-close' && (await io.state.view.read()).showKeys) await io.state.view.update(v => ({ ...v, showKeys: false }))
  return PRESSES.get(press.key)!(io, press.rest, surface, id)
}

/** Into the field `field` (the finder's, a note's, the hubs filter's, the Notes tab's): what was typed, kept at once. */
function typeInto(io: Port, field: string, text: string): Promise<void> {
  return field === 'find' ? typeQuery(io, text) : field === 'note' ? typeNote(io, text) : field === 'notes-filter' ? typeNotesFilter(io, text) : field === 'notes-reason' ? typeReason(io, text) : typeFilter(io, text)
}

/** Enter in the field `field`. */
function submitFrom(io: Port, field: string, text: string): Promise<void> {
  return field === 'find' ? submitQuery(io, text) : field === 'note' ? submitNote(io, text) : field === 'notes-filter' ? submitNotesFilter(io, text) : field === 'notes-reason' ? submitReason(io, text) : submitFilter(io, text)
}

/** What the pane's elements do, for the pane drawn through `io`: each action's failure (a teardown under it) is swallowed. */
export function handlersOf(io: Port): Handlers {
  return {
    press: (id, surface) => void pressPane(io, id, surface).catch(() => undefined),
    type: (field, value) => void typeInto(io, field, value).catch(() => undefined),
    submit: (field, value) => void submitFrom(io, field, value).catch(() => undefined),
  }
}
