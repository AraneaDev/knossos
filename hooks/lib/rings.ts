/**
 * A component's blast radius, as the detail draws it: what depends on it
 * one hop away, two hops away, and further (`knossos blast-radius`), each
 * ring with how many of its components a test reaches and the test files
 * that do.
 *
 * Pure. Drawn as concentric frames, the furthest ring outermost and the
 * component in the middle (`◉ name ■ boundary`), so the eye reads outward
 * from what changes to what it reaches. Each ring's frame carries its hop,
 * its count and how it stands; inside it, one cell in from either side and
 * before the next ring in, its components in columns of one width (the
 * untested mark `▲` in the warning colour, then the kind letter, then the
 * name, then a boundary named only where it is not the component's own, each
 * in line down its column), `+N more` in the last cell for what it does not
 * show, and last the test files that reach it. Every frame row of a ring is
 * exactly as wide as its top edge, so every box closes at one column, and
 * the frames grow fainter outwards. Every named component is a press the
 * marker walks and `o` opens; the marked one is tinted where it stands, and
 * a ring's cells follow the marker when it walks past what they show. Below
 * {@link RINGS_MIN} columns the rings are drawn as plain lists, their
 * components in the same columns.
 */
import type { BlastRadius, RingsState } from '../../types'
import { noteOf, windowOf } from './cards'
import type { Block } from './cards'
import { ACCENT, FAINT, HEADING, NO_HUES, SECONDARY, SELECTED_BG } from './palette'
import type { Hues } from './palette'
import { absolute, baseName } from './paths'
import { button, clip, inList, segmentsWidth } from './rows'
import { chip } from './chips'
import { bodyLine, bodyText, GUTTER } from './spacing'
import { cells, displayName, fit, grouped, plural, spaces } from './text'
import type { Loc, Row, Segment } from './types'
import { PLACE, REACH, kindMark, mark } from './marks'
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

/** The narrowest the rings are drawn as frames: each ring takes four columns of the width. */
export const RINGS_MIN = 44

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

/** How a ring stands, as the legend of its marks: all tested, or how many are tested and how many are not; or none. */
function standing(ring: Ring): Segment[] {
  if (ring.count === 0) return [{ text: 'none', dim: true }]
  const untested = ring.count - ring.tested
  if (untested === 0) return [mark(REACH.tested, ' all tested')]
  const untestedPart = mark(REACH.untested, ` ${grouped(untested)} ${REACH.untested.legend}`)
  return ring.tested === 0 ? [untestedPart] : [mark(REACH.tested, ` ${grouped(ring.tested)} ${REACH.tested.legend}`), { text: ' · ', dim: true }, untestedPart]
}

/** A ring's count as its title says it: how many, and how many of them only probably depend on the component. */
const countOf = (ring: Ring): string => `${grouped(ring.count)}${(ring.probable ?? 0) > 0 ? ` (${grouped(ring.probable ?? 0)} probable)` : ''}`

/** The rows of cells a ring shows its components in at most, as on the rings before their columns: the last cell `+N more` when some do not show. */
export const MEMBER_ROWS = 3

/**
 * The cells between two columns of a ring: two blanks, then the one the
 * marked component's `›` stands in, so the marker keeps a blank cell from the
 * column before it even where that column's name fills its cell (as a
 * table's marker keeps the margin before it).
 */
const CELL_GAP = 3

/** The cells before a component's name in its cell: the untested mark, a space, the kind letter and its space. */
const LEAD = 4

/** The fewest cells a name keeps before its member and its boundary give way. */
const NAME_LEAST = 8

/**
 * A ring's components as lines of cells `room` wide (see the module docblock); `at` is the walk's index of its first.
 * Each line starts one cell early, in the padding before its first mark, where the marker stands.
 */
