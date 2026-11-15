/**
 * The pane's Issues tab and its component detail: a view model
 * read from the envelopes, and the rows each draws at a given width.
 *
 * Pure, like the rest of the layout: envelopes in, rows of styled segments
 * out, every width decision made and tested here. Rows that list components
 * are pressable by an index into the view's walkable list, so the marker,
 * `o` and a click all address the same component.
 */
import { detailHead } from './detail-head'
import { diffBlock } from './diff'
import type { DiffView } from './diff'
import { NOTE_ROOM, noteOf, windowOf, windowedTable } from './cards'
import type { Arrangement, Block, Section } from './cards'
import type { ComponentDetail, Counterpart, Dashboard, DetailState, Diagnostic, Inspected, NotesFilter, NoteState, PaneTab } from '../../types'
import { ringsBlock, ringsList } from './rings'
import type { RingsInput } from './rings'
import { countLabel, detailLines } from './envelopes'
import { boundaryColour, boundaryLabel, FAINT, NO_HUES, STATUS_COLOURS } from './palette'
import { DIAGRAM_MIN, neighbourhood } from './diagram'
import type { Neighbour } from './diagram'
import type { Hues } from './palette'
import { absolute, placeText } from './paths'
import { button, dimRow, inList, moreListedRow, segmentsWidth, tintedRow } from './rows'
import { MARK, numberWidth, tableHead, tableRow, tableSpec, withDependents } from './table'
import { chip, SWATCH } from './chips'
import { cells, displayName, fit, grouped, placeLeast, placeSegments, shortName, spaces, wrapWords } from './text'
import { unreadablePolicies, unreadableText } from './policies'
import type { Loc, Row, Segment } from './types'
import type { Tier } from './table'
import { CERTAINTY, REACH, SEVERITY, kindLegend, kindMark, legend, mark } from './marks'
import { busy } from './motion'
import { notesBlock } from './notes-card'
import { bodyRow, gutter, moreText } from './spacing'

/**
 * A row the marker can walk to: `name` to show, `canonical` to look it up by,
 * and `loc` where its file is, when known, for `e` to open. A `file` row is a
 * file, not a component: opening it opens the file itself.
 */
export type Openable = {
  name: string
  canonical: string
  loc?: Loc | null
  file?: boolean
  /** Nothing to open (a boundary): `o` only marks it. */
  inert?: true
  /** What `c` copies instead of the canonical name (a cycle's chain). */
  copy?: string
  /** What "Ask Claude" asks instead of what depends on it (a cycle, a boundary). */
  ask?: string
  /** A file the session's changes list: its detail shows the change since the session began. */
  changed?: true
  /** Opens another tab instead of a detail (an Overview chart's bar): the tab, and how it opens. */
  jump?: Jump
  /** Heads a group the tab folds (a Branch boundary): `o` folds or unfolds it, by this key in the view's `folds`. */
  fold?: string
  /** The opposite key `fold` replaces (a Notes boundary set open replaces the one that set it closed). */
  contrary?: string
}

/**
 * Where a press on an Overview chart takes the pane: a tab, the in-degree
 * range Hubs narrows to, the row its marker starts on, and the boundary a
 * Boundaries cell runs to.
 */
export type Jump = { tab: PaneTab; degree?: { from: number; to: number | null }; selected?: number; target?: string; notesFilter?: NotesFilter }

export type ViolationLine = { source: Openable & { boundary: string | null }; target: string; targetBoundary: string | null; place: string }
/** `certainty` is the candidate's confidence, `probable` or `possible`: how sure the scan is that nothing reaches it. */
export type DeadLine = Openable & { boundary: string | null; place: string; testOnly: boolean; certainty: string; kind: string }
/** A diagnostic as Issues lists it; `path` null for one about no file (the project's own setup). */
/** `place` names it in the walk (file and line); `where` is what its row says: the project path, then its source. */
export type DiagnosticLine = { severity: string; text: string; path: string | null; place: string; where: string; loc: Loc | null }

export type IssuesInput = {
  /** Null when the knossos that answered sends no policy section. */
  /** `unreadable` is why the project's policies could not be read, absent when they could. */
  policy: { evaluated: boolean; total: string; count: number; items: ViolationLine[]; unreadable?: string } | null
  diagnostics: { errors: number; warnings: number; infos: number; items: DiagnosticLine[] } | null
  /** `probable` is how many of `total` are probably dead and `testOnly` how many only tests reach, each null from a knossos that does not say. */
  deadCode: { total: string; probable: number | null; testOnly: number | null; items: DeadLine[] }
  /** The files a change is riskiest in: lines times dependents, the highest first. */
  hotspots: { path: string; lines: number; dependents: number; score: number; loc: Loc | null }[]
  /** The files over the project's function-length budget; null without a budget, undefined when the knossos that answered does not say. */
  budget: { source: string; max: number; total: number; files: { path: string; functions: number; longest: number; loc: Loc | null }[] } | null | undefined
}

export type Side = { title: string; count: string; items: (Openable & { boundary: string | null; edges: number })[] }
export type DetailInput = {
  label: string
  loading: boolean
  /** What to say instead of a component: not found, ambiguous, nothing answered. */
  messages: string[] | null
  component: {
    name: string
    canonical: string
    kind: string
    boundary: string | null
    place: string | null
    loc: Loc | null
    /** How a function or method is called; null for anything else and from an older knossos. */
    signature?: string | null
    usedBy: Side
    uses: Side
    /** Each with its author: `user` written in the pane, `model` by an agent; absent from an older knossos, read as the person's. */
    annotations: { kind: string; value: string; author?: string }[]
  } | null
  /** Present (null until it is read) when the detail is a file's, not a component's. */
  file?: FileView | null
  /** A changed file's change since the session began, shown below its dependents; absent when it was not opened as a change. */
  diff?: DiffView | null
  /** Drawn in a panel beside a list (master-detail): its change as text rows, the engine's diff element being the pane's width. */
  panel?: true
  /** A component's blast radius in rings, once asked for (a component's own detail, never the panel). */
  rings?: RingsInput | null
  /** A note being added to the component, while one is; null otherwise (never in the panel). */
  note?: NoteInput | null
  /** Whether the notes card offers its key (`m`): a component's own detail, not the panel. */
  notable?: true
}

