/**
 * What the presses that move about the pane do: the marker, opening a row,
 * a file, a place in the editor or a related component, the hubs filter,
 * the boundaries' cells, a cycle's fold, the untested files, a figure's list,
 * a route drawn, and where the focus ring and the wheel go.
 */
import type { Inspected, KnossosView, PaneTab } from '../../types'
import { wheelMove } from '../lib/cards'
import { nextTarget } from '../lib/boundaries'
import { TABS } from '../lib/chrome'
import { editorCommand } from '../lib/editor'
import { editTarget, peekList } from '../lib/pane-list'
import { locText } from '../lib/paths'
import { issuesInput, issuesWalk } from '../lib/views'
import type { Loc, Row } from '../lib/types'
import { currentInput, currentList } from './input'
import { request, showComponent } from './loaders'
import { narrowing } from './field-actions'
import { askMove } from './notes-actions'
import { say } from './port'
import type { Port } from './port'
import { mod } from './state'
import type { ProcessRunResult, RenderSurface } from 'claude-code'

/** The editor command and the focus ring, which only these presses keep. */
const { navigation } = mod

/** How long the editor's command may take to hand a file to the editor. */
const EDITOR_TIMEOUT_MS = 10_000

/** Where the person sets the editor command, by host: in Claude Code what the installer itself tells them to run, on the web the server's flag or variable. */
const EDITOR_SETTING = { claude: '/plugin configure knossos@knossos', web: '`knossos web --editor=<command>` or KNOSSOS_EDITOR' } as const

/** Moves the selection marker by `by` rows, kept inside the list. */
export async function moveSelection(io: Port, by: number): Promise<void> {
  const length = (await currentList(io)).length
  // A boundary marked anew starts on what it depends on most.
  await io.state.view.update(v => ({ ...v, selected: Math.min(Math.max(0, v.selected + by), Math.max(0, length - 1)), target: undefined }))
}

/** Opens the row at `index` (the marker's when absent) and leaves the marker there: a component or a file as its detail. */
export async function openRow(io: Port, index?: number): Promise<void> {
  const at = index ?? (await io.state.view.read()).selected
  const item = (await currentList(io))[at]
  if (item === undefined) return
  // A match opened from the finder closes it: the detail opens over the tab, whose marker stays where it stood.
  if ((await io.state.view.read()).finding === true) {
    // Picking where a route ends: the match is that end, and the route is drawn.
    if (((await io.state.view.read()).picking ?? null) !== null) return showRoute(io, { name: item.canonical, label: item.name })
    // Picking where a note on a missing name moves: the Notes tab asks to move it there.
    if (((await io.state.view.read()).moving ?? null) !== null) return item.file === true ? undefined : askMove(io, item.canonical)
    mod.search.openWhenFound = false
    await io.state.view.update((v): KnossosView => ({ ...v, finding: false, selected: mod.search.findFrom }))
    return showComponent(io, { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}) })
  }
  await io.state.view.update(v => ({ ...v, selected: at }))
  // An Overview chart's bar opens the tab it counts: Hubs narrowed to a bucket, a flow's cell on Boundaries, Changes.
  const jump = item.jump
  if (jump !== undefined) {
    await io.state.view.update((v): KnossosView => ({ ...v, tab: jump.tab, selected: jump.selected ?? 0, degree: jump.degree ?? null, filtering: false, drift: false, target: jump.target, ...(jump.notesFilter === undefined ? {} : { notesFilter: jump.notesFilter, notesText: '' }) }))
    return
  }
  // A row that heads a group (a Branch boundary) folds or unfolds it; the marker stays on it.
  const fold = item.fold
  if (fold !== undefined) {
    await io.state.view.update((v): KnossosView => {
      const open = (v.folds ?? []).filter(f => f !== item.contrary)
      return { ...v, folds: open.includes(fold) ? open.filter(f => f !== fold) : [...open, fold] }
    })
    return
  }
  // A boundary opens nothing: marking it is what spells it out.
  if (item.inert === true) return
  await showComponent(io, { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}), ...(item.changed === true ? { changed: true } : {}) })
}

/**
 * Opens a place in the person's editor (see {@link EDITOR}). Where no editor
 * command answers, `path:line` goes onto the clipboard of the surface the
 * press came from instead, and a toast says which happened.
 */
