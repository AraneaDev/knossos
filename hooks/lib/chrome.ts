/**
 * The pane's chrome: the header's title row and tab bar, the footer bar of
 * keys, and the key list `h` opens. Pure, like the rest of the layout.
 */
import type { GitHead, PaneTab } from '../../types'
import { markedCell } from './boundaries'
import { ACCENT, CHIP_BG, HEADING, languageColour, ON_FILL, STATUS_COLOURS } from './palette'
import { button, clip, rowWidth, segmentsWidth, spread, tinted, wrapGroups } from './rows'
import { tierOf } from './table'
import { cells, fit, padEnd, spaces, wrapWords } from './text'
import type { Row, Segment } from './types'
import type { Tier } from './table'
import { baselineWords } from './baseline'
import { widthGauge } from './gauge'
import { confirmable, markedNote } from './notes-view'
import { cycleSteps, unfoldPress } from './cycles'
import { noteFooterRows } from './views'
import { cardInner } from './cards'
import type { Block } from './cards'
import type { NoteInput } from './views'
import type { PaneInput } from './pane-types'
import type { PaneStatus } from './pane-status'
import { listFor, subjectOf, editTarget } from './pane-list'

/** The tabs in hotkey order; each new one came last, so the older tabs keep their digits. */
export const TABS: { id: PaneTab; full: string; hotkey: string }[] = [
  { id: 'overview', full: 'Overview', hotkey: '1' },
  { id: 'hubs', full: 'Hubs', hotkey: '2' },
  { id: 'boundaries', full: 'Boundaries', hotkey: '3' },
  { id: 'cycles', full: 'Cycles', hotkey: '4' },
  { id: 'issues', full: 'Issues', hotkey: '5' },
  { id: 'changes', full: 'Changes', hotkey: '6' },
  { id: 'branch', full: 'Branch', hotkey: '7' },
  { id: 'churn', full: 'Churn', hotkey: '8' },
  { id: 'notes', full: 'Notes', hotkey: '9' },
]

/** The active tab's ground: the theme's selection colour, under which a plain Button's own text stays legible in every theme. */
export const TAB_BG = 'selectionBg'

/** Where the checkout stands, as the header names it: the branch and the short commit, or the commit alone on a detached head. */
export function gitLabel(git: GitHead | undefined): string {
  if (git === undefined || git === null) return ''
  const short = git.rev.slice(0, 7)
  return git.branch === null ? `@ ${short}` : `${git.branch} · ${short}`
}

/** The status as a pill: the words on the status colour, in the text set on a fill. No dot: ● means probably dead. */
function pill(status: PaneStatus, pulse = ''): Segment {
  return { text: ` ${pulse === '' ? '' : `${pulse} `}${status.text} `, bg: STATUS_COLOURS[status.tone], color: ON_FILL, bold: true }
}

/**
 * The header's first row. On a tab: the project's name in bold, where the
 * checkout stands (branch and short commit, dim; nothing without git) and
 * the languages as small chips, then against the right edge why the status
 * is what it is, the status as a pill in its colour and the rescan action
 * whenever no scan is running. In a detail the name becomes the way
 * back: `project › Tab › what is shown`. Beside the pill, the width gauge
 * (see `gauge.ts`): the first thing given up, from its slider to its short
 * form to nothing. As the width shrinks the chips go
 * next, then the commit, the branch and the reason; then the name is cut,
 * never below four cells, then the pill's words. Narrow, the name, the
 * pill and, while it fits, why.
 */