function memberGrid(ring: Ring, at: number, room: number, selected: number, hues: Hues, home: string | null): Segment[][] {
  const members = ring.members
  if (members.length === 0 && ring.count === 0) return []
  const chips = members.map(m => (m.boundary === home ? [] : chip(m.boundary, hues)))
  const viaOf = (m: RingMember): string => (m.via === undefined || m.via === null ? '' : ` via ${m.via}`)
  // One width for every cell of the ring, from all it lists, so the columns hold still while the marker walks them.
  const chipWidth = Math.max(0, ...chips.map(segmentsWidth))
  const longest = Math.max(0, ...members.map(m => cells(m.name) + cells(viaOf(m))))
  const need = Math.max(cells(`+${grouped(ring.count)} more`), LEAD + longest + (chipWidth > 0 ? 1 + chipWidth : 0))
  const width = Math.max(1, Math.min(room, need))
  const per = Math.max(1, Math.floor((room + CELL_GAP) / (width + CELL_GAP)))
  const slots = per * MEMBER_ROWS
  // All of them when they fit with the count of those knossos did not list; else the cells less one, and `+N more` in it.
  const all = members.length <= slots && (ring.count === members.length || members.length < slots)
  const window = windowOf(members.length, all ? members.length : Math.max(1, slots - 1), inList(selected - at, members.length))
  const shown = members.slice(window.start, window.end)
  // The boundaries in a column of their own at the cell's end, while the names keep their least beside it.
  const nameRoom = width - LEAD
  const withChips = chipWidth > 0 && nameRoom - 1 - chipWidth >= Math.min(longest, NAME_LEAST)
  const nameColumn = withChips ? nameRoom - 1 - chipWidth : nameRoom
  const pieces: Segment[][] = shown.map((m, j) => {
    const index = at + window.start + j
    const own = withChips ? chips[window.start + j]! : []
    // The member it is reached through goes before the name is cut below its least.
    const via = cells(viaOf(m)) > 0 && nameColumn - cells(viaOf(m)) >= Math.min(cells(m.name), NAME_LEAST) ? viaOf(m) : ''
    const name = fit(m.name, Math.max(1, nameColumn - cells(via)))
    const pressed: Segment = { ...button(`rel:${index}`, name), ...(index === selected ? { bg: SELECTED_BG, bold: true } : {}) }
    const named = cells(name) + cells(via)
    const cell: Segment[] = [m.tested ? { text: ' ' } : mark(REACH.untested), { text: ' ' }, kindMark(m.kind), pressed, ...(via === '' ? [] : [{ text: via, dim: true }]), ...(own.length === 0 ? [] : [{ text: spaces(nameColumn - named + 1) }, ...own])]
    return clip(cell, width)
  })
  const more = ring.count - shown.length
  if (more > 0) {
    const next = window.end < members.length ? at + window.end : window.start > 0 ? at + window.start - 1 : null
    const text = fit(`+${grouped(more)} more`, width)
    pieces.push([next === null ? { text, dim: true } : button(`more:${next}`, text, undefined, { dim: true })])
  }
  // Each line opens with the cell before its first mark, and every cell after the first has one in its gap: the marked
  // component's `›` stands there, as a table's marker stands before a row's mark, and no column moves for it.
  const marked = (i: number): Segment => (i < shown.length && at + window.start + i === selected ? { text: '›', color: ACCENT, bold: true } : { text: ' ' })
  const lines: Segment[][] = []
  pieces.forEach((piece, i) => {
    if (i % per === 0) lines.push([marked(i)])
    const line = lines[lines.length - 1]!
    if (i % per > 0) line.push({ text: spaces(CELL_GAP - 1 + width - segmentsWidth(pieces[i - 1]!)) }, marked(i))
    line.push(...piece)
  })
  return lines
}

/** The test files that reach a ring, by file name, as many as fit one line `width` wide, then how many more. */
function testsLine(ring: Ring, width: number): Segment[] {
  if (ring.count === 0) return []
  if (ring.tests.count === 0) return [mark(REACH.untested, fit(' no test file reaches it', Math.max(0, width - 1)))]
  const lead = `${REACH.tested.glyph} ${plural(ring.tests.count, 'test file', 'test files')}`
  const said = (shown: string[]): string => {
    const rest = ring.tests.count - shown.length
    return shown.length === 0 ? lead : `${lead}  ${shown.join(', ')}${rest > 0 ? ` +${rest}` : ''}`
  }
  let shown: string[] = []
  for (const name of ring.tests.paths.map(baseName)) {
    if (cells(said([...shown, name])) > width) break
    shown = [...shown, name]
  }
  return [{ text: fit(said(shown), width), dim: true }]
}

