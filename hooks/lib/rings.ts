/**
 * A component's blast radius, as the detail draws it: what depends on it
 * one hop away, two hops away, and further (`knossos blast-radius`), each
 * ring with how many of its components a test reaches and the test files
 * that do.
 *
 * Pure. Drawn as rows lined up in columns, the nearest ring first, a blank
 * row between two rings:
 *
 *     1 hop      4    ▲ C Near0                       ✓ 3 tested
 *                       C Near1           ■ edge      ▲ 1 untested
 *                     ✓   2 test files  NearTest.php
 *
 * The ring's distance and count stand in the left column on its first row,
 * how it stands (all tested, or how many are and are not) at the right
 * end; between them its components one a line, their marks in one column
 * (`▲` for the untested, in the warning colour, then the kind), their names
 * in the next, and a boundary named only where it is not the component's
 * own; then `+N more` for what the ring does not show, and the test files
 * that reach it last. The head card already names the component itself,
 * so the rings do not draw it again. Every named component is a row the
 * marker walks and `o` opens; a ring shows as many as the pane's height
 * gives it, the rest one `+N more` that walks to the next of them. Below
 * {@link RINGS_MIN} columns each ring's distance, count and standing share
 * one row, its components under it.
 */
import type { BlastRadius, RingsState } from '../../types'
import { noteOf, windowOf } from './cards'
import type { Block } from './cards'
import { ACCENT, HEADING, NO_HUES, SECONDARY, SELECTED_BG } from './palette'
import type { Hues } from './palette'
import { absolute, baseName } from './paths'
import { button, clip, dimRow, inList, segmentsWidth } from './rows'
import { chip } from './chips'
import { GUTTER, groupGap } from './spacing'
import { cells, displayName, fit, grouped, padEnd, padStart, plural, spaces, wrapWords } from './text'
import type { Loc, Row, Segment } from './types'
import { REACH, kindMark, mark } from './marks'
import { busy } from './motion'

/** One component a ring names: what it shows, what it opens, and whether a test reaches it. */
/** `via` names the member of the component a dependant reaches it through (a static method's caller), by its own name. */
export type RingMember = { name: string; canonical: string; kind: string; boundary: string | null; tested: boolean; loc: Loc | null; via?: string | null }

/** One ring as the detail draws it. */
/** `probable` counts the members whose path is only probable or possible: a guess, as the interface a call goes through. */
export type Ring = { label: string; count: number; tested: number; probable?: number; members: RingMember[]; tests: { count: number; paths: string[] } }

/**
 * The blast radius the detail draws: loading, what to say instead, or the
 * rings. `testsPartial` when the tests' own searches ran out of time, so an
 * untested member may have a test no search reached.
 */
export type RingsInput = { loading: boolean; said: string | null; centre: { name: string; boundary: string | null } | null; rings: Ring[]; truncated: boolean; testsPartial?: boolean }

/** The narrowest the rings are drawn in their columns; narrower, each ring's figures share its first row. */
export const RINGS_MIN = 44

/** The fewest components a ring lists however short the pane: the rest are its `+N more`. */
export const RING_MIN = 3

/** The cells between the columns: the ring's figures and its marks, its names and their boundaries, the rest and its standing. */
const GAP = 3

/** The cells before a component's name: the marker, the untested mark and the kind, a space between. */
const LEAD = 6

/** The cells a name keeps whole in the columns: past it a long name may be cut, short of it the rings take the narrow form. */
const NAME_KEEP = 24

/** The ring's name by its hop: the last one gathers everything further. */
const ringLabel = (hop: number): string => (hop >= 3 ? '3+ hops' : hop === 1 ? '1 hop' : `${hop} hops`)

/** The detail's view of the stored rings, for the component it shows (`name`); `root` places each on disk. */
export function ringsInput(state: RingsState | null, name: string, root: string | null): RingsInput {
  const none = { centre: null, rings: [], truncated: false }
  if (state === null || state.name !== name || (state.phase === 'loading' && state.answer === null)) return { loading: true, said: busy('Reading what depends on it, hop by hop…'), ...none }
  const answer: BlastRadius | null = state.answer
  if (answer === null || answer.status !== 'ok' || answer.component === null) return { loading: false, said: answer?.status === 'not-found' ? 'knossos found no component by that name to measure.' : 'knossos did not answer; the detail asks again with the next snapshot.', ...none }
  return {
    loading: state.phase === 'loading',
    said: null,
    centre: { name: displayName(answer.component), boundary: answer.component.boundary },
    rings: answer.rings.map(r => ({
      label: ringLabel(r.hop),
      count: r.count,
      tested: r.tested,
      probable: (r.by_confidence?.probable ?? 0) + (r.by_confidence?.possible ?? 0),
      members: r.items.map(i => ({ name: displayName(i), canonical: i.canonical_name, kind: i.kind, boundary: i.boundary, tested: i.tested, loc: root === null || i.path === null || i.path === undefined ? null : { path: absolute(root, i.path), line: i.line ?? null }, via: i.via_member === undefined || i.via_member === null ? null : memberName(i.via_member) })),
      tests: { count: r.tests.count, paths: r.tests.items.map(t => t.path) },
    })),
    truncated: answer.truncated,
    testsPartial: answer.tests_partial === true,
  }
}