async function openLocation(io: Port, loc: Loc, surface?: RenderSurface): Promise<void> {
  const target = locText(loc)
  // The plugin's `editor` setting (on the web the server's), VS Code's `code -g` by default. A terminal editor
  // (`$EDITOR`) needs a terminal of its own, which a press cannot give, so it is not asked.
  const command = editorCommand(navigation.editor, loc)
  const failed = await io.process.run(command, { timeoutMs: EDITOR_TIMEOUT_MS }).then(
    (r: ProcessRunResult) => (r.exitCode === 0 ? null : `exited ${r.exitCode}`),
    () => 'did not start',
  )
  if (failed === null) return say(io, '✓ opened in editor', 'ok')
  const copied = await io.ui.copy({ text: target, ...(surface === undefined ? {} : { surface }) })
  const program = command[0]!.split('/').pop()!
  await say(io, `✗ ${program} ${failed}${copied.isCopied ? ' · path copied' : ''}`, 'alert')
  io.ui.toast(`knossos ran "${command.join(' ')}" to open the file. Set another editor command with ${EDITOR_SETTING[mod.session.host]}.`)
}

/** A press on a place (`file:` and the file's absolute path): the detail of that file, named by its path in the project. */
export async function openFile(io: Port, path: string): Promise<void> {
  const root = (await io.state.dashboard.read())?.project_root ?? null
  if (root === null || !path.startsWith(`${root}/`)) return
  const relative = path.slice(root.length + 1)
  await showComponent(io, { name: relative, label: relative, file: true })
}

/** `e`: opens the file of what the detail shows, else of the marked row, whatever list the marker is in. */
export async function openEditTarget(io: Port, surface?: RenderSurface): Promise<void> {
  const input = await currentInput(io, true)
  const loc = input === null ? null : editTarget(input)
  if (loc !== null) await openLocation(io, loc, surface)
}

/** Opens a component the detail lists (who uses it, what it uses): the detail moves to that one. */
export async function openRelated(io: Port, index: number): Promise<void> {
  const item = (await currentList(io))[index]
  if (item !== undefined) await showComponent(io, { name: item.canonical, label: item.name })
}

/** The hubs filter's field, narrowing the list in place as it is typed. */
const HUBS_FILTER = narrowing('hubs', 'filter', 'filter')
/** Opens the hubs filter's field and moves the focus into it once it is drawn. */
export const openFilter = HUBS_FILTER.open
/** The filter as typed, applied at once; the marker goes back to the top of the shorter list. */
export const typeFilter = HUBS_FILTER.type
/** Enter in the filter field: keep the text (an empty one clears the filter) and close the field. */
export const submitFilter = HUBS_FILTER.submit

/**
 * Where a focus ring headed for a tab's hidden hotkey twin (`tabkey:<id>`)
 * goes instead: onto its visible tab, or, when it comes from that tab (a
 * backward walk, as the twin sits just before it), onto the tab before; null
 * keeps it where it is (backward past the first tab). Undefined for any
 * other element.
 */
export function twinTarget(element: string): string | null | undefined {
  if (!element.startsWith('tabkey:')) return undefined
  const id = element.slice('tabkey:'.length)
  if (navigation.focused !== `tab:${id}`) return `tab:${id}`
  const before = TABS[TABS.findIndex(t => t.id === id) - 1]
  return before === undefined ? null : `tab:${before.id}`
}

/**
 * A focus move the engine asked for, before it is made: the twin key's redirect
 * (`to`, the element to move the focus to instead), a refusal (`refuse`, the
 * move to make is none), or null to let the focus stand. Notes the element the
 * focus is on.
 */
export async function focusMoved(_io: Port, asked: unknown): Promise<{ to: string } | { refuse: true } | null> {
  const twin = typeof asked === 'string' ? twinTarget(asked) : undefined
  if (twin === null) return { refuse: true }
  const element = twin ?? asked
  navigation.focused = typeof element === 'string' ? element : null
  return twin === undefined ? null : { to: twin }
}

/** The focus has landed on `element`: a listed row moves the marker with it, and the lookups that follow the marker are asked for. */
export async function focusLanded(io: Port, element: string): Promise<void> {
  const index = element.startsWith('row:') ? Number(element.slice(4)) : Number.NaN
  if (!Number.isInteger(index)) return
  await io.state.view.update(v => (v.selected === index ? v : { ...v, selected: index, target: undefined }))
  await request(io, 'couplings')
  await request(io, 'peek')
}

/** The wheel over a list longer than its window scrolls that list, the marker left where it is; false when the pointer is over none, or at its end. */
export async function wheelScrolled(io: Port, rows: readonly Row[], offset: number, pointer: { row: number; column: number }, by: number): Promise<boolean> {
  const moved = wheelMove(rows, offset, pointer, by)
  if (moved === null) return false
  await io.state.view.update(v => ({ ...v, scrolls: { ...(v.scrolls ?? {}), [moved.key]: moved.start } }))
  return true
}