export function titleRow(input: PaneInput, columns: number, tier: Tier, paneColumns = columns): Row {
  const rescan: Segment[] = input.canRescan ? [{ text: '  ' }, button('rescan', 'rescan', 'r', { dim: false })] : []
  // Where the header is short of room the label goes before the reason does;
  // the hotkey stays, on a twin drawn out of sight, as a tab's digit does.
  const rescanKey: Segment[] = input.canRescan ? [{ text: '', hidden: true, press: { id: 'rescan', label: 'rescan', hotkey: 'r' } }] : []
  const note: Segment[] = input.status.note === undefined ? [] : [{ text: `${input.status.note}  `, dim: true }]
  const name: Segment = { text: input.project, bold: true, color: HEADING }
  const crumbs: Segment[] =
    input.route !== null
      ? [name, { text: ' › ', dim: true }, { text: 'Route', dim: true }, { text: ' › ', dim: true }, { text: `${input.route.from} → ${input.route.to}`, bold: true, color: HEADING }]
      : input.detail === null
        ? [name]
        : [name, { text: ' › ', dim: true }, { text: TABS.find(t => t.id === input.tab)?.full ?? '', dim: true }, { text: ' › ', dim: true }, { text: input.detail.label, bold: true, color: HEADING }]
  const git = gitLabel(input.git)
  const where: Segment[] = git === '' || tier === 'narrow' ? [] : [{ text: `  ${git}`, dim: true }]
  const away = input.detail !== null || input.route !== null
  const chips: Segment[] =
    tier === 'narrow' || away || input.languages === '' ? [] : input.languages.split(' ').flatMap((l): Segment[] => [{ text: ' ' }, { text: ` ${l} `, color: languageColour(l), bg: CHIP_BG }])
  const chipsLead: Segment[] = chips.length === 0 ? [] : [{ text: ' ' }, ...chips]
  // How wide the pane is against the layouts' bounds, full or short: the first thing the header gives up.
  const gauge = (room: number): Segment[] => {
    // The pane's own width, which the layout goes by, not the title's padded one.
    const drawn = widthGauge(paneColumns, room)
    return drawn.length === 0 ? [] : [...drawn, { text: '   ' }]
  }
  // Each layout from the fullest down; the first that fits wins.
  const variants: [Segment[], Segment[]][] = [
    [[...crumbs, ...(away ? [] : where), ...chipsLead], [...gauge(Infinity), ...note, pill(input.status, input.pulse), ...rescan]],
    [[...crumbs, ...(away ? [] : where), ...chipsLead], [...gauge(14), ...note, pill(input.status, input.pulse), ...rescan]],
    [[...crumbs, ...(away ? [] : where), ...chipsLead], [...note, pill(input.status, input.pulse), ...rescan]],
    [[...crumbs, ...(away ? [] : where)], [...note, pill(input.status, input.pulse), ...rescan]],
    [[...crumbs, ...(!away && input.git?.branch ? [{ text: `  ${input.git.branch}`, dim: true }] : [])], [...note, pill(input.status, input.pulse), ...rescan]],
    [crumbs, [...note, pill(input.status, input.pulse), ...rescan]],
    [crumbs, [...note, pill(input.status, input.pulse), ...rescanKey]],
    [crumbs, [pill(input.status, input.pulse), ...rescan]],
    [crumbs, [pill(input.status, input.pulse), ...rescanKey]],
  ]
  for (const [left, right] of variants) {
    if (segmentsWidth(left) + 1 + segmentsWidth(right) <= columns) return spread('title', left, right, columns)
  }
  // Nothing fits whole: the right side first, then the way back cut from its middle, then the pill's words cut.
  // The rescan label is the first thing given up; its hotkey stays.
  const right: Segment[] = [pill(input.status, input.pulse), ...rescanKey]
  const room = columns - 1 - segmentsWidth(right)
  if (room >= Math.min(4, cells(input.project))) return spread('title', cutCrumbs(crumbs, room), right, columns)
  const keep = Math.max(0, columns - 1 - Math.min(4, cells(input.project)))
  const shortPill: Segment = { ...pill(input.status, input.pulse), text: fit(pill(input.status, input.pulse).text, keep) }
  const left = cutCrumbs(crumbs, Math.max(0, columns - 1 - cells(shortPill.text)))
  return spread('title', left, keep >= 4 ? [shortPill, ...rescanKey] : rescanKey, columns)
}

/** The header's left side cut to `room`: a detail's label first, then the tab between, then the project's name. */
function cutCrumbs(crumbs: Segment[], room: number): Segment[] {
  if (segmentsWidth(crumbs) <= room) return crumbs
  if (crumbs.length > 1) {
    const label = crumbs[crumbs.length - 1]!
    const head = [crumbs[0]!, { text: ' › ', dim: true }]
    const cut = room - segmentsWidth(head)
    if (cut >= 6) return [...head, { ...label, text: fit(label.text, cut) }]
  }
  return [{ ...crumbs[0]!, text: fit(crumbs[0]!.text, Math.max(0, room)) }]
}