/** The components the rings name, nearest ring first: what the marker walks after the detail's own lists (rows the detail opens). */
export const ringsList = (input: RingsInput | null | undefined): { name: string; canonical: string; loc: Loc | null }[] => (input === null || input === undefined ? [] : input.rings.flatMap(r => r.members.map(m => ({ name: m.name, canonical: m.canonical, loc: m.loc }))))

/** A member's own name, what follows `::` or the last `\` or `.` of its full one. */
const memberName = (full: string): string => full.split(/::|\\|(?<=\w)\.(?=\w)/).at(-1) ?? full

/** How a ring stands, one part a row: all tested, or how many are tested and how many are not; or none. */
function standing(ring: Ring): Segment[][] {
  if (ring.count === 0) return [[{ text: 'none', dim: true }]]
  const untested = ring.count - ring.tested
  if (untested === 0) return [[mark(REACH.tested, ' all tested')]]
  const untestedPart = [mark(REACH.untested, ` ${grouped(untested)} ${REACH.untested.legend}`)]
  return ring.tested === 0 ? [untestedPart] : [[mark(REACH.tested, ` ${grouped(ring.tested)} ${REACH.tested.legend}`)], untestedPart]
}

/** A ring's count with how many of them only probably depend on the component, when any do. */
const probableOf = (ring: Ring): string => ((ring.probable ?? 0) > 0 ? ` (${grouped(ring.probable ?? 0)} probable)` : '')

/** One ring laid out: where its walk starts, the members it shows, and its `+N more` with the walk index that press moves to. */
type Laid = { ring: Ring; index: number; offset: number; start: number; shown: RingMember[]; more: number; next: number | null }

/** Each ring's members shown at most `limit` at a time, round the marker when it is on one of them. */
function laidOut(rings: readonly Ring[], offset: number, limit: number, selected: number): Laid[] {
  let at = offset
  return rings.map((ring, index) => {
    const listed = ring.members.length
    // A last one is shown rather than counted: its `+1 more` would take the row it needs.
    const room = listed === limit + 1 && ring.count === listed ? listed : Math.max(1, limit)
    const window = windowOf(listed, room, inList(selected - at, listed))
    const next = window.end < listed ? at + window.end : window.start > 0 ? at + window.start - 1 : null
    const laid = { ring, index, offset: at, start: window.start, shown: ring.members.slice(window.start, window.end), more: ring.count - (window.end - window.start), next }
    at += listed
    return laid
  })
}

/** A member's lead: the marker when marked, the untested mark, the kind. {@link LEAD} cells. */
const lead = (m: RingMember, marked: boolean): Segment[] => [{ text: marked ? '›' : ' ', color: ACCENT, bold: true }, { text: ' ' }, m.tested ? { text: ' ' } : mark(REACH.untested), { text: ' ' }, kindMark(m.kind)]

/** A member's name, a press that opens it (the marked one tinted), and the member it is reached through, in `width` cells. */
function named(m: RingMember, index: number, selected: number, width: number): Segment[] {
  const via = m.via === undefined || m.via === null ? '' : ` via ${m.via}`
  const name = fit(m.name, Math.max(4, width - cells(via)))
  const pressed: Segment = { ...button(`rel:${index}`, name), ...(index === selected ? { bg: SELECTED_BG, bold: true } : {}) }
  return [pressed, ...(via === '' ? [] : [{ text: fit(via, Math.max(0, width - cells(name))), dim: true }])]
}

/** The `+N more` under a ring's members: a press to the next one hidden where there is one, else text. */
function moreOf(laid: Laid): Segment[] {
  if (laid.more <= 0) return []
  const text = `+${grouped(laid.more)} more`
  return [laid.next === null ? { text, dim: true } : button(`more:${laid.next}`, text, undefined, { dim: true })]
}