/**
 * A note being added on the detail: typed, checked, asked about, recorded,
 * or refused with why. `keys` is false while another question holds `y`
 * and `n` (the allow-root offer): its answers are then buttons alone.
 */
export type NoteInput = { phase: NoteState['phase']; value: string; previous: string | null; reason: string | null; keys: boolean }

/** One file's detail as the pane draws it; `loc` places each file (and component) on disk. */
export type FileView = {
  path: string
  language: string
  lines: number | null
  boundary: string | null
  loc: Loc | null
  dependents: { count: number; truncated: boolean; boundaries: string[]; items: { path: string; edges: number; boundary: string | null; loc: Loc | null }[] }
  /** The files it depends on; null from a knossos that does not say. */
  uses: { count: number; truncated: boolean; items: { path: string; edges: number; boundary: string | null; loc: Loc | null }[] } | null
  components: { count: number; truncated: boolean; items: { name: string; canonical: string; kind: string; boundary: string | null; usedBy: number; loc: Loc | null }[] }
}

/** The share of a row a place (`file:line`) may take before it is cut from the front. */
const PLACE_SHARE = 0.35
/** The fewest cells a place is cut to before its column goes. */
const PLACE_MIN = 12
/** The narrowest the main text of an issue row is allowed before the boundary, then the place, give way. */
const MAIN_MIN = 16
/** The fewest cells the main text gives up to, past its marks, so that a place keeps its file name and line whole. */
const MAIN_FLOOR = 12

const SUPERSCRIPT = ['⁰', '¹', '²', '³', '⁴', '⁵', '⁶', '⁷', '⁸', '⁹']

/** A count as a superscript (`³`, `¹²`, `⁹⁺`), for a tab label. */
export function superscript(n: number, plus = false): string {
  return [...String(n)].map(d => SUPERSCRIPT[Number(d)] ?? '').join('') + (plus ? '⁺' : '')
}

/** Where `path` (project-relative) is on disk under `root`, at `line`; null without a path or a root. */
export function locIn(root: string | null, path: string | null, line: number | null = null): Loc | null {
  return root === null || path === null || path === '' ? null : { path: absolute(root, path), line }
}

/** The reason a policy check could not read the project's policies, as {@link IssuesInput}'s `unreadable`; nothing when it could. */
export function unreadableOf(policy: { status: string } | null | undefined): { unreadable?: string } {
  const reason = unreadablePolicies(policy)
  return reason === null ? {} : { unreadable: reason }
}

/** The Issues tab's view of a dashboard. */
export function issuesInput(d: Dashboard): IssuesInput {
  const policy = d.policy
  const diagnostics = d.diagnostics
  const root = d.project_root
  return {
    policy:
      policy === undefined
        ? null
        : {
            evaluated: policy.status === 'evaluated',
            ...unreadableOf(policy),
            total: countLabel(policy.total, policy.truncated),
            count: policy.total,
            items: policy.items.map(v => ({
              source: { name: shortName(v.source), canonical: v.source, boundary: v.source_boundary, loc: locIn(root, v.path, v.line) },
              target: shortName(v.target),
              targetBoundary: v.target_boundary,
              place: placeText(v.path, v.line),
            })),
          },
    diagnostics:
      diagnostics === undefined
        ? null
        : {
            errors: diagnostics.errors,
            warnings: diagnostics.warnings,
            infos: diagnostics.infos,
            items: diagnostics.items.map(x => ({ severity: x.severity, text: `${x.code} ${x.message}`, path: x.path, place: placeText(x.path, x.line), where: diagnosticWhere(x), loc: locIn(root, x.path, x.line) })),
          },
    deadCode: {
      total: countLabel(d.dead_code_candidates, d.dead_code_truncated),
      probable: d.dead_code_probable ?? null,
      testOnly: d.dead_code_test_only ?? null,
      items: (d.dead_code ?? []).map(c => ({
        name: displayName(c),
        canonical: c.canonical_name,
        boundary: c.boundary,
        place: placeText(c.path, c.line),
        loc: locIn(root, c.path, c.line),
        testOnly: c.reachability === 'test_only',
        certainty: c.confidence,
        kind: c.kind,
      })),
    },
    hotspots: (d.complexity_hotspots ?? []).map(f => ({ path: f.path, lines: f.lines, dependents: f.dependent_files, score: f.score, loc: locIn(root, f.path) })),
    budget:
      d.over_budget === undefined || d.over_budget === null
        ? d.over_budget
        : {
            source: d.over_budget.source,
            max: d.over_budget.max_function_lines,
            total: d.over_budget.total,
            files: d.over_budget.files.map(f => ({ path: f.path, functions: f.functions, longest: f.longest, loc: locIn(root, f.path, f.line) })),
          },
  }
}

/** How many issues the tab label counts: policy violations, errors and warnings; `plus` when that is a floor. */
export function issueCount(issues: IssuesInput): { n: number; plus: boolean } {
  const policy = issues.policy?.evaluated ? issues.policy.count : 0
  const diagnostics = issues.diagnostics === null ? 0 : issues.diagnostics.errors + issues.diagnostics.warnings
  return { n: policy + diagnostics, plus: issues.policy?.total.endsWith('+') ?? false }
}