/**
 * The tab bar: every tab by its name with its count as a superscript badge
 * (`Issues²`), the open one on {@link TAB_BG}, a cell of room either side of
 * each; where the names do not fit, and always when `narrow`, the others by
 * their digit (the open one keeps its name), then with no room between.
 * The digits that switch tabs are said in the `h` help, not on the bar.
 *
 * Every tab's hotkey rides on a hidden twin (`tabkey:<id>`, no text) just
 * before it: a Button with a hotkey always draws `N: label`. The pane's focus
 * hook moves a ring landing on a twin onto its visible tab.
 */
export function tabRows(active: PaneTab, columns: number, terminal: boolean, badges: Partial<Record<PaneTab, string>> = {}, narrow = false): Row[] {
  type Tab = (typeof TABS)[number]
  const full = (t: Tab) => `${t.full}${badges[t.id] ?? ''}`
  const digit = (t: Tab) => `${t.hotkey}${badges[t.id] ?? ''}`
  const variants: [(t: Tab) => boolean, number][] = [
    ...(narrow ? [] : [[() => true, 2] as [(t: Tab) => boolean, number], [() => true, 1] as [(t: Tab) => boolean, number]]),
    [t => t.id === active, 2],
    [t => t.id === active, 1],
    [t => t.id === active, 0],
    [() => false, 0],
  ]
  const label = (t: Tab, named: (t: Tab) => boolean) => ` ${named(t) ? full(t) : digit(t)} `
  const width = (named: (t: Tab) => boolean, gap: number) => TABS.reduce((n, t) => n + cells(label(t, named)), 0) + gap * (TABS.length - 1)
  const [named, gap] = variants.find(([l, g]) => width(l, g) <= columns) ?? variants[variants.length - 1]!
  const segments: Segment[] = []
  TABS.forEach((t, i) => {
    if (i > 0 && gap > 0) segments.push({ text: spaces(gap) })
    const on = t.id === active
    segments.push({ text: '', hidden: true, press: { id: `tabkey:${t.id}`, label: t.full, hotkey: t.hotkey } }, button(`tab:${t.id}`, label(t, named), undefined, on ? { bg: TAB_BG } : { dim: true }))
  })
  // Elsewhere tabs are native buttons whose widths the pane cannot know: the bar is the same row.
  void terminal
  return [{ key: 'tabs', segments: clip(segments, columns) }]
}

/** The keys and what each does, for the key list. */
const KEY_HELP: [string, string][] = [
  [`1–${TABS.length}`, 'switch tabs (or click one)'],
  ['j k', 'move the marker (or Tab, or a click)'],
  ['o', 'open the marked row: a component or a file shows what depends on it, an Overview bar the tab it counts'],
  ['e', "open the marked row's file in your editor"],
  ['b', 'back from a detail'],
  ['c', "copy the marked row's full name or path"],
  ['q', 'ask Claude about the marked row: your press sends the prompt'],
  ['d', 'list the files drifted since the snapshot, or hide them'],
  ['u', 'on Overview and Changes: list only the changed files no test reaches, or every file again'],
  ['l', "on Boundaries: move the marked cell to the next boundary the marked one depends on, and spell it out"],
  ['f', 'find any component or file by the letters of its name; Enter opens the first match, x closes the finder'],
  ['p', "in a component's detail: pick another component in the finder and draw the route between them"],
  ['m', "in a component's detail: add a note to it; knossos checks it first, and y records it"],
  ['n s x', 'on Hubs: narrow the list (type, then Enter), sort by in, out or cross, clear the narrowing or the in-degree range'],
  ['n s x', "on Notes: narrow by text (type, then Enter), show every note, yours, the model's, the stale, the missing or the flagged, clear both"],
  ['m w g', "on Notes: edit your note or adopt the model's as yours, remove a note (asks first), flag the model's note for it to check (or take the flag back)"],
  ['v', "on Notes: say a stale note of the model's is still true, so it rests on its files as they are now"],
  ['r', 'rescan the project now, fresh or not'],
  ['a', 'allow a refused root (asks first)'],
]

