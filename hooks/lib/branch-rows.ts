/**
 * The Branch tab's rows: the findings under the verdict and the touched
 * files by boundary. Pure.
 *
 * Every row stands on the pane's gutter: the marker, then the row's mark
 * (a finding's severity, a boundary's fold), then the body. Findings of one
 * kind share their columns (names, boundaries, figures), so a kind reads
 * down the pane; what changed has its figures right-aligned in columns of
 * their own, a file's under its boundary's, and the bar takes no more than
 * a gauge's share of the row.
 */
import { boundaryColour, FAINT, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { button, clip, segmentsWidth, tintedRow } from './rows'
import { bar, barRange, tierOf } from './table'
import { chip, SWATCH } from './chips'
import { cells, fit, grouped, padEnd, padStart, pathParts, placeSegments, plural, spaces } from './text'
import { apart, bodyIndent, gutter, GUTTER, nest, NEST, PART_GAP } from './spacing'
import type { Row, Segment } from './types'
import { CARD_ICON, CHANGE, SEVERITY, mark } from './marks'
import type { BranchFile, BranchFinding, BranchGroup, BranchLine } from './branch'

/** The cells a group's reach takes at its row's end, and the column title over it. */
export const REACH_CELLS = 6

/** The fewest cells a bar is drawn in: narrower, it gives way. */
const BAR_MIN = 4

/** The fewest cells a name is cut to while what follows it on its row still has room. */
const NAME_LEAST = 8

/** One walked row: the gutter (the marker, the row's `mark`), then `content` at the body, cut to `columns`, tinted when marked. */
function itemRow(key: string, marked: boolean, content: Segment[], columns: number, rowMark?: Segment): Row {
  return tintedRow(key, clip([...gutter(marked, rowMark), ...content], columns), marked, { columns })
}

/** `segments` padded with spaces to `width` cells. */
const padded = (segments: Segment[], width: number): Segment[] => [...segments, { text: spaces(width - segmentsWidth(segments)) }]

/** The widest of `texts`, in cells. */
const widest = (texts: string[]): number => Math.max(0, ...texts.map(cells))

/** Two columns that share `room`: each as wide as its longest while both fit, else the room split, the narrower kept whole. */
function share(a: number, b: number, room: number): [number, number] {
  if (a + b <= room) return [a, b]
  const half = Math.floor(room / 2)
  if (a <= half) return [a, Math.max(1, room - a)]
  if (b <= half) return [Math.max(1, room - b), b]
  return [Math.max(1, half), Math.max(1, room - half)]
}

/** A component's boundary as a chip padded to `width`; blank where it has none. */
const chipCell = (line: BranchLine, hues: Hues, width: number): Segment[] => (width === 0 ? [] : padded(chip(line.boundary, hues), width))

/** The widest chip the lines' boundaries take. */
const chipWidth = (lines: BranchLine[], hues: Hues): number => Math.max(0, ...lines.map(l => segmentsWidth(chip(l.boundary, hues))))

/**
 * The shown findings as rows, `start` the first's place in the walk: each
 * kind's names, boundaries and figures in columns of their own, its mark in
 * the gutter.
 */
export function findingRows(shown: BranchFinding[], start: number, selected: number, columns: number, hues: Hues): Row[] {
  const room = Math.max(NAME_LEAST, columns - GUTTER)
  const of = <K extends BranchFinding['kind']>(kind: K) => shown.filter((f): f is Extract<BranchFinding, { kind: K }> => f.kind === kind)
  const policies = of('policy')
  const cycles = of('cycle')
  const crossings = of('crossing')
  const hubs = of('hub')
  const dead = of('dead')
  // Policy: the source, an arrow, the target, then the policy it breaks.
  const policyWidth = widest(policies.map(f => f.policy))
  const [policySource, policyTarget] = share(widest(policies.map(f => f.line.name)), widest(policies.map(f => f.target)), Math.max(2 * NAME_LEAST, room - 3 - 2 - policyWidth))
  // Cycle: its size, its first member, the rest of the loop.
  const sizeWidth = widest(cycles.map(f => String(f.size)))
  const cycleName = Math.min(widest(cycles.map(f => f.line.name)), Math.max(NAME_LEAST, Math.floor((room - sizeWidth - 1) / 2)))
  // Crossing: the source and its boundary, an arrow, the target's boundary and the target.
  const fromChip = chipWidth(crossings.map(f => f.line), hues)
  const toChip = chipWidth(crossings.map(f => f.target), hues)
  const [crossSource, crossTarget] = share(widest(crossings.map(f => f.line.name)), widest(crossings.map(f => f.target.name)), Math.max(2 * NAME_LEAST, room - (fromChip > 0 ? fromChip + 1 : 0) - 3 - (toChip > 0 ? toChip + 1 : 0)))
  // Hub: the name and its boundary, then how many depended on it before and after, and the growth.
  const hubChip = chipWidth(hubs.map(f => f.line), hues)
  const before = widest(hubs.map(f => grouped(f.before)))
  const after = widest(hubs.map(f => grouped(f.after)))
  const growth = widest(hubs.map(f => `+${grouped(f.after - f.before)}`))
  const hubName = Math.min(widest(hubs.map(f => f.line.name)), Math.max(NAME_LEAST, room - (hubChip > 0 ? hubChip + 1 : 0) - 2 - before - 3 - after - 1 - growth))
  // Dead code: the name and its boundary, then where it is, which keeps its file name and line while the name gives way.
  const deadChip = chipWidth(dead.map(f => f.line), hues)
  const deadPlace = widest(dead.map(f => f.line.place))
  const deadName = Math.min(widest(dead.map(f => f.line.name)), Math.max(NAME_LEAST, room - (deadChip > 0 ? deadChip + 1 : 0) - (deadPlace > 0 ? 2 + deadPlace : 0)))
  return shown.map((f, n) => {
    const at = start + n
    const press = `row:${at}`
    const name = (text: string, width: number): Segment[] => padded([button(press, fit(text, width))], width)
    const own = (line: BranchLine, width: number): Segment[] => (width === 0 ? [] : [{ text: ' ' }, ...chipCell(line, hues, width)])
    if (f.kind === 'policy') return itemRow(`finding-${at}`, at === selected, [...name(f.line.name, policySource), { text: ' → ', dim: true }, { text: padEnd(fit(f.target, policyTarget), policyTarget) }, apart(), { text: f.policy, dim: true }], columns, mark(SEVERITY.error))
    if (f.kind === 'cycle') {
      const rest = `${f.members.slice(1).map(m => m.name).join(' → ')}${f.size > f.members.length ? ' → …' : ''}`
      return itemRow(`finding-${at}`, at === selected, [{ text: `${padStart(String(f.size), sizeWidth)} `, dim: true }, button(press, fit(f.line.name, cycleName)), { text: ` → ${rest}`, dim: true }], columns, { text: CARD_ICON.cycles, color: STATUS_COLOURS.warn })
    }
    if (f.kind === 'crossing') {
      const target: Segment[] = [...(toChip === 0 ? [] : [...chipCell(f.target, hues, toChip), { text: ' ' }]), { text: fit(f.target.name, crossTarget) }]
      return itemRow(`finding-${at}`, at === selected, [...name(f.line.name, crossSource), ...own(f.line, fromChip), { text: ' → ', dim: true }, ...target], columns, mark(SEVERITY.warn))
    }
    if (f.kind === 'hub') {
      const figures: Segment[] = [apart(), { text: `${padStart(grouped(f.before), before)} → ${padStart(grouped(f.after), after)} `, dim: true }, { text: `+${grouped(f.after - f.before)}`, color: STATUS_COLOURS.warn }]
      return itemRow(`finding-${at}`, at === selected, [...name(f.line.name, hubName), ...own(f.line, hubChip), ...figures], columns, mark(SEVERITY.warn))
    }
    const place = f.line.place === '' ? [] : [apart(), ...placeSegments(f.line.place, Math.max(1, room - deadName - (deadChip > 0 ? deadChip + 1 : 0) - PART_GAP), f.line.loc)]
    return itemRow(`finding-${at}`, at === selected, [...name(f.line.name, deadName), ...own(f.line, deadChip), ...place], columns, mark(SEVERITY.warn))
  })
}

/** A row of what changed: a boundary, or a touched file under its opened boundary. */
export type ChangedRow = { kind: 'group'; g: BranchGroup } | { kind: 'file'; f: BranchFile }

/** How a group is named on its row. */
export const labelOf = (g: Pick<BranchGroup, 'name' | 'outside'>): string => (g.outside ? 'outside the graph' : (g.name ?? 'no boundary'))

/**
 * What changed as rows, `shown` of them from `start` in the list, the list
 * `at0` into the walk: the reach column's title, then each boundary (its
 * fold in the gutter, its swatch and name, a bar by lines changed, its
 * files, its lines and the files its changes reach) and, under an opened
 * one, each file (its path under the boundary's name, its lines and its
 * dependents in the same columns). Narrow, the bar gives way first, then the
 * file count; the reach the list is sorted by always shows.
 */
export function changedRows(groups: BranchGroup[], shown: ChangedRow[], start: number, at0: number, selected: number, columns: number, hues: Hues): Row[] {
  const room = Math.max(1, columns - GUTTER)
  const name = Math.min(24, Math.max(8, Math.floor(columns / 4)), widest(groups.map(labelOf)))
  const lines = shown.map(r => (r.kind === 'group' ? r.g : r.f))
  const added = widest(lines.map(l => `+${grouped(l.added)}`))
  const deleted = widest(lines.map(l => `${CHANGE.removed.glyph}${grouped(l.deleted)}`))
  const files = widest(groups.map(g => plural(g.count, 'file', 'files')))
  const lead = 2 + name
  const tail = 2 + added + 1 + deleted + 2 + REACH_CELLS
  // The bar's share of the row as a table's, the files beside it while the bar keeps its least.
  const range = barRange(columns, tierOf(columns))
  const withFiles = room - lead - (1 + files) - tail - 1 >= BAR_MIN
  const left = room - lead - (withFiles ? 1 + files : 0) - tail - 1
  const barWidth = left >= BAR_MIN ? Math.min(range.max, left) : 0
  const most = Math.max(1, ...groups.map(g => g.added + g.deleted))
  // Where the files' column ends: a file's path runs to it, under its boundary's name.
  const pathEnd = lead + (barWidth > 0 ? 1 + barWidth : 0) + (withFiles ? 1 + files : 0)
  const figures = (l: { added: number; deleted: number }, reach: string): Segment[] => [
    apart(),
    { text: padStart(`+${grouped(l.added)}`, added), color: STATUS_COLOURS.ok },
    { text: ` ${padStart(`${CHANGE.removed.glyph}${grouped(l.deleted)}`, deleted)}`, color: STATUS_COLOURS.alert },
    apart(),
    { text: padStart(reach, REACH_CELLS) },
  ]
  // The reach column's title over the figures it heads, keyed apart from the card's own top edge.
  const head: Row = { key: 'branch-changed-cols', segments: [bodyIndent(pathEnd + tail - REACH_CELLS), { text: padStart('reach', REACH_CELLS), dim: true }] }
  return [
    head,
    ...shown.map((r, n): Row => {
      const at = at0 + start + n
      if (r.kind === 'file') {
        const { dir, base } = pathParts(r.f.path, Math.max(1, pathEnd - NEST))
        const path: Segment[] = [{ text: dir, dim: true }, { ...button(`row:${at}`, base), press: { id: `row:${at}`, label: r.f.path } }]
        return itemRow(`file-${at}`, at === selected, [nest(), ...padded(path.filter(s => s.text !== ''), pathEnd - NEST), ...figures(r.f, grouped(r.f.dependents))], columns)
      }
      const g = r.g
      const colour = g.outside || g.name === null ? FAINT : (boundaryColour(g.name, hues) ?? FAINT)
      const label: Segment[] = [g.outside || g.name === null ? { text: '· ', dim: true } : { text: `${SWATCH} `, color: colour }, ...padded([button(`row:${at}`, fit(labelOf(g), name))], name)]
      const glyphs = barWidth > 0 ? bar(g.added + g.deleted, most, barWidth) : ''
      const barCell: Segment[] = barWidth > 0 ? [{ text: ' ' }, { text: glyphs, color: colour }, { text: spaces(barWidth - cells(glyphs)) }] : []
      const count: Segment[] = withFiles ? [{ text: ` ${padStart(plural(g.count, 'file', 'files'), files)}`, dim: true }] : []
      return itemRow(`group-${at}`, at === selected, [...label, ...barCell, ...count, ...figures(g, g.outside ? '' : grouped(g.reach))], columns, { text: g.open ? '▾' : '▸', dim: true })
    }),
  ]
}