/**
 * Where a diagnostic stands, as its row says it: its path from the project
 * root and its line, since two files of one name are common enough to need
 * telling apart, then the scanner or enricher that recorded it (`knossos.`
 * left off, `source unknown` when none is known). A row too narrow for it
 * cuts the path's start first. An older knossos sends no source, and none is
 * said.
 */
function diagnosticWhere(x: Diagnostic): string {
  const source = x.source === undefined ? null : x.source === null ? 'source unknown' : x.source.replace(/^knossos\./, '')
  const place = x.path === null ? '' : x.line === null ? x.path : `${x.path}:${x.line}`
  return [place, source].filter((part): part is string => part !== null && part !== '').join(' · ')
}

/** The rows the Issues tab walks: each violation's source, each dead-code candidate, then the hotspot files and the files over budget. */
export function issuesList(issues: IssuesInput): Openable[] {
  const file = (path: string, loc: Loc | null): Openable => ({ name: path, canonical: path, loc, file: true })
  return [
    ...(issues.policy?.items ?? []).map(v => v.source),
    // A diagnostic opens its file, and `e` goes to its line; one about no file is only marked.
    ...(issues.diagnostics?.items ?? []).map((x): Openable => (x.path === null ? { name: x.text, canonical: x.text, inert: true } : { name: x.place, canonical: x.path, loc: x.loc, file: true })),
    ...issues.deadCode.items,
    ...issues.hotspots.map(f => file(f.path, f.loc)),
    ...(issues.budget?.files ?? []).map(f => file(f.path, f.loc)),
  ]
}

const sideOf = (title: string, related: { count: number; truncated: boolean; names: string[]; items?: Counterpart[] }): Side => ({
  title,
  count: countLabel(related.count, related.truncated),
  items:
    related.items?.map(i => ({ name: displayName(i), canonical: i.canonical_name, boundary: i.boundary, edges: i.edges })) ??
    // An older knossos names them only: listed without counts, opened by that name (which may be ambiguous).
    related.names.map(name => ({ name, canonical: name, boundary: null, edges: 0 })),
})

/** The detail's view of the component on show, from the stored lookup; `root` places its file on disk. */
export function detailInput(shown: Inspected, state: DetailState | null, root: string | null = null): DetailInput {
  const label = shown.label
  if (state === null || state.name !== shown.name || state.phase === 'loading') return { label, loading: true, messages: null, component: null }
  const answer: ComponentDetail | null = state.detail
  if (answer === null) return { label, loading: false, messages: [`No details for ${label}: knossos said nothing.`], component: null }
  const c = answer.component
  if (answer.status !== 'ok' || c === null) return { label, loading: false, messages: detailLines(answer, shown.name), component: null }
  const name = displayName({ name: c.display_name ?? label, canonical_name: c.name, kind: c.kind })
  return {
    label,
    loading: false,
    messages: null,
    component: {
      name,
      canonical: c.name,
      kind: c.kind,
      boundary: c.boundary ?? c.boundaries[0] ?? null,
      place: c.path === null ? null : `${c.path}${c.line === null ? '' : `:${c.line}`}`,
      loc: locIn(root, c.path, c.line),
      signature: c.signature ?? null,
      usedBy: sideOf('Used by', c.used_by),
      uses: sideOf('Uses', c.uses),
      annotations: c.annotations ?? [],
    },
  }
}

/** The components the detail can open: everything that uses it, then everything it uses. */
export function detailList(detail: DetailInput): Openable[] {
  return detail.component === null ? [] : [...detail.component.usedBy.items, ...detail.component.uses.items, ...ringsList(detail.rings)]
}

/** An issue row's columns: its main text, then the boundary, then the place. */
export type EntrySpec = { main: number; boundary: number; place: number }
/**
 * One issue row: `need` is how wide its main text is uncut, `marks` the cells of it its marks take; `loc` makes its
 * place a link, and `whole` asks the place's share for its whole path, not only its file name (its folders tell it apart).
 */
export type Entry = { selected?: boolean; mark?: Segment; need: number; marks?: number; main: (width: number) => Segment[]; boundary?: string | null; place: string; whole?: true; loc?: Loc | null; /** Two names in its main text (a source and a target): the names win the row ({@link namesFirst}). */ names?: 2 }

/**
 * Fits issue rows to `columns`, packed to the left like a table: the main
 * text takes what the longest needs, the boundary and the place follow it,
 * and width left over stays at the right edge. As the width shrinks a place
 * gives up its folders (it is cut from its front), then the main text gives
 * up cells from {@link MAIN_MIN} to {@link MAIN_FLOOR} so the place keeps
 * its file name and line whole; then the file name is cut, then the
 * boundary goes, then the place. The marks always stay.
 */