const KEY_WIDTH = Math.max(...KEY_HELP.map(([k]) => cells(k))) + 2

/** How long the footer's word after an action stays, in milliseconds. */
export const FEEDBACK_MS = 2_000

/** The footer bar's ground: a faint fill across the pane, under the keys. */
export const BAR_BG = 'composerSidebarBackground'

/** The cells of room inside the header and the footer bar, either side, per tier. */
export const padOf = (tier: Tier): number => (tier === 'narrow' ? 1 : 2)

/** `row` set `pad` cells in from the left; with `bg`, that ground laid across the whole of `columns`, padding included. */
export function padded(row: Row, pad: number, columns: number, bg?: string): Row {
  const used = pad + rowWidth(row)
  const segments: Segment[] = [{ text: spaces(pad) }, ...row.segments, ...(bg !== undefined && used < columns ? [{ text: spaces(columns - used) }] : [])]
  return { ...row, segments: bg === undefined ? segments.filter(s => s.text !== '' || s.hidden === true) : tinted(segments, bg) }
}

/**
 * The footer bar: the keys, grouped, on a faint ground across the pane,
 * padded in from either edge. Moving about (the marker, back) dim on the
 * left, what can be done to the marked row on the right, and between them,
 * for a moment after an action, what it did (`✓ copied`, `✗ no editor`).
 * Once the header is scrolled out of view (`scrolled`), the graph's state
 * stands at the right edge (`live · 7s`). Only the keys that do something
 * in this view are offered. Each group stays whole; past the width the
 * actions wrap under the moves, and a key is never dropped, since it would
 * stop working. While the key list `h` opens is shown, the bar offers only
 * closing it.
 */