/** A press in the detail beside the tab (`peek:N`): opens what it lists as the detail itself. */
export async function openPeeked(io: Port, index: number): Promise<void> {
  const input = await currentInput(io, true)
  const item = input?.peek === null || input?.peek === undefined ? undefined : peekList(input.peek)[index]
  if (item !== undefined) await showComponent(io, { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}) })
}

/** The route from the component picked with `p` to `to`, drawn instead of the detail; `b` goes back to it. */
export async function showRoute(io: Port, to: Inspected): Promise<void> {
  const v = await io.state.view.read()
  const from = v.picking ?? null
  if (from === null) return
  mod.search.openWhenFound = false
  await io.state.view.update((w): KnossosView => ({ ...w, finding: false, picking: null, selected: 0, route: { from, to, index: 0, back: w.inspect } }))
  await request(io, 'route')
}

/** `l`, or a press on one of what the marked boundary depends on: the heat map cell moves to that column. */
export async function moveCell(io: Port, to: number | null): Promise<void> {
  const input = await currentInput(io, true)
  const boundaries = input?.boundaries ?? null
  if (input === null || boundaries === null) return
  const marked = Math.min(Math.max(0, input.selected), boundaries.boundaries.length - 1)
  const target = to === null ? nextTarget(boundaries, marked, input.target) : (boundaries.boundaries[to]?.name ?? null)
  if (target !== null) await io.state.view.update(v => ({ ...v, target }))
}

/** `o` on a cycle's fold (`unfold:<cycle>:<row>`), or a press on its box (`fold:…`): the cycle shows every member, the marker on the first it hid. */
export async function unfoldCycle(io: Port, id: string): Promise<void> {
  const [cycle, row] = id.slice(id.indexOf(':') + 1).split(':').map(Number)
  if (!Number.isInteger(cycle) || !Number.isInteger(row)) return
  await io.state.view.update(v => ({ ...v, unfolded: [...new Set([...(v.unfolded ?? []), cycle!])], selected: row! }))
}

/**
 * The count of changed files no test reaches, or `u`: on Changes it lists
 * only those files, the marker on the first, and pressed again lists every
 * file; anywhere else (the Overview's session card) it opens Changes with
 * the list narrowed so.
 */
export async function toggleUntested(io: Port): Promise<void> {
  const v = await io.state.view.read()
  const onChanges = v.tab === 'changes' && v.inspect === null && (v.route ?? null) === null && v.finding !== true
  if (onChanges && (await io.state.untestedOnly.read())) return showAllFiles(io)
  await io.state.untestedOnly.update(() => true)
  await io.state.view.update((w): KnossosView => ({ ...w, tab: 'changes', inspect: null, route: null, selected: 0, filtering: false, finding: false, drift: false, target: undefined, degree: null }))
}

/** Every changed file listed again, the marker kept on the file it was on. */
export async function showAllFiles(io: Port): Promise<void> {
  if (!(await io.state.untestedOnly.read())) return
  const marked = (await currentList(io))[(await io.state.view.read()).selected]?.canonical
  await io.state.untestedOnly.update(() => false)
  const at = marked === undefined ? -1 : (await currentList(io)).findIndex(item => item.canonical === marked)
  await io.state.view.update(v => ({ ...v, selected: Math.max(0, at) }))
}

/** Where each figure that counts a set is listed: its tab, and the place of its first item there (null: the top). */
const STAT_LISTS: Readonly<Record<string, PaneTab>> = { cycles: 'cycles', components: 'hubs', boundaries: 'boundaries', boundary: 'boundaries', issues: 'issues', dead: 'issues', policy: 'issues', diagnostics: 'issues' }

/**
 * A figure that counts a set (an Overview tile, the violations a session
 * introduced): opens the tab that lists it, the marker on its first item.
 * On Issues the diagnostics follow the policy violations in the walk, and
 * the dead code follows them.
 */
export async function openStat(io: Port, key: string): Promise<void> {
  if (!Object.hasOwn(STAT_LISTS, key)) return
  const tab = STAT_LISTS[key]!
  const d = await io.state.dashboard.read()
  // Where the list starts in the Issues walk. A count with nothing listed under it opens the tab at its top.
  const walk = d?.status === 'ok' && (key === 'diagnostics' || key === 'dead') ? issuesWalk(issuesInput(d)) : null
  const part = walk === null ? null : key === 'diagnostics' ? walk.diagnostics : walk.dead
  const selected = part !== null && part.length > 0 ? part.offset : 0
  await io.state.view.update((v): KnossosView => ({ ...v, tab, inspect: null, route: null, selected, filtering: false, finding: false, drift: false, target: undefined, degree: null }))
}