export function entrySpec(columns: number, entries: Entry[], hues: Hues = NO_HUES): EntrySpec {
  // A place needs its file name and line, whatever its share; more of its path, up to its share, only from width the rest leaves over.
  const fileLeast = Math.max(0, ...entries.map(e => placeLeast(e.place)))
  const placeWhole = Math.min(Math.max(PLACE_MIN, fileLeast, Math.floor(columns * PLACE_SHARE)), Math.max(0, ...entries.map(e => cells(e.place))))
  const placeNeed = Math.min(placeWhole, Math.max(0, ...entries.map(e => (e.whole === true ? cells(e.place) : placeLeast(e.place)))))
  // A boundary is a chip: its swatch and a space before its label.
  const longest = Math.max(0, ...entries.map(e => cells(boundaryLabel(e.boundary ?? null, hues))))
  const boundaryNeed = longest === 0 ? 0 : Math.min(12, longest) + 2
  const mainNeed = Math.max(1, ...entries.map(e => e.need))
  // The marks are not the text: the main text keeps its least beside them, so they outlast the boundary and the place.
  const marks = Math.max(0, ...entries.map(e => e.marks ?? 0))
  const least = Math.min(MAIN_MIN + marks, mainNeed)
  const floor = Math.min(MAIN_FLOOR + marks, mainNeed)
  const attempt = (boundary: number, place: number): EntrySpec | null => {
    const room = columns - MARK - (boundary > 0 ? boundary + 1 : 0) - (place > 0 ? place + 1 : 0)
    if (room >= least) return { main: Math.min(room, mainNeed), boundary, place: place > 0 ? Math.min(placeWhole, place + room - Math.min(room, mainNeed)) : 0 }
    if (place === 0) return null
    // Its folders first: a place is cut from its front.
    if (place > fileLeast) return attempt(boundary, place - Math.min(place - fileLeast, least - room))
    // Then the main text, down to its floor, so the file name and line stay whole.
    if (room >= floor) return { main: room, boundary, place }
    // Then the file name is cut, down to the fewest cells a place is shown in.
    return place - (floor - room) >= PLACE_MIN ? { main: floor, boundary, place: place - (floor - room) } : null
  }
  if (entries.some(e => e.names === 2)) return namesFirst(columns, { main: mainNeed, floor, boundary: boundaryNeed, place: placeWhole })
  return attempt(boundaryNeed, placeNeed) ?? attempt(0, placeNeed) ?? attempt(0, 0) ?? { main: Math.max(1, columns - MARK), boundary: 0, place: 0 }
}

/**
 * Fits rows of two names (a source and a target), where the names are what a
 * reader scans for and win the row. As it narrows, and only as far as the
 * row needs: the place gives up its folders, then its file name is cut from
 * the front, keeping its end and its line, down to {@link PLACE_MIN}; then
 * the boundary chip after the names goes; only then do the names give up
 * cells, evenly, to their floor, and past it the place goes.
 */
function namesFirst(columns: number, need: { main: number; floor: number; boundary: number; place: number }): EntrySpec {
  const chip = need.boundary > 0 ? need.boundary + 1 : 0
  const least = Math.min(PLACE_MIN, need.place)
  // What the place keeps beside the names whole, with the chip or without it.
  const placeBeside = (chipCells: number) => Math.min(need.place, columns - MARK - need.main - chipCells - 1)
  if (need.place === 0) return chip > 0 && columns - MARK - chip >= need.main ? { main: need.main, boundary: need.boundary, place: 0 } : { main: Math.max(1, Math.min(need.main, columns - MARK)), boundary: 0, place: 0 }
  if (chip > 0 && placeBeside(chip) >= least) return { main: need.main, boundary: need.boundary, place: placeBeside(chip) }
  if (placeBeside(0) >= least) return { main: need.main, boundary: 0, place: placeBeside(0) }
  const room = columns - MARK - least - 1
  if (room >= need.floor) return { main: room, boundary: 0, place: least }
  return { main: Math.max(1, Math.min(need.main, columns - MARK)), boundary: 0, place: 0 }
}

/** One issue row, its columns fitted by {@link entrySpec}; the marked one on the selection's tint. */
export function entryRow(key: string, e: Entry, spec: EntrySpec, hues: Hues): Row {
  const main = e.main(spec.main)
  const used = main.reduce((n, s) => n + cells(s.text), 0)
  const segments: Segment[] = [
    ...gutter(e.selected === true, e.mark),
    ...main,
    { text: spaces(spec.main - used) },
  ]
  if (spec.boundary > 0) {
    const chipped = chip(e.boundary ?? null, hues, spec.boundary)
    segments.push({ text: ' ' }, ...chipped, { text: spaces(spec.boundary - segmentsWidth(chipped)) })
  }
  // Places are left-aligned: a column of file names reads down its left edge.
  if (spec.place > 0) segments.push({ text: ' ' }, ...placeSegments(e.place, spec.place, e.loc ?? null))
  const kept = segments.filter(s => s.text !== '')
  return tintedRow(key, kept, e.selected === true)
}

/** Two names joined by an arrow in `width`, each cut only as far as it must be. */
function pair(a: string, b: string, width: number): [string, string] {
  const room = width - 3
  if (cells(a) + cells(b) <= room) return [a, b]
  const half = Math.floor(room / 2)
  const aw = Math.min(cells(a), Math.max(half, room - cells(b)))
  return [fit(a, aw), fit(b, room - aw)]
}

const none = (key: string): Row => bodyRow(key, [{ text: 'none', dim: true }])

/** The fewest rows each list on the Issues tab shows, however short the pane. */
const ISSUES_MIN = 3

/** The Issues tab's lists in the order the marker walks them. */
export const ISSUES_WALK = ['violations', 'diagnostics', 'dead', 'hotspots', 'budget'] as const

/** Where each of the Issues tab's lists stands in the marker's walk: its first row's place (`offset`) and how many it lists. */
export type IssuesWalk = Record<(typeof ISSUES_WALK)[number], { offset: number; length: number }>