export function footerRows(input: PaneInput, columns: number, hasList: boolean, scrolled = false): { bar: Row[] } {
  const moves: Segment[] = []
  const actions: Segment[] = []
  if (input.showKeys) return keysFooter(input, columns, scrolled)
  if (input.finder !== null) return finderFooter(input, columns, hasList)
  const noting = input.detail?.note ?? null
  if (input.detail?.notable === true && input.detail.component !== null && noting !== null && noting.phase !== 'failed') return noteFooter(input.detail.component.name, noting, columns)
  const onTab = input.detail === null && input.route === null && !input.driftOpen
  if (input.detail !== null || input.route !== null) moves.push(button('back', 'back', 'b'))
  // On Cycles the marker walks the boxes the diagram draws, which only the width decides: the keys name the row they move to.
  const tier = tierOf(columns)
  const marked = Math.min(Math.max(0, input.selected), Math.max(0, listFor(input).length - 1))
  const steps = onTab && input.tab === 'cycles' ? cycleSteps(input.cycles, cardInner(columns, tier), tier, marked) : null
  const unfold = onTab && input.tab === 'cycles' ? unfoldPress(input.cycles, cardInner(columns, tier), tier, marked) : null
  if (hasList) moves.push(button(steps === null ? 'down' : `next:${steps.next}`, '↓', 'j'), button(steps === null ? 'up' : `prev:${steps.prev}`, '↑', 'k'))
  // A boundary has nothing to open: the marker only shows what it depends on. A cycle's fold opens into its members.
  if (unfold !== null) actions.push(button(unfold, 'unfold', 'o'))
  else if (hasList && !listFor(input).every(item => item.inert === true)) actions.push(button('open', 'open', 'o'))
  // The same keys on every list: `e` whenever the marked row has a file.
  if (editTarget(input) !== null) actions.push(button('edit', 'edit', 'e'))
  if (subjectOf(input) !== null) actions.push(button('copy', 'copy', 'c'), ...(input.host === 'web' ? [] : [button('ask', 'ask Claude', 'q')]))
  if (onTab && input.tab === 'boundaries' && input.boundaries !== null && markedCell(input.boundaries, input.selected, input.target) !== null) actions.push(button('target', 'next cell', 'l'))
  // On Overview `t` stands beside the tests it copies, in "This session".
  if (onTab && input.tab === 'changes' && input.changes.command !== null) actions.push(button('tests', 'copy test command', 't'))
  if (onTab && (input.tab === 'changes' || input.tab === 'overview') && input.changes.untested > 0) actions.push(button('untested', input.tab === 'changes' && input.changes.untestedOnly ? 'all files' : 'untested', 'u'))
  if (input.detail === null && input.drift !== null) actions.push(button('drift', input.driftOpen ? 'hide drifted' : 'drifted files', 'd'))
  if (onTab && input.tab === 'hubs') {
    actions.push(button('filter', 'narrow', 'n'), button('sort', `sort: ${input.sort}`, 's'))
    if (input.filter !== '' || input.degree !== null) actions.push(button('clear', 'clear', 'x'))
  }
  // While the card asks, its own y and n are the keys: nothing else on the tab may take them.
  if (onTab && input.tab === 'notes' && input.notes.ask === null) {
    // On a boundary's or a folder's row there is no note: o folds it, and nothing else applies.
    const note = markedNote(input.notes, input.selected) ?? undefined
    if (note !== undefined && !note.missing) actions.push(button('notes-edit', note.author === 'user' ? 'edit' : 'adopt', 'm'))
    if (note !== undefined) actions.push(button('notes-remove', 'remove', 'w'))
    if (note !== undefined && !note.missing && note.author === 'model') actions.push(button('notes-flag', note.checked ? 'unflag' : 'flag', 'g'))
    if (note !== undefined && confirmable(note)) actions.push(button('notes-confirm', 'still true', 'v'))
    actions.push(button('notes-narrow', 'narrow', 'n'), button('notes-filter', `show: ${input.notes.filter}`, 's'))
    if (input.notes.filter !== 'all' || input.notes.text !== '') actions.push(button('notes-clear', 'clear', 'x'))
  }
  // From a component's own detail, a route to any other component (the finder picks where it ends).
  if (input.route === null && input.detail?.component !== null && input.detail?.component !== undefined && input.detail.file === undefined) actions.push(button('route', 'route to…', 'p'))
  // A note on the component, from the footer: the notes card ends a long detail, so its own button may be scrolled away.
  if (input.route === null && input.detail?.notable === true && (noting === null || noting.phase === 'failed')) actions.push(button('note', 'note', 'm'))
  actions.push(button('find', 'find', 'f'), button('keys', 'keys', 'h'))
  const pad = padOf(tier)
  const inner = Math.max(1, columns - 2 * pad)
  const said: Segment[] = input.feedback === null ? [] : [{ text: input.feedback.text, color: input.feedback.tone === 'ok' ? STATUS_COLOURS.ok : STATUS_COLOURS.alert }]
  const state: Segment[] = scrolled ? [{ text: input.status.text, color: STATUS_COLOURS[input.status.tone] }] : []
  const left = joinGroups(moves.map(k => [{ ...k, dim: true }]))
  const right = joinGroups([...actions.map(k => [{ ...k, dim: true }]), ...(state.length > 0 ? [state] : [])])
  const lines: Row[] = []
  const middle = said.length === 0 ? [] : [{ text: '   ' }, ...said]
  if (segmentsWidth(left) + segmentsWidth(middle) + 2 + segmentsWidth(right) <= inner) {
    lines.push(spread('keys', [...left, ...middle], right, inner))
  } else {
    if (said.length > 0) lines.push({ key: 'keys-said', segments: clip(said, inner) })
    if (moves.length > 0) lines.push(...wrapGroups('keys', moves.map(k => [{ ...k, dim: true }]), inner))
    lines.push(...wrapGroups(moves.length > 0 ? 'keys-actions' : 'keys', [...actions.map(k => [{ ...k, dim: true }]), ...(state.length > 0 ? [state] : [])], inner))
  }
  return { bar: lines.map(row => padded(row, pad, columns, BAR_BG)) }
}

/**
 * The footer bar while the key list is shown: closing it, which puts back
 * what it stood in for, and the graph's state once the header is out of view.
 */