/** The test files that reach a ring, their count and as many names as fit `width` (from the mark's column), then how many more. */
function testsOf(ring: Ring, width: number): Segment[] {
  if (ring.count === 0) return []
  if (ring.tests.count === 0) return [mark(REACH.untested, `${spaces(LEAD - 3)}no test file reaches it`)]
  const lead = plural(ring.tests.count, 'test file', 'test files')
  const said = (shown: string[]): string => {
    const rest = ring.tests.count - shown.length
    return shown.length === 0 ? lead : `${lead}  ${shown.join(', ')}${rest > 0 ? ` +${rest}` : ''}`
  }
  let shown: string[] = []
  for (const name of ring.tests.paths.map(baseName)) {
    if (cells(said([...shown, name])) > width - (LEAD - 2)) break
    shown = [...shown, name]
  }
  return [{ text: `${REACH.tested.glyph}${spaces(LEAD - 3)}${fit(said(shown), Math.max(1, width - (LEAD - 2)))}`, dim: true }]
}

/** `content` with `part` set against the right end of `columns`, at `at`; cut to the columns. */
function withStanding(key: string, content: Segment[], part: Segment[] | undefined, at: number, columns: number): Row {
  const used = segmentsWidth(content)
  const segments = part === undefined ? content : [...content, { text: spaces(Math.max(1, at - used)) }, ...part]
  return { key, segments: clip(segments, columns) }
}

/**
 * The rings in `columns` (the card's inner width): see the module docblock.
 * `offset` is the walk's index of the first component they name, `limit`
 * the most a ring lists.
 */
export function ringRows(input: RingsInput, columns: number, selected: number, offset: number, hues: Hues = NO_HUES, limit = Number.POSITIVE_INFINITY): Row[] {
  const laid = laidOut(input.rings, offset, limit, selected)
  const home = input.centre?.boundary ?? null
  const labelWidth = Math.max(0, ...laid.map(l => cells(l.ring.label)))
  const countWidth = Math.max(0, ...laid.map(l => cells(grouped(l.ring.count))))
  const left = labelWidth + 2 + countWidth + Math.max(0, ...laid.map(l => cells(probableOf(l.ring))))
  const standWidth = Math.max(0, ...laid.flatMap(l => standing(l.ring).map(segmentsWidth)))
  const room = columns - GUTTER - left - 1 - LEAD - GAP - standWidth
  const members = laid.flatMap(l => l.shown)
  const need = Math.max(4, ...members.map(m => cells(m.name) + (m.via === undefined || m.via === null ? 0 : cells(` via ${m.via}`))))
  // Names cut short say less than the columns line up: the rings take the narrow form instead.
  if (columns < RINGS_MIN || room < Math.min(need, NAME_KEEP)) return ringLists(laid, columns, selected, hues, home)
  const chips = new Map(members.map(m => [m, m.boundary === home ? [] : chip(m.boundary, hues)]))
  const chipWidth = Math.max(0, ...[...chips.values()].map(segmentsWidth))
  // Boundaries give way before names do.
  const withChips = chipWidth > 0 && room - chipWidth - 2 >= Math.min(need, NAME_KEEP)
  const nameWidth = Math.min(need, withChips ? room - chipWidth - 2 : room)
  const inset = spaces(GUTTER + left + 1)
  // The standing a column past the names and their boundaries: read across from them, never past the edge.
  const at = Math.min(columns - standWidth, inset.length + LEAD + nameWidth + (withChips ? 2 + chipWidth : 0) + GAP)
  return laid.flatMap((l): Row[] => {
    const figures: Segment[] = [{ text: spaces(GUTTER) }, { text: padEnd(l.ring.label, labelWidth), bold: true, color: l.index === 0 ? HEADING : SECONDARY }, { text: `  ${padStart(grouped(l.ring.count), countWidth)}`, color: HEADING }, { text: padEnd(probableOf(l.ring), left - labelWidth - 2 - countWidth), dim: true }, { text: ' ' }]
    const content: { key: string; segments: Segment[] }[] = l.shown.map((m, j) => {
      const index = l.offset + l.start + j
      const name = named(m, index, selected, nameWidth)
      const own = withChips ? chips.get(m) ?? [] : []
      return { key: `ring-${l.index}-member-${l.start + j}`, segments: [...lead(m, index === selected), ...name, ...(own.length === 0 ? [] : [{ text: spaces(nameWidth - segmentsWidth(name) + 2) }, ...own])] }
    })
    const more = moreOf(l)
    if (more.length > 0) content.push({ key: `ring-${l.index}-more`, segments: [{ text: spaces(2) }, ...more] })
    const parts = standing(l.ring)
    // The tests line runs to the edge unless a standing shares its row.
    const tests = testsOf(l.ring, (content.length < parts.length ? at - GAP : columns) - inset.length - 2)
    if (tests.length > 0) content.push({ key: `ring-${l.index}-tests`, segments: [{ text: spaces(2) }, ...tests] })
    const rows = Array.from({ length: Math.max(1, content.length, parts.length) }, (_, k): Row => {
      const own = content[k]
      const key = k === 0 ? `ring-${l.index}-head` : (own?.key ?? `ring-${l.index}-standing-${k}`)
      return withStanding(key, [...(k === 0 ? figures : [{ text: inset }]), ...(own?.segments ?? [])], parts[k], at, columns)
    })
    return l.index === 0 ? rows : [groupGap(`ring-${l.index}-gap`), ...rows]
  })
}