/** The Issues tab's walk: the violations, the diagnostics, the dead code, the hotspots, the files over budget, one after another. */
export function issuesWalk(issues: IssuesInput): IssuesWalk {
  const lengths = { violations: issues.policy?.items.length ?? 0, diagnostics: issues.diagnostics?.items.length ?? 0, dead: issues.deadCode.items.length, hotspots: issues.hotspots.length, budget: issues.budget?.files.length ?? 0 }
  let offset = 0
  const walk = {} as IssuesWalk
  for (const part of ISSUES_WALK) {
    walk[part] = { offset, length: lengths[part] }
    offset += lengths[part]
  }
  return walk
}

/** The marker's index in one list of the walk, -1 when it is on another. */
const localTo = (part: { offset: number; length: number }, selected: number): number => inList(selected - part.offset, part.length)

/**
 * A list of entries `limit` long around the marker (`local`: its index in this list), with what is above and below;
 * `walk` is its first entry's place in the marker's walk.
 */
function listedEntries(key: string, entries: Entry[], hues: Hues, at: { columns: number; limit: number; local: number; walk: number; scrolled?: number }): { body: Row[]; window: Section['window'] } {
  const spec = entrySpec(at.columns, entries, hues)
  const { rows, window } = windowedTable(`${key}-window`, entries, at, (shown, start) => shown.map((e, n) => entryRow(`${key}-${start + n}`, e, spec, hues)))
  return { body: rows, window }
}

/** Each policy violation as an entry: its source, an arrow, its target on its boundary's swatch. */
function violationEntries(violations: ViolationLine[], selected: number, hues: Hues): Entry[] {
  return violations.map((v, i) => ({
    selected: i === selected,
    mark: mark(SEVERITY.error),
    boundary: v.source.boundary,
    place: v.place,
    loc: v.source.loc ?? null,
    need: cells(v.source.name) + 3 + (v.targetBoundary === null ? 0 : 2) + cells(v.target),
    // The arrow and the target's swatch are not text to cut: the two names keep their least beside them.
    marks: 3 + (v.targetBoundary === null ? 0 : 2),
    names: 2,
    main: width => {
      // The target's boundary on a swatch before its name: the name stays in the text tone.
      const swatch: Segment[] = v.targetBoundary === null ? [] : [{ text: `${SWATCH} `, color: boundaryColour(v.targetBoundary, hues) ?? FAINT }]
      const [a, b] = pair(v.source.name, v.target, width - swatch.length * 2)
      return [button(`row:${i}`, a), { text: ' → ', dim: true }, ...swatch, { text: b }]
    },
  }))
}

/** Each diagnostic as an entry, marked by its severity, the first `offset` into the walk. */
function diagnosticEntries(items: DiagnosticLine[], offset: number, selected: number): Entry[] {
  return items.map((x, i) => ({
    selected: offset + i === selected,
    mark: mark(x.severity === 'error' ? SEVERITY.error : x.severity === 'warning' ? SEVERITY.warn : SEVERITY.note),
    place: x.where,
    whole: true,
    loc: x.loc,
    need: cells(x.text),
    main: width => [button(`row:${offset + i}`, fit(x.text, width))],
  }))
}

/**
 * Each dead-code candidate as an entry, the first `offset` into the walk. Its marks, the card's note their legend: how
 * sure the scan is in the gutter, where every issue's mark stands (● probable or a dim ○ possible: something no scan
 * sees may reach it), then what reaches it, ◐ tests only in the warning colour (finished work no product path runs) or
 * a dim ∅ nothing at all, and its kind. Where those would squeeze the names below {@link MAIN_FLOOR} they go from every
 * row at once, so they never show on some rows and not others.
 */
function deadEntries(items: DeadLine[], offset: number, selected: number): Entry[] {
  const MARKS = 4
  const longestName = Math.max(1, ...items.map(c => cells(c.name)))
  return items.map((c, i) => ({
    selected: offset + i === selected,
    mark: mark(c.certainty === 'probable' ? CERTAINTY.probable : CERTAINTY.possible),
    boundary: c.boundary,
    place: c.place,
    loc: c.loc ?? null,
    need: cells(c.name) + MARKS, marks: MARKS,
    main: width => {
      // Too narrow only when the names would be squeezed below their least, or below the longest when all are short.
      if (width - MARKS < Math.min(MAIN_FLOOR, longestName)) return [button(`row:${offset + i}`, fit(c.name, width))]
      const reach = mark(c.testOnly ? REACH.testsOnly : REACH.unused, ' ')
      return [reach, kindMark(c.kind), button(`row:${offset + i}`, fit(c.name, width - MARKS))]
    },
  }))
}

/** The verdict on the policy card's top edge: not reported, unreadable, none declared, clean, or how many. */
function policyVerdict(policy: IssuesInput['policy']): Segment[] {
  if (policy === null) return [{ text: 'not reported', dim: true }]
  if (policy.unreadable !== undefined) return [{ text: unreadableText(policy.unreadable), color: STATUS_COLOURS.warn }]
  if (!policy.evaluated) return [{ text: 'no policies declared', dim: true }]
  return policy.count === 0 ? [mark(SEVERITY.ok, ' 0')] : [mark(SEVERITY.error, ` ${policy.total}`)]
}

/** The policy violations' card. */
function policyBlock(policy: IssuesInput['policy'], entries: Entry[], selected: number, part: IssuesWalk['violations'], hues: Hues): Block {
  return {
    key: 'policy',
    grow: { length: entries.length, min: ISSUES_MIN },
    make: (columns, limit, scrolled) => {
      const { body, window } = listedEntries('pol', entries, hues, { columns, limit, local: selected < part.length ? selected : -1, walk: part.offset, scrolled })
      if (policy?.evaluated === true) body.push(...moreListedRow('pol-more', policy.count, entries.length, columns))
      return { key: 'policy', title: 'Policy violations', note: policyVerdict(policy), body, empty: 'none', ...(window === undefined ? {} : { window }) }
    },
  }
}