/** A ring's frame colour: the nearest in a text tone, the further ones faint. */
const frameColour = (index: number): string => (index === 0 ? SECONDARY : FAINT)

/** Each ring's walk index: where its first component stands in the walk, from `offset`. */
const offsetsOf = (rings: readonly Ring[], offset: number): number[] => rings.map((_, i) => offset + rings.slice(0, i).reduce((sum, r) => sum + r.members.length, 0))

/**
 * The rings as nested frames in `columns`: the furthest ring outermost,
 * each frame two cells in from the one around it on either side, its title
 * in its top edge, its components and tests before the next frame, the
 * component itself in the middle. Every row is `columns` wide.
 */
export function ringRows(input: RingsInput, columns: number, selected: number, offset: number, hues: Hues = NO_HUES): Row[] {
  const rings = input.rings
  const n = rings.length
  const offsets = offsetsOf(rings, offset)
  const home = input.centre?.boundary ?? null
  const rows: Row[] = []
  // The left and the right sides of the `depth` outermost frames, outermost at the edges.
  const sides = (depth: number): { left: Segment[]; right: Segment[] } => {
    const left: Segment[] = []
    const right: Segment[] = []
    for (let d = 0; d < depth; d++) {
      const colour = frameColour(n - 1 - d)
      left.push({ text: '│ ', color: colour })
      right.unshift({ text: ' │', color: colour })
    }
    return { left, right }
  }
  // A line inside `depth` frames: their left sides, the content cut to the room, padding, their right sides; `lead`, the
  // content takes the innermost frame's padding cell too (the marker's, before a line of components).
  const inside = (key: string, depth: number, content: Segment[], lead = false): Row => {
    const sided = sides(depth)
    const last = sided.left[sided.left.length - 1]
    const left = lead && last !== undefined ? [...sided.left.slice(0, -1), { ...last, text: '│' }] : sided.left
    const room = Math.max(0, columns - 4 * depth + (left === sided.left ? 0 : 1))
    const fitted = clip(content, room)
    return { key, segments: [...left, ...fitted, { text: spaces(room - segmentsWidth(fitted)) }, ...sided.right] }
  }
  for (let d = 0; d < n; d++) {
    const index = n - 1 - d
    const ring = rings[index]!
    const width = columns - 4 * d
    const colour = frameColour(index)
    const title: Segment[] = [{ text: ring.label, bold: true, color: index === 0 ? HEADING : SECONDARY }, { text: ` · ${countOf(ring)}`, color: HEADING }]
    const state = standing(ring)
    const fill = width - 8 - segmentsWidth(title) - segmentsWidth(state)
    const top: Segment[] = [{ text: '╭─ ', color: colour }, ...title, { text: ` ${'─'.repeat(Math.max(1, fill))} `, color: colour }, ...state, { text: ' ─╮', color: colour }]
    const { left, right } = sides(d)
    // Too narrow for both in the edge: the hop and count stay in it, and how the ring stands is its first line inside.
    const bare = width - 5 - segmentsWidth(title)
    const edge: Segment[] = fill >= 1 ? top : bare >= 1 ? [{ text: '╭─ ', color: colour }, ...title, { text: ` ${'─'.repeat(bare)}╮`, color: colour }] : [{ text: `╭${'─'.repeat(Math.max(0, width - 2))}╮`, color: colour }]
    rows.push({ key: `ring-${index}-top`, segments: [...left, ...edge, ...right] })
    const room = width - 4
    if (fill < 1) rows.push(inside(`ring-${index}-standing`, d + 1, state))
    memberGrid(ring, offsets[index]!, room, selected, hues, home).forEach((line, i) => rows.push(inside(`ring-${index}-names-${i}`, d + 1, line, true)))
    const tests = testsLine(ring, room)
    if (tests.length > 0) rows.push(inside(`ring-${index}-tests`, d + 1, tests))
  }
  const centre = input.centre
  if (centre !== null) {
    const room = columns - 4 * n
    const own = chip(centre.boundary, hues)
    const label: Segment[] = [mark(PLACE.centre, ' '), { text: fit(centre.name, Math.max(4, room - segmentsWidth(own) - 4)), bold: true, color: HEADING }, ...(own.length > 0 ? [{ text: ' ' }, ...own] : [])]
    const pad = Math.max(0, Math.floor((room - segmentsWidth(label)) / 2))
    rows.push(inside('ring-centre', n, [{ text: spaces(pad) }, ...label]))
  }
  for (let d = n - 1; d >= 0; d--) {
    const { left, right } = sides(d)
    rows.push({ key: `ring-${n - 1 - d}-end`, segments: [...left, { text: `╰${'─'.repeat(Math.max(0, columns - 4 * d - 2))}╯`, color: frameColour(n - 1 - d) }, ...right] })
  }
  return rows
}