/** The rings for a pane too narrow for their columns: each ring's distance, count and standing on one row, its components under it. */
function ringLists(laid: Laid[], columns: number, selected: number, hues: Hues, home: string | null): Row[] {
  const body = spaces(GUTTER)
  const room = Math.max(1, columns - GUTTER - LEAD)
  return laid.flatMap((l): Row[] => {
    const head: Segment[] = [{ text: body }, { text: l.ring.label, bold: true, color: HEADING }, { text: ` · ${grouped(l.ring.count)}${probableOf(l.ring)}`, dim: true }]
    const stands = standing(l.ring).flatMap((part, i) => (i === 0 ? part : [{ text: ' · ', dim: true }, ...part]))
    // How the ring stands beside its figures while both fit, else on a row of its own under them.
    const beside = segmentsWidth(head) + 2 + segmentsWidth(stands) <= columns
    const rows: Row[] = beside ? [{ key: `ring-${l.index}-head`, segments: [...head, { text: '  ' }, ...stands] }] : [{ key: `ring-${l.index}-head`, segments: clip(head, columns) }, { key: `ring-${l.index}-standing`, segments: clip([{ text: `${body}  ` }, ...stands], columns) }]
    l.shown.forEach((m, j) => {
      const index = l.offset + l.start + j
      const own = m.boundary === home ? [] : chip(m.boundary, hues)
      const name = named(m, index, selected, own.length > 0 && room > 24 ? room - segmentsWidth(own) - 1 : room)
      rows.push({ key: `ring-${l.index}-member-${l.start + j}`, segments: clip([{ text: body }, ...lead(m, index === selected), ...name, ...(own.length > 0 && room > 24 ? [{ text: ' ' }, ...own] : [])], columns) })
    })
    const more = moreOf(l)
    if (more.length > 0) rows.push({ key: `ring-${l.index}-more`, segments: [{ text: `${body}  ` }, ...more] })
    const tests = testsOf(l.ring, Math.max(1, columns - GUTTER - 2))
    if (tests.length > 0) rows.push({ key: `ring-${l.index}-tests`, segments: clip([{ text: `${body}  ` }, ...tests], columns) })
    return l.index === 0 ? rows : [groupGap(`ring-${l.index}-gap`), ...rows]
  })
}

/**
 * The blast radius as a detail card: the rings (`offset` is the walk's
 * index of the first component they name, each ring as long as the pane's
 * height lets it be), or what to say while they load or when there are none.
 */
export function ringsBlock(input: RingsInput, selected: number, offset: number, hues: Hues = NO_HUES): Block {
  const total = input.rings.reduce((sum, r) => sum + r.count, 0)
  const longest = Math.max(0, ...input.rings.map(r => r.members.length))
  return {
    key: 'rings',
    ...(input.said === null && longest > 0 ? { grow: { length: longest, min: Math.min(RING_MIN, longest) } } : {}),
    make: (columns, limit) => {
      const note = noteOf(input.said !== null ? '' : `${grouped(total)}${input.truncated ? '+' : ''} dependents, tests apart${input.testsPartial === true ? ', tests partly searched' : ''}`)
      if (input.said !== null) return { key: 'rings', title: 'Blast radius', note, body: wrapWords(input.said, Math.max(1, columns - GUTTER)).map((line, i) => dimRow(`rings-said-${i}`, `${spaces(GUTTER)}${line}`, columns)) }
      if (total === 0) return { key: 'rings', title: 'Blast radius', note, body: [dimRow('rings-none', `${spaces(GUTTER)}Nothing depends on it: a change here reaches no other component.`, columns)] }
      const body = ringRows(input, columns, selected, offset, hues, longest === 0 ? Number.POSITIVE_INFINITY : Math.max(Math.min(RING_MIN, longest), limit))
      return { key: 'rings', title: 'Blast radius', ...(input.loading ? { subtitle: busy('reading…') } : {}), note, body }
    },
  }
}