/** The diagnostics' card: the errors, warnings and notes counted on its edge, the first errors and warnings listed. */
function diagBlock(d: IssuesInput['diagnostics'], entries: Entry[], selected: number, part: IssuesWalk['diagnostics'], hues: Hues): Block {
  return {
    key: 'diag',
    grow: { length: entries.length, min: ISSUES_MIN },
    make: (columns, limit, scrolled) => {
      const counts =
        d === null
          ? 'not reported'
          : d.errors + d.warnings + d.infos === 0
            ? `${SEVERITY.ok.glyph} 0`
            : legend([[SEVERITY.error, d.errors], [SEVERITY.warn, d.warnings], [SEVERITY.note, d.infos]])
      const { body, window } = listedEntries('diag', entries, hues, { columns, limit, local: localTo(part, selected), walk: part.offset, scrolled })
      // Only the first errors and warnings come listed; notes are counted, never listed.
      if (d !== null) body.push(...moreListedRow('diag-more', d.errors + d.warnings, d.items.length, columns))
      return { key: 'diag', title: 'Diagnostics', note: noteOf(counts), body, empty: 'none', ...(window === undefined ? {} : { window }) }
    },
  }
}

/** The dead code's card: the total, how many are probable and how many only tests reach, then the kinds when there is room. */
function deadBlock(dead: IssuesInput['deadCode'], entries: Entry[], selected: number, part: IssuesWalk['dead'], hues: Hues): Block {
  return {
    key: 'dead',
    grow: { length: entries.length, min: ISSUES_MIN },
    make: (columns, limit, scrolled) => {
      // The probable and test-only counts beside the total, so the card does not read as that much dead code.
      const sure =
        dead.total === '0' ? '' : ((key: string) => (key === '' ? '' : ` · ${key}`))(legend([[CERTAINTY.probable, dead.probable ?? 0], [REACH.testsOnly, dead.testOnly ?? 0]]))
      const deadNote = dead.items.length > 0 && dead.total !== String(dead.items.length) ? `${dead.total}${sure} · first ${dead.items.length}` : `${dead.total}${sure}`
      const { body, window } = listedEntries('dead', entries, hues, { columns, limit, local: localTo(part, selected), walk: part.offset, scrolled })
      // The kinds the rows show join the note where the top edge has room; a note too long is dropped whole.
      const kinds = kindLegend(dead.items.map(c => c.kind))
      const keyed = kinds === '' ? deadNote : `${deadNote} · ${kinds}`
      const told = cells('Dead code') + cells(keyed) + NOTE_ROOM <= columns ? keyed : deadNote
      return { key: 'dead', title: 'Dead code', note: noteOf(told), body, empty: 'none', ...(window === undefined ? {} : { window }) }
    },
  }
}

/** The complexity hotspots' card: each file's path, a bar of its score (lines times dependents) and the two figures. */
function hotBlock(list: IssuesInput['hotspots'], selected: number, part: IssuesWalk['hotspots'], tier: Tier, hues: Hues): Block {
  return {
    key: 'hotspots',
    grow: { length: list.length, min: ISSUES_MIN },
    make: (columns, limit, scrolled) => {
      const max = Math.max(0, ...list.map(f => f.score))
      const { rows, window } = windowedTable('hot-window', list, { columns, limit, local: localTo(part, selected), scrolled, walk: part.offset }, (shown, start) => {
        // The bar draws the score (lines times dependents); the two figures it is made of stand beside it.
        const { title, spec } = withDependents(t => tableSpec(columns, shown.map(f => f.path), [], [numberWidth('lines', shown.map(f => f.lines), tier), numberWidth(t, shown.map(f => f.dependents), tier)], 56, { tier }), tier)
        const head: Row[] = list.length === 0 ? [] : [tableHead('hot-cols', spec, { name: 'file', boundary: '', numbers: ['lines', title] })]
        return [
          ...head,
          ...shown.map((f, n) => {
            const i = start + n
            return tableRow(`hot-${i}`, { name: f.path, boundary: null, values: [f.lines, f.dependents], barValue: f.score, max, path: true, press: `row:${part.offset + i}`, selected: part.offset + i === selected }, spec, hues)
          }),
        ]
      })
      return { key: 'hotspots', title: 'Complexity hotspots', note: noteOf(list.length > 0 ? 'lines × dependents' : ''), body: rows, empty: 'none', window }
    },
  }
}

/** The card of the files over the project's function-length budget: how many functions each has over it, and its longest. */
function budgetBlock(budget: IssuesInput['budget'], selected: number, part: IssuesWalk['budget'], tier: Tier, hues: Hues): Block {
  return {
    key: 'budget',
    grow: { length: budget?.files.length ?? 0, min: ISSUES_MIN },
    make: (columns, limit, scrolled) => {
      const title = 'Over budget'
      if (budget === undefined) return { key: 'budget', title, note: noteOf('not reported'), body: [], empty: 'not reported' }
      if (budget === null) return { key: 'budget', title, note: [{ text: 'no maintainability-budgets.json', dim: true }], body: [], empty: 'none' }
      const rule = `functions over ${budget.max} lines`
      if (budget.files.length === 0) return { key: 'budget', title, note: [mark(SEVERITY.ok, ' 0'), { text: ` ${rule}`, dim: true }], body: [], empty: 'none' }
      const { rows, window } = windowedTable('budget-window', budget.files, { columns, limit, local: localTo(part, selected), scrolled, walk: part.offset }, (shown, start) => {
        const spec = { ...tableSpec(columns, shown.map(f => f.path), [], [numberWidth('over', shown.map(f => f.functions), tier), numberWidth('longest', shown.map(f => f.longest), tier)], 56, { tier }), bar: 0 }
        return [
          tableHead('budget-cols', spec, { name: 'file', boundary: '', numbers: ['over', 'longest'] }),
          ...shown.map((f, n) => {
            const i = start + n
            return tableRow(`budget-${i}`, { name: f.path, boundary: null, values: [f.functions, f.longest], max: 0, path: true, press: `row:${part.offset + i}`, selected: part.offset + i === selected, mark: mark(SEVERITY.warn) }, spec, hues)
          }),
        ]
      })
      const body = [...rows, ...moreListedRow('budget-more', budget.total, budget.files.length, columns)]
      return { key: 'budget', title, note: [mark(SEVERITY.warn, ` ${grouped(budget.total)}`), { text: ` ${rule}`, dim: true }], body, empty: 'none', window }
    },
  }
}