function keysFooter(input: PaneInput, columns: number, scrolled: boolean): { bar: Row[] } {
  const pad = padOf(tierOf(columns))
  const inner = Math.max(1, columns - 2 * pad)
  const state: Segment[] = scrolled ? [{ text: input.status.text, color: STATUS_COLOURS[input.status.tone] }] : []
  const actions = [button('keys-close', 'close', 'x'), button('keys', 'hide keys', 'h')].map(k => [{ ...k, dim: true }])
  const right = joinGroups([...actions, ...(state.length > 0 ? [state] : [])])
  const lines = segmentsWidth(right) <= inner ? [spread('keys', [], right, inner)] : wrapGroups('keys', [...actions, ...(state.length > 0 ? [state] : [])], inner)
  return { bar: lines.map(row => padded(row, pad, columns, BAR_BG)) }
}

/**
 * The footer bar while the finder is open: moving through the matches, and
 * opening the marked one, closing the finder and the key list. While the
 * field holds the focus the keys type into it: Enter opens the first match,
 * and a click opens any.
 */
function finderFooter(input: PaneInput, columns: number, hasList: boolean): { bar: Row[] } {
  const pad = padOf(tierOf(columns))
  const inner = Math.max(1, columns - 2 * pad)
  const moves = hasList ? [button('down', '↓', 'j'), button('up', '↑', 'k')] : []
  const actions = [...(hasList ? [button('open', 'open', 'o')] : []), button('find-close', 'close', 'x'), button('keys', 'keys', 'h')]
  const left = joinGroups(moves.map(k => [{ ...k, dim: true }]))
  const right = joinGroups(actions.map(k => [{ ...k, dim: true }]))
  const lines = segmentsWidth(left) + 2 + segmentsWidth(right) <= inner ? [spread('keys', left, right, inner)] : [...(moves.length > 0 ? wrapGroups('keys', moves.map(k => [{ ...k, dim: true }]), inner) : []), ...wrapGroups(moves.length > 0 ? 'keys-actions' : 'keys', actions.map(k => [{ ...k, dim: true }]), inner)]
  return { bar: lines.map(row => padded(row, pad, columns, BAR_BG)) }
}

/** The footer while a note is under way: the note's field or question in place of the keys, always on screen. */
function noteFooter(name: string, note: NoteInput, columns: number): { bar: Row[] } {
  const pad = padOf(tierOf(columns))
  return { bar: noteFooterRows(name, note, Math.max(1, columns - 2 * pad)).map(row => padded(row, pad, columns, BAR_BG)) }
}

/**
 * The key list `h` opens, as a card that stands in for the tab, the detail
 * or the finder while it is shown: one key a line, its action wrapped
 * beside it, a list to look up rather than a paragraph to read. Drawn under
 * the header, so nothing that lands later can push it out of view.
 */
export function keysBlock(host?: 'web'): Block {
  const lines = (columns: number): Row[] => {
    const help: Row[] = []
    let n = 0
    const keys = KEY_HELP.filter(([key]) => host === undefined || key !== 'q')
    // `t` sits after `q`, and names the changes by the baseline the host counts from.
    keys.splice(keys.findIndex(([key]) => key === 'd'), 0, ['t', `on Overview and Changes: copy the command for the tests that reach ${baselineWords(host).tests}`])
    for (const [key, action] of [...keys, ['', 'A file:line opens in your editor on a click. Every key has a button.'] as [string, string]]) {
      wrapWords(action, Math.max(1, columns - 3 - KEY_WIDTH)).forEach((line, i) =>
        help.push({ key: `help-${n++}`, segments: [{ text: '   ' }, { text: padEnd(i === 0 ? key : '', KEY_WIDTH), color: ACCENT }, { text: line, dim: true }] }),
      )
    }
    return help
  }
  return { key: 'keys', grow: { length: lines(80).length, min: lines(80).length }, make: columns => ({ key: 'keys', title: 'Keys', body: lines(columns) }) }
}

/** Groups of segments on one line, two cells apart. */
function joinGroups(groups: Segment[][]): Segment[] {
  return groups.flatMap((g, i) => (i === 0 ? g : [{ text: '  ' }, ...g]))
}
