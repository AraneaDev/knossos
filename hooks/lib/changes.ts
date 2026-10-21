/**
 * The Changes tab that lists what this session changed, and what the
 * Overview's "This session" card sums up from it.
 *
 * Pure, like the rest of the layout. It reads the session's changes as
 * `session-changes.ts` adds them up, from the turn briefs or the scan
 * ledger; the command that runs the tests reaching them is
 * `test-commands.ts`'s.
 */
import type { SessionChanges, TouchStatus } from '../../types'
import { ACCENT, boundaryLabel, HEADING, NO_HUES, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { blank, button, clip, dimRow, segmentsWidth, tinted, wrapGroups } from './rows'
import { numberWidth, tableHead, tableRow, tableSpec } from './table'
import { chip } from './chips'
import { cells, grouped, padEnd, padStart, plural, spaces, wrapWords } from './text'
import type { Loc, Row, Segment } from './types'
import type { Tier } from './table'
import { NOTE_ROOM, noteOf, windowedTable } from './cards'
import type { Arrangement, Block, Section } from './cards'
import { locIn } from './views'
import type { Openable } from './views'
import { CHANGE, REACH, SEVERITY, legend, mark } from './marks'
import { baselineWords } from './baseline'
import { cdFor, testCommand } from './test-commands'

// Where branch.ts reaches it, from this module.
export { stamp } from './session-changes'

/** How many boundaries the Changes tab names on its summary line. */
const REACH_SHOWN = 3
/** Paths longer than this are cut from the front in a table. */
const PATH_MAX = 56
/** Where a file's change came from, as the Changes tab labels it. */
const ORIGIN_LABELS = { session: 'this session', outside: 'outside' } as const
const ORIGIN_WIDTH = Math.max(...Object.values(ORIGIN_LABELS).map(l => l.length))

/** A boundary's place in `hues` (the project's colour order), past the end for one it does not hold. */
export function rankIn(hues: Hues): (boundary: string) => number {
  const order = [...hues.keys()]
  return b => (order.includes(b) ? order.indexOf(b) : order.length)
}

/** A changed file as the Changes tab lists it; `tests` how many test files reach it, null when that is not known. */
export type TouchedFile = { path: string; status: TouchStatus; boundary: string | null; dependents: number; loc: Loc | null; origin?: 'session' | 'outside'; tests: number | null }
export type ChangesInput = {
  turns: number
  /** Most dependents first. */
  files: TouchedFile[]
  /** Nearest first. */
  tests: { path: string; distance: number; loc: Loc | null }[]
  command: string | null
  /** Every boundary the changes' dependents are in, best ranked first. */
  boundaries: string[]
  violations: number
  /** The files still there that no test reaches, as far as that is known. */
  untested: number
  /** Whether the list holds only the files no test reaches: asked for, and there are some. */
  untestedOnly: boolean
  truncated: boolean
  /** Whether the files are every change since the session began (from the scan ledger), each with its origin. */
  sinceStart: boolean
  /** What to say about where the files start (only the turn briefs', an older start, an approximate one), when there is something to say. */
  fallback: string | null
  /** Where the files start when it is not where the session began (`since 2026-10-04 09:14`). */
  since: string | null
  /** The session's scans, oldest first, by whose changes each took in; `earlier` counts the older ones left out. */
  timeline: { origin: 'session' | 'outside' }[]
  earlier: boolean
}

/**
 * The Changes tab's view of the session's changes; `root` places the files on
 * disk, and the command changes to it when `sessionRoot` is elsewhere.
 */
export function changesInput(changes: SessionChanges, root: string | null, hues: Hues = NO_HUES, sessionRoot: string | null = null, untestedOnly = false): ChangesInput {
  const files = Object.entries(changes.files)
    .map(([path, f]) => ({
      path,
      status: f.status,
      // The file's own boundary; its dependents' are where the change reaches, not where it is.
      boundary: f.boundary ?? null,
      dependents: f.dependents,
      loc: f.status === 'deleted' ? null : locIn(root, path),
      ...(changes.origins === undefined ? {} : { origin: changes.origins[path] ?? 'outside' }),
      tests: f.status === 'deleted' || typeof f.tests !== 'number' ? null : f.tests,
    }))
    .sort((a, b) => b.dependents - a.dependents || a.path.localeCompare(b.path))
  const tests = Object.entries(changes.tests)
    .map(([path, distance]) => ({ path, distance, loc: locIn(root, path) }))
    .sort((a, b) => a.distance - b.distance || a.path.localeCompare(b.path))
  const reached = [...new Set(Object.values(changes.files).flatMap(f => f.boundaries))]
  const rank = rankIn(hues)
  const untested = files.filter(isUntested).length
  return {
    turns: changes.turns,
    files,
    tests,
    command: testCommand(tests.map(t => t.path), changes.js_runners, cdFor(root, sessionRoot)),
    boundaries: reached.sort((a, b) => rank(a) - rank(b) || a.localeCompare(b)),
    violations: changes.violations.length,
    untested,
    // With none left to show, the list is every file again.
    untestedOnly: untestedOnly && untested > 0,
    truncated: changes.truncated,
    sinceStart: changes.origins !== undefined || changes.since !== undefined,
    fallback: changes.fallback ?? null,
    since: changes.since ?? null,
    timeline: (changes.timeline ?? []).map(t => ({ origin: t.origin })),
    earlier: changes.timeline_truncated === true,
  }
}

/**
 * The session's scans as one thin row: a dot per scan, oldest first, in the
 * accent for one that took in this session's own changes and dim for one
 * that took in changes made outside it, then the two counts. A timeline
 * longer than the row keeps its newest scans behind a `…`. Null with no scan.
 */
export function timelineRow(input: Pick<ChangesInput, 'timeline' | 'earlier'>, columns: number): Row | null {
  const scans = input.timeline
  if (scans.length === 0) return null
  const own = scans.filter(t => t.origin === 'session').length
  const counts: Segment[] = [
    { text: '  ' },
    { text: grouped(own), color: ACCENT },
    { text: ' this session', dim: true },
    ...(own === scans.length ? [] : [{ text: ' · ', dim: true }, { text: grouped(scans.length - own), color: HEADING }, { text: ' outside', dim: true }]),
  ]
  const lead: Segment = { text: '   scans ', dim: true }
  const room = Math.max(1, columns - cells(lead.text) - segmentsWidth(counts))
  const cut = input.earlier || scans.length > room
  const shown = scans.slice(-Math.max(1, cut ? room - 1 : room))
  const dots: Segment[] = shown.map(t => (t.origin === 'session' ? { text: TIMELINE_DOT, color: ACCENT } : { text: TIMELINE_DOT, dim: true }))
  // Runs of one origin as one segment: a long timeline is a handful of segments, not one per scan.
  const runs = dots.reduce<Segment[]>((out, d) => {
    const last = out[out.length - 1]
    if (last !== undefined && last.color === d.color && last.dim === d.dim) out[out.length - 1] = { ...last, text: last.text + d.text }
    else out.push(d)
    return out
  }, [])
  return { key: 'changes-timeline', segments: clip([lead, ...(cut ? [{ text: '…', dim: true }] : []), ...runs, ...(columns - cells(lead.text) - shown.length - (cut ? 1 : 0) >= segmentsWidth(counts) ? counts : [])], columns) }
}

/** One scan on the timeline. */
const TIMELINE_DOT = CHANGE.scan.glyph

/** The files the Changes tab walks: each opens as a file's detail (who depends on it), and `e` opens it in the editor. */
export function changesList(input: ChangesInput): Openable[] {
  return listedFiles(input).map(f => ({ name: f.path, canonical: f.path, loc: f.loc, file: true, changed: true }))
}

/** A file still there that no test reaches: what the count above the list counts. */
const isUntested = (f: TouchedFile): boolean => f.tests === 0

/** The files the Changes list shows, in its order: every one, or only those no test reaches. */
export const listedFiles = (input: ChangesInput): TouchedFile[] => (input.untestedOnly ? input.files.filter(isUntested) : input.files)

/** What the Overview's session card reads: the touched file with the most dependents, how many tests reach the changes, and their command. */
export type LookAt = { file: TouchedFile | null; tests: number; command: string | null; plus: boolean }

/** The session card's figures from the session's changes; null before anything changed. */
export function lookAtOf(input: ChangesInput): LookAt | null {
  if (input.files.length === 0) return null
  const file = input.files.find(f => f.status !== 'deleted') ?? null
  return { file, tests: input.tests.length, command: input.command, plus: input.truncated }
}

/**
 * A line of what something reaches: `lead` (the files and their dependents,
 * or nothing), then the best ranked few boundaries in their colours and the rest counted,
 * so the line stays a line and not a rainbow. Fewer are named when the pane
 * is narrow, so the count never wraps onto a line of its own.
 */
export function reachRows(key: string, lead: string, boundaries: string[], columns: number, hues: Hues = NO_HUES): Row[] {
  const groups = (shown: number): Segment[][] => {
    const named = boundaries.slice(0, shown)
    const out: Segment[][] = lead === '' ? [] : [[{ text: lead, dim: true }]]
    if (named.length > 0) out.push([{ text: 'reaching', dim: true }], ...named.map(b => chip(b, hues)))
    if (boundaries.length > named.length) out.push([{ text: `+${boundaries.length - named.length} more`, dim: true }])
    return out
  }
  const shown = [REACH_SHOWN, 2, 1].find(n => wrapGroups(key, groups(n), columns, 1, 3).length === 1) ?? REACH_SHOWN
  return wrapGroups(key, groups(shown), columns, 1, 3)
}

/** A one-cell mark for how a file stands: `+` added, `−` deleted, `~` changed. */
export function statusMark(status: TouchStatus): Segment {
  if (status === 'added') return mark(CHANGE.added)
  return status === 'deleted' ? mark(CHANGE.removed) : mark(CHANGE.changed)
}

/** The fewest changed files and tests the Changes tab lists, however short the pane. */
const FILES_MIN = 5
const TESTS_MIN = 3

/**
 * The Changes tab's cards: every file this session touched, most dependents
 * first, with the boundary it sits in and, since the session began, where
 * each change came from; then the tests that reach the changes, nearest
 * first, and the command that runs them. Wide, the tests stand beside the
 * files.
 */
export function changesArrangement(input: ChangesInput, selected: number, tier: Tier, hues: Hues = NO_HUES, lit: ReadonlySet<string> = new Set(), host?: 'web'): Arrangement {
  const files: Block = { key: 'changes', grow: { length: listedFiles(input).length, min: FILES_MIN }, make: (columns, limit, scrolled) => filesSection(input, selected, columns, limit, tier, hues, lit, scrolled, host) }
  if (input.files.length === 0) return { left: [files] }
  const tests: Block = { key: 'tests', grow: { length: input.tests.length, min: TESTS_MIN }, make: (columns, limit, scrolled) => testsSection(input, columns, limit, hues, scrolled) }
  // Wide, the two side by side on the grid, equally tall.
  return { left: [files], right: [tests], rows: [[files, tests]], split: 0.5 }
}

/** The changed files' card, its list `limit` rows long around the marker. */
function filesSection(input: ChangesInput, selected: number, columns: number, limit: number, tier: Tier, hues: Hues, lit: ReadonlySet<string>, scrolled?: number, host?: 'web'): Section {
  const rows: Row[] = []
  const said = (key: string, text: string) => wrapWords(text, Math.max(1, columns - 3)).forEach((line, i) => rows.push(dimRow(`${key}-${i}`, `   ${line}`, columns)))
  const words = baselineWords(host)
  const title = words.list
  const timeline = timelineRow(input, columns)
  if (timeline !== null) rows.push(timeline)
  if (input.files.length === 0) {
    if (input.fallback !== null) said('changes-fallback', input.fallback)
    // On the web a fallback says why there is no list at all (no git, a failed read): nothing follows it.
    if (input.fallback !== null && host === 'web') return { key: 'changes', title, body: rows }
    said(
      'changes-none',
      // Off Claude Code nothing is edited by a turn: the list is always of the changes since its baseline.
      input.sinceStart || host === 'web'
        ? `Nothing changed in this project ${input.since ?? words.since}.`
        : "Nothing yet. The files Claude edits show here after each turn's scan, with what depends on them and the tests that reach them.",
    )
    return { key: 'changes', title, body: rows }
  }
  const deps = input.files.reduce((n, f) => n + f.dependents, 0)
  // Every change since the session began says where it came from, in a column of its own at the end.
  const origin = input.sinceStart && input.files.some(f => f.origin !== undefined) && columns > ORIGIN_WIDTH + 20 ? ORIGIN_WIDTH + 1 : 0
  // How many tests reach each file, when that is known: a file none reaches is marked in the warning tone.
  const tested = input.files.some(f => f.tests !== null) && columns - origin > TESTS_WIDTH + 24 ? TESTS_WIDTH + 1 : 0
  const spec = tableSpec(columns - origin - tested, input.files.map(f => f.path), input.files.map(f => boundaryLabel(f.boundary)), [numberWidth('deps', input.files.map(f => f.dependents), tier)], PATH_MAX, { tier })
  const max = Math.max(0, ...input.files.map(f => f.dependents))
  // The file marks the card shows, counted: the note is their legend, where it fits beside the title. A note too
  // long for the top edge is dropped whole, so the legend gives way first and what the note says otherwise stays.
  const counted = (status: TouchStatus) => input.files.filter(f => f.status === status).length
  const key = legend([[CHANGE.changed, counted('changed')], [CHANGE.added, counted('added')], [CHANGE.removed, counted('deleted')]])
  const told = `${input.sinceStart ? (input.since ?? 'since it began') : plural(input.turns, 'turn', 'turns')}${input.truncated ? ' · partial' : ''}`
  const keyed = key === '' ? told : `${told} · ${key}`
  const note = cells(title) + cells(keyed) + NOTE_ROOM <= columns ? keyed : told
  if (input.fallback !== null) said('changes-fallback', input.fallback)
  // What the changes reach: the dependents, and every boundary they are in, each in its colour.
  rows.push(...reachRows('changes-reach', `${plural(input.files.length, 'file', 'files')} → ${grouped(deps)} dependents`, input.boundaries, columns, hues))
  if (input.violations > 0) {
    // The violations are listed on Issues: the press opens it on the first.
    rows.push({ key: 'changes-policy', segments: [{ text: '   ' }, button('stat:policy', `${SEVERITY.error.glyph} ${plural(input.violations, 'policy violation', 'policy violations')} introduced`, undefined, { color: STATUS_COLOURS.alert })] })
  }
  // The count is a press that lists only those files; while it does, it says so, and a row offers every file back.
  if (input.untested > 0) {
    const count: Segment[] = [button('untested-row', `${REACH.untested.glyph} ${untestedText(input.untested)}`, undefined, { color: STATUS_COLOURS.warn })]
    rows.push(...wrapGroups('changes-untested', input.untestedOnly ? [count, [{ text: '· showing only these', dim: true }]] : [count], columns, 1, 3))
    if (input.untestedOnly) rows.push({ key: 'changes-all', segments: [{ text: '   ' }, button('untested-all', 'show all files', undefined, { dim: true })] })
  }
  const head = tableHead('changes-cols', spec, { name: 'file', boundary: 'boundary', numbers: ['deps'] })
  rows.push({
    ...head,
    segments: [...head.segments, ...(tested === 0 ? [] : [{ text: ` ${padStart('tests', TESTS_WIDTH)}`, dim: true }]), ...(origin === 0 ? [] : [{ text: ` ${padEnd('from', ORIGIN_WIDTH)}`, dim: true }])],
  })
  const listed = listedFiles(input)
  const { rows: shown, window } = windowedTable('changes-more', listed, { columns, limit, local: selected, scrolled, walk: 0 }, (files, start) =>
    files.map((f, n) => {
      const i = start + n
      const line = tableRow(`change-${i}`, { name: f.path, boundary: f.boundary, values: [f.dependents], max, selected: i === selected, mark: statusMark(f.status), path: true, press: `row:${i}`, lit: lit.has(`change:${f.path}`) }, spec, hues)
      const added: Segment[] = [...(tested === 0 ? [] : [{ text: ' ' }, testsCell(f.tests)]), ...(origin === 0 || f.origin === undefined ? [] : [{ text: ' ' }, originCell(f.origin)])]
      return added.length === 0 ? line : { ...line, segments: [...line.segments, ...(line.tint === undefined ? added : tinted(added, line.tint))] }
    }),
  )
  rows.push(...shown)
  return { key: 'changes', title, note: noteOf(note), body: rows, window }
}

/** The cells the Changes tab's tests column takes: its title, `▲ none` and a count. */
const TESTS_WIDTH = 6
/** How many tests the per-file count names before it is a floor (as `FileTestReach::COUNTED`). */
const TESTS_COUNTED = 20

/** How many changed files no test reaches, said as a warning. */
export const untestedText = (n: number): string => `${plural(n, 'file', 'files')} no test reaches`

/** A changed file's tests cell: `▲ none` in the warning tone, the count dim (`20+` at the cap), blank when not known. */
function testsCell(tests: number | null): Segment {
  if (tests === null) return { text: spaces(TESTS_WIDTH) }
  if (tests === 0) return { ...mark(REACH.untested), text: padStart(`${REACH.untested.glyph} none`, TESTS_WIDTH) }
  return { text: padStart(tests >= TESTS_COUNTED ? `${TESTS_COUNTED}+` : String(tests), TESTS_WIDTH), dim: true }
}

/** A changed file's origin cell: this session's in the accent, outside dim. */
const originCell = (origin: 'session' | 'outside'): Segment =>
  origin === 'session' ? { text: padEnd(ORIGIN_LABELS.session, ORIGIN_WIDTH), color: ACCENT } : { text: padEnd(ORIGIN_LABELS.outside, ORIGIN_WIDTH), dim: true }

/** The tests that reach the changes, nearest first, `limit` of them, and the command that runs them all. */
function testsSection(input: ChangesInput, columns: number, limit: number, hues: Hues, scrolled?: number): Section {
  const count = `${grouped(input.tests.length)}${input.truncated ? '+' : ''}`
  const title = 'Tests that reach these changes'
  if (input.tests.length === 0) {
    // With some file's reach known, the files none reaches are listed on their own by the press.
    const said = `${REACH.untested.glyph} no test reaches these changes`
    const warn: Segment = input.untested > 0 ? button('untested-none', said, undefined, { color: STATUS_COLOURS.warn }) : { text: said, color: STATUS_COLOURS.warn }
    return { key: 'tests', title, body: [{ key: 'tests-none', segments: [{ text: '   ' }, warn] }] }
  }
  const listed = windowedTable('tests-more', input.tests, { columns, limit, scrolled }, (shown, start) => {
    const spec = { ...tableSpec(columns, shown.map(t => t.path), [], [numberWidth('hops', shown.map(t => t.distance))], PATH_MAX), bar: 0 }
    return [tableHead('tests-cols', spec, { name: 'test', boundary: '', numbers: ['hops'] }), ...shown.map((t, n) => tableRow(`test-${start + n}`, { name: t.path, boundary: null, values: [t.distance], max: 0, path: true, opens: t.loc }, spec, hues))]
  })
  const rows: Row[] = [...listed.rows]
  if (input.command !== null) {
    rows.push(blank('gap-command'))
    wrapWords(input.command, Math.max(1, columns - 5)).forEach((line, i) =>
      rows.push({ key: `command-${i}`, segments: [{ text: i === 0 ? '   $ ' : '     ', dim: true }, { text: line, color: HEADING }] }),
    )
  }
  return { key: 'tests', title, subtitle: count, note: noteOf('nearest first'), body: rows, window: listed.window }
}