/**
 * The Issues tab's cards: policy violations, diagnostics, dead code, the
 * complexity hotspots (lines times dependents) and the files over the
 * project's function-length budget, each list as long as the pane allows.
 * Wide, they stand on a grid of equal rows (see {@link issueGrid}).
 */
export function issuesArrangement(issues: IssuesInput, selected: number, tier: Tier, hues: Hues = NO_HUES): Arrangement {
  const walk = issuesWalk(issues)
  const policy = policyBlock(issues.policy, violationEntries(issues.policy?.items ?? [], selected, hues), selected, walk.violations, hues)
  const diag = diagBlock(issues.diagnostics, diagnosticEntries(issues.diagnostics?.items ?? [], walk.diagnostics.offset, selected), selected, walk.diagnostics, hues)
  const dead = deadBlock(issues.deadCode, deadEntries(issues.deadCode.items, walk.dead.offset, selected), selected, walk.dead, hues)
  const hot = hotBlock(issues.hotspots, selected, walk.hotspots, tier, hues)
  const budget = budgetBlock(issues.budget, selected, walk.budget, tier, hues)
  // Stacked, the cards stand in the order the marker walks them, as the wide grid reads them.
  return { left: [policy, diag, budget], right: [dead, hot], order: [policy, diag, dead, hot, budget], rows: issueGrid([policy, diag, dead, hot, budget]) }
}

/**
 * How the wide Issues tab sets its cards in rows of equal height: the cards
 * with nothing listed first, side by side as short rows of at most three,
 * the rows as even as they can be (four as two and two, five as three and
 * two), then the lists in pairs, each pair as tall as its longer list
 * allows; a list left over spans the pane.
 */
export function issueGrid(blocks: Block[]): Block[][] {
  const empty = blocks.filter(b => (b.grow?.length ?? 0) === 0)
  const listed = blocks.filter(b => (b.grow?.length ?? 0) > 0)
  const chunks = (list: Block[], size: number): Block[][] => Array.from({ length: Math.ceil(list.length / size) }, (_, i) => list.slice(i * size, i * size + size))
  const per = empty.length === 0 ? 1 : Math.ceil(empty.length / Math.ceil(empty.length / 3))
  return [...chunks(empty, per), ...chunks(listed, 2)]
}

/**
 * One side of the detail ("used by" or "uses") as rows of the narrow
 * neighbourhood: a small table `limit` rows long around the marker, under a
 * head that names the side in its first column and what the figures count
 * over them, as every table heads its columns.
 */
function sideRows(prefix: string, side: Side, offset: number, columns: number, limit: number, tier: Tier, hues: Hues, selected = -1): Row[] {
  const counted = side.items.some(i => i.edges > 0)
  // Bars that are all one length compare nothing: the counts say it alone.
  const flat = side.items.length > 1 && side.items.every(i => i.edges === side.items[0]!.edges)
  const boundaries = side.items.map(i => boundaryLabel(i.boundary, hues))
  const named = side.title.toLowerCase()
  const spec = counted
    ? { ...tableSpec(columns, [named, ...side.items.map(i => i.name)], boundaries, [numberWidth('edges', side.items.map(i => i.edges))], undefined, { tier, compact: false }), ...(flat ? { bar: 0 } : {}) }
    : { ...tableSpec(columns, [named, ...side.items.map(i => i.name)], boundaries, []), bar: 0 }
  const head = tableHead(`${prefix}-cols`, spec, { name: named, boundary: '', numbers: counted ? ['edges'] : [] })
  if (side.items.length === 0) return [head, none(`${prefix}-none`)]
  const max = Math.max(0, ...side.items.map(i => i.edges))
  const { rows } = windowedTable(`${prefix}-window`, side.items, { columns, limit, local: inList(selected - offset, side.items.length), walk: offset }, (shown, start) =>
    shown.map((item, n) =>
      tableRow(`${prefix}-${start + n}`, { name: item.name, boundary: item.boundary, values: counted ? [item.edges] : [], max, press: `rel:${offset + start + n}`, selected: offset + start + n === selected }, spec, hues),
    ),
  )
  return [head, ...rows, ...moreListedRow(`${prefix}-more`, Number.parseInt(side.count, 10), side.items.length, columns)]
}

/**
 * The detail of one component: a card naming it (kind, boundary, place),
 * who uses it and what it uses (side by side when wide), its annotations,
 * and below them its change, when it was opened as one.
 */