/** The rings as plain lists, for a pane too narrow for frames: each ring's figures and standing, its components in columns under them, its tests. */
function ringLists(input: RingsInput, columns: number, selected: number, offset: number, hues: Hues): Row[] {
  const offsets = offsetsOf(input.rings, offset)
  const body = spaces(GUTTER)
  const room = Math.max(1, columns - GUTTER - 2)
  return input.rings.flatMap((ring, index): Row[] => {
    const head: Segment[] = [{ text: body }, { text: ring.label, bold: true, color: HEADING }, { text: ` · ${countOf(ring)}`, dim: true }]
    const state = standing(ring)
    // How the ring stands beside its figures while both fit, else on a row of its own under them.
    const beside = segmentsWidth(head) + 2 + segmentsWidth(state) <= columns
    const rows: Row[] = beside ? [{ key: `ring-${index}-head`, segments: [...head, { text: '  ' }, ...state] }] : [{ key: `ring-${index}-head`, segments: clip(head, columns) }, { key: `ring-${index}-standing`, segments: clip([{ text: `${body}  ` }, ...state], columns) }]
    memberGrid(ring, offsets[index]!, room, selected, hues, input.centre?.boundary ?? null).forEach((line, i) => rows.push({ key: `ring-${index}-names-${i}`, segments: [{ text: `${body} ` }, ...line] }))
    const tests = testsLine(ring, room)
    if (tests.length > 0) rows.push({ key: `ring-${index}-tests`, segments: [{ text: `${body}  ` }, ...tests] })
    return rows
  })
}

/**
 * The blast radius as a detail card: the rings (`offset` is the walk's
 * index of the first component they name), or what to say while they load
 * or when there are none. Its title says how many depend on it at the
 * right end, as every card's count.
 */
export function ringsBlock(input: RingsInput, selected: number, offset: number, hues: Hues = NO_HUES): Block {
  const total = input.rings.reduce((sum, r) => sum + r.count, 0)
  return {
    key: 'rings',
    make: columns => {
      const note = noteOf(input.said !== null ? '' : `${grouped(total)}${input.truncated ? '+' : ''} dependents, tests apart${input.testsPartial === true ? ', tests partly searched' : ''}`)
      if (input.said !== null) return { key: 'rings', title: 'Blast radius', note, body: bodyText('rings-said', input.said, columns) }
      if (total === 0) return { key: 'rings', title: 'Blast radius', note, body: [bodyLine('rings-none', 'Nothing depends on it: a change here reaches no other component.', columns)] }
      const body = columns >= RINGS_MIN ? ringRows(input, columns, selected, offset, hues) : ringLists(input, columns, selected, offset, hues)
      return { key: 'rings', title: 'Blast radius', ...(input.loading ? { subtitle: busy('reading…') } : {}), note, body }
    },
  }
}