export function detailArrangement(detail: DetailInput, tier: Tier, hues: Hues = NO_HUES, selected = -1, host?: 'web'): Arrangement {
  const c = detail.component
  const diff = detail.diff ? [diffBlock(detail.diff, detail.panel === true, host)] : []
  if (c === null) {
    const lines = detail.loading ? [busy(`Inspecting ${detail.label}…`)] : (detail.messages ?? [])
    const head: Block = {
      key: 'detail',
      make: columns => ({ key: 'detail', title: detail.label, body: lines.flatMap((line, i) => wrapWords(line, columns).map((part, j) => dimRow(`detail-line-${i}-${j}`, part, columns))) }),
    }
    return { left: [head, ...diff] }
  }
  const head = detailHead(c, hues)
  const items = (side: Side, offset: number): HoodItem[] => side.items.map((item, i) => ({ name: item.name, boundary: item.boundary, edges: item.edges, press: `rel:${offset + i}` }))
  const hood = hoodBlock(
    { name: c.name, boundary: c.boundary },
    { title: c.usedBy.title, count: Number.parseInt(c.usedBy.count, 10) || c.usedBy.items.length, items: items(c.usedBy, 0) },
    { title: c.uses.title, count: Number.parseInt(c.uses.count, 10) || c.uses.items.length, items: items(c.uses, c.usedBy.items.length) },
    selected,
    hues,
    (columns, limit) => [
      ...sideRows('used', c.usedBy, 0, columns, limit, tier, hues, selected).map(r => ({ ...r, key: `hood-${r.key}` })),
      ...sideRows('uses', c.uses, c.usedBy.items.length, columns, limit, tier, hues, selected).map(r => ({ ...r, key: `hood-${r.key}` })),
    ],
  )
  const rings: Block[] = detail.rings === undefined || detail.rings === null ? [] : [ringsBlock(detail.rings, selected, c.usedBy.items.length + c.uses.items.length, hues)]
  const notes: Block[] = c.annotations.length === 0 && detail.notable !== true ? [] : [notesBlock(c.name, c.annotations, detail.note ?? null, detail.notable === true)]
  return { top: [head], left: [hood], bottom: [...rings, ...notes, ...diff] }
}

/** One neighbour as the neighbourhood draws it: its name, boundary, edge count and the press that opens it. */
export type HoodItem = { name: string; boundary: string | null; edges: number; press: string }
/** One side of a neighbourhood: its title, how many there are in all, and those listed, in walking order from `items[0]`'s press. */
export type HoodSide = { title: string; count: number; items: HoodItem[] }

/** The fewest neighbours each side of the neighbourhood shows, however short the pane. */
const HOOD_MIN = 3

/**
 * The neighbourhood as a card: the thing on show in the middle, what uses it
 * fanning in, what it uses fanning out, each neighbour a box carrying its
 * count on its edge and a press that opens it (see `diagram.ts`). Each side
 * shows `limit` neighbours around the marker; the rest, and those knossos
 * did not list, are one `+N more` box. Below {@link DIAGRAM_MIN} columns,
 * `fallback`'s rows (the two lists as tables) instead.
 */
export function hoodBlock(centre: { name: string; boundary: string | null }, usedBy: HoodSide, uses: HoodSide, selected: number, hues: Hues, fallback: (columns: number, limit: number) => Row[]): Block {
  // The counts at the right of the title, then what the figures on the arrows count.
  const edges = usedBy.items.some(i => i.edges > 0) || uses.items.some(i => i.edges > 0) ? ' · edges' : ''
  const note = noteOf(`used by ${usedBy.count} · uses ${uses.count}${edges}`)
  return {
    key: 'hood',
    grow: { length: Math.max(usedBy.items.length, uses.items.length) + 1, min: HOOD_MIN },
    make: (columns, limit) => {
      // Too narrow to draw: the two sides as tables, each headed by its name, the counts in the note as the drawing has them.
      if (columns < DIAGRAM_MIN) return { key: 'hood', title: 'Dependencies', note: noteOf(`used by ${usedBy.count} · uses ${uses.count}`), body: fallback(columns, limit) }
      const side = (s: HoodSide): Neighbour[] => {
        const pressed = s.items.findIndex(i => pressIndex(i.press) === selected)
        const room = s.items.length > limit || s.count > s.items.length ? Math.max(1, limit - 1) : limit
        const window = windowOf(s.items.length, room, pressed)
        const shown = s.items.slice(window.start, window.end).map((item): Neighbour => {
          const colour = boundaryColour(item.boundary, hues)
          return {
            node: { key: item.press, label: item.name, ...(colour === undefined ? {} : { color: colour }), press: { id: item.press, label: item.name }, selected: pressIndex(item.press) === selected },
            edge: item.edges > 0 ? grouped(item.edges) : '',
          }
        })
        const hidden = s.count - shown.length
        // The box presses to the nearest one it hides that the detail lists, below the window or else above it, as the
        // line under a cut list does; past the list there is nothing to go to.
        const next = s.items[window.end] ?? (window.start > 0 ? s.items[window.start - 1] : undefined)
        const label = moreText(hidden)
        const box = { key: `${s.title}-more`, label, dim: true, ...(next === undefined ? {} : { press: { id: `more:${pressIndex(next.press)}`, label } }) }
        return hidden > 0 ? [...shown, { node: box, edge: '' }] : shown
      }
      const colour = boundaryColour(centre.boundary, hues)
      const drawn = neighbourhood({ key: 'centre', label: centre.name, ...(colour === undefined ? {} : { color: colour }) }, side(usedBy), side(uses), columns, { usedBy: 'nothing uses it', uses: 'uses nothing' }, 'hood')
      return { key: 'hood', title: 'Dependencies', note, body: drawn.rows }
    },
  }
}

/** The list index a `rel:N` or `row:N` press names. */
const pressIndex = (press: string): number => Number(press.slice(press.indexOf(':') + 1))
