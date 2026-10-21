/**
 * The pane's tiers, its bars, and the table that fits itself to the columns
 * it has. Pure; nothing here reads state.
 *
 * Bars are thin rules (`━`, in half cells) on a faint dotted track, so a
 * column of them reads as a set of gauges rather than a block of colour.
 *
 * A table is packed to the left: names take what the longest needs (up to a
 * cap), the kind (where the table has one) and the boundary follow them,
 * then the bar, the numbers and, where the table has one, the file each row
 * is declared in. The bar takes the width left after the names and numbers,
 * within a minimum and a maximum share of the table and a cap in cells that
 * depend on the pane's {@link Tier}; width past that stays at the right
 * edge, never between a name and its boundary. As the width shrinks it gives
 * way in a fixed order: the kind goes, the file column goes, the bars
 * shorten to their minimum, then names are cut with an ellipsis, then the
 * boundary column goes, then the bars go. The numbers always stay.
 */
import { ACCENT, FAINT, FLASH_BG, HEADING, NO_HUES, SELECTED_BG } from './palette'
import type { Hues } from './palette'
import { RANKING, kindMark, mark } from './marks'
import { opensFile } from './editor'
import { boundaryStyle, chip } from './chips'
import { button, segmentsWidth, tinted } from './rows'
import { cells, compact, fit, fitStart, grouped, padEnd, padStart, pathParts, spaces } from './text'
import type { Loc, Preview, Row, Segment } from './types'

export const BAR_MIN = 4

/**
 * The pane's three layouts, by the columns its body has: `narrow` (below 80)
 * one column with light cards, `medium` (80 to 130) one column of framed
 * cards whose tables add columns, `wide` (above 130) a two-column grid.
 */
export type Tier = 'narrow' | 'medium' | 'wide'
/** The first width of the medium tier, and the last. */
export const MEDIUM_MIN = 80
export const MEDIUM_MAX = 130
export const tierOf = (columns: number): Tier => (columns < MEDIUM_MIN ? 'narrow' : columns <= MEDIUM_MAX ? 'medium' : 'wide')

/**
 * The share of a table's width its bars take at least and at most, per tier,
 * and the most cells a bar takes however wide the table: a bar is a gauge
 * beside a name, never the widest thing on its row. Width a bar may not take
 * goes to more columns (a wide table adds the kind and the dependent files)
 * or stays at the right edge.
 */
export const BAR_SHARE: Record<Tier, { min: number; max: number; cap: number }> = {
  narrow: { min: 0.08, max: 0.25, cap: 16 },
  medium: { min: 0.1, max: 0.25, cap: 24 },
  wide: { min: 0.1, max: 0.25, cap: 30 },
}
/** How many cells a bar may take in a table `columns` wide at `tier`. */
export function barRange(columns: number, tier: Tier): { min: number; max: number } {
  const share = BAR_SHARE[tier]
  const max = Math.max(BAR_MIN, Math.min(share.cap, Math.floor(columns * share.max)))
  return { min: Math.min(max, Math.max(BAR_MIN, Math.floor(columns * share.min))), max }
}
/** The glyph a bar's empty part (its track) is drawn with, faint. */
export const TRACK = '·'
export const NAME_MIN = 8
const NAME_MAX = 32
const BOUNDARY_MAX = 12
/** The widest a file column grows. */
const PLACE_MAX = 36
/** The cells a kind mark takes before a name: its letter and a space. */
const KIND_CELLS = 2
/** The selection marker, the hotspot mark and a space. */
export const MARK = 3

/** A whole cell of a bar, and its left half. */
export const BAR_FULL = '━'
export const BAR_HALF = '╸'

/**
 * A horizontal bar for `value` out of `max` in at most `width` cells, in
 * half cells. Anything above zero shows at least a half.
 */
export function bar(value: number, max: number, width: number): string {
  if (width <= 0 || max <= 0 || value <= 0) return ''
  const halves = Math.max(1, Math.round((Math.min(value, max) / max) * width * 2))
  return BAR_FULL.repeat(Math.floor(halves / 2)) + (halves % 2 === 1 ? BAR_HALF : '')
}

/** How a table of names, boundaries, a bar, numbers and (optionally) files fits `columns`; `compact` numbers (`31.7k`) on a narrow pane. */
export type TableSpec = { name: number; boundary: number; bar: number; numbers: number[]; place?: number; kind?: number; compact?: true }

/**
 * What a table may add beyond names and numbers: the pane's tier (its bar
 * shares), each row's file, each row's kind, and whether its numbers are
 * compact (narrow, unless the table is a detail's, whose figures stay whole).
 */
export type TableOptions = { tier?: Tier; places?: string[]; kinds?: string[]; compact?: boolean }

/**
 * Fits a table to `columns` (see the module docblock for the order things
 * give way in). `numbers` holds each number column's widest cell, title
 * included; the first number column is the one the bar draws. On a narrow
 * pane `nameMax` caps how wide names grow before the bar takes the rest
 * (paths want more); from the medium tier on names grow whole while the bar
 * keeps its minimum share.
 * With `places` the table ends in a file column while it fits beside a bar
 * at its minimum and names uncut; with `kinds` a kind column follows the
 * names while that fits too.
 */
export function tableSpec(columns: number, names: string[], boundaries: string[], numbers: number[], nameMax = NAME_MAX, options: TableOptions = {}): TableSpec {
  const range = barRange(columns, options.tier ?? 'narrow')
  const compacted = (options.compact ?? options.tier === 'narrow') ? { compact: true as const } : {}
  const longest = Math.max(1, ...names.map(cells))
  // From the medium tier on a whole name wins over a longer gauge; narrow, names stop at their cap so the bars still compare.
  const nameNeed = (options.tier ?? 'narrow') === 'narrow' ? Math.min(nameMax, longest) : longest
  // A boundary is a chip: its swatch and a space before its label.
  const boundaryLongest = Math.max(0, ...boundaries.map(cells))
  const boundaryNeed = boundaryLongest === 0 ? 0 : Math.min(BOUNDARY_MAX, boundaryLongest) + 2
  const placeNeed = Math.min(PLACE_MAX, Math.max(0, ...(options.places ?? []).map(cells)))
  // A kind is a two-cell mark before the name (its letter and a space), so a table with kinds always has room for it.
  const kindNeed = (options.kinds ?? []).length > 0 ? KIND_CELLS : 0
  // Beside a file or kind column a name may stop at the typical one's width: the few longest are cut rather than the column lost.
  const typical = Math.min(nameNeed, percentile(names.map(cells), 0.9))
  const attempt = (withBoundary: boolean, withBar: boolean, place: number, kind = 0): TableSpec | null => {
    const boundary = withBoundary && boundaryNeed > 0 ? boundaryNeed : 0
    const fixed = MARK + numbers.reduce((n, w) => n + 1 + w, 0) + (place > 0 ? place + 1 : 0) + kind
    const avail = columns - fixed - (boundary > 0 ? boundary + 1 : 0)
    const placed = { ...(place > 0 ? { place } : {}), ...(kind > 0 ? { kind } : {}), ...compacted }
    if (!withBar) return avail >= Math.min(NAME_MIN, nameNeed) ? { name: Math.min(avail, nameNeed), boundary, bar: 0, numbers, ...placed } : null
    const barWidth = Math.min(range.max, Math.max(range.min, avail - 1 - nameNeed))
    // Room the bar may not take goes to a name past its cap: a whole name says more than a longer gauge.
    const name = Math.min(Math.max(nameNeed, Math.min(longest, avail - 1 - barWidth)), avail - 1 - barWidth)
    // A file or kind column only beside names whole but for the longest few: the name is what the row is about.
    if ((place > 0 || kind > 0) && name < typical) return null
    return name >= Math.min(NAME_MIN, nameNeed) ? { name, boundary, bar: barWidth, numbers, ...placed } : null
  }
  return (
    (placeNeed > 0 ? attempt(true, true, placeNeed, kindNeed) : null) ??
    attempt(true, true, 0, kindNeed) ??
    attempt(false, true, 0, kindNeed) ??
    attempt(false, false, 0) ?? { name: Math.max(1, columns - MARK - numbers.reduce((n, w) => n + 1 + w, 0)), boundary: 0, bar: 0, numbers, ...compacted }
  )
}

/** The value `at` (0 to 1) of the way up `values` sorted; 1 for none. */
export function percentile(values: number[], at: number): number {
  if (values.length === 0) return 1
  const sorted = [...values].sort((a, b) => a - b)
  return sorted[Math.min(sorted.length - 1, Math.ceil(at * sorted.length) - 1)] ?? 1
}

/** A column-title row for a table, dim. */
export function tableHead(key: string, spec: TableSpec, titles: { name: string; boundary: string; numbers: string[]; place?: string; kind?: string }): Row {
  let text = spaces(MARK + (spec.kind ?? 0)) + padEnd(fit(titles.name, spec.name), spec.name)
  // A title that would be cut says nothing: the column's colours say what it is.
  if (spec.boundary > 0) text += ` ${padEnd(cells(titles.boundary) <= spec.boundary ? titles.boundary : '', spec.boundary)}`
  if (spec.bar > 0) text += ` ${spaces(spec.bar)}`
  spec.numbers.forEach((w, i) => (text += ` ${padStart(titles.numbers[i] ?? '', w)}`))
  if ((spec.place ?? 0) > 0) text += ` ${fit(titles.place ?? 'where', spec.place ?? 0)}`
  return { key, segments: [{ text, dim: true }] }
}

export type TableLine = {
  name: string
  boundary: string | null
  values: number[]
  max: number
  /** The value the bar draws, when not the first number. */
  barValue?: number
  selected?: boolean
  hotspotOnly?: boolean
  press?: string
  /** The name is a path: drawn in two tones and cut in its directory, never its file name (see {@link pathParts}). */
  path?: boolean
  /** Where the name's file is: pressing the name opens that file's detail. */
  opens?: Loc | null
  /** The one-cell mark before the name, when not the hotspot mark. */
  mark?: Segment
  /** The number column the list is sorted by: the others are drawn dim beside it. */
  sorted?: number
  /** What kind of thing the row is (class, method, ...), for a table with a kind column: drawn dim. */
  kind?: string
  /** The file (and line) the row is declared in, for a table with a file column: a link to it. */
  place?: string
  placeLoc?: Loc | null
  /** The card shown while the pointer rests on the row. */
  preview?: Preview
  /** Changed in the latest scan: lit on the flash ground for a moment (the marked row keeps its own). */
  lit?: boolean
}

/**
 * One table row: marker, name (pressable when it has an id), boundary as a
 * chip, bar and numbers. The boundary's colour is on its swatch and its
 * bar, never on its name. The marked row keeps its `›` (a surface without
 * backgrounds still shows which) and is tinted across.
 */
export function tableRow(key: string, line: TableLine, spec: TableSpec, hues: Hues = NO_HUES): Row {
  const style = boundaryStyle(line.boundary, hues)
  // A path in two tones, its directory dim and its file name in the text tone; only the file name is pressed.
  const parts = line.path === true ? pathParts(line.name, spec.name) : { dir: '', base: fit(line.name, spec.name) }
  // The press keeps the whole name as its label; the Button draws only the cells laid out for it (`pressLabel`).
  const named = line.press === undefined ? opensFile(parts.base, line.opens ?? null) : { ...button(line.press, parts.base), press: { id: line.press, label: line.name } }
  const segments: Segment[] = [
    { text: line.selected ? '›' : ' ', color: ACCENT, bold: true },
    line.mark ?? (line.hotspotOnly ? mark(RANKING.hotspotOnly) : { text: ' ' }),
    { text: ' ' },
    ...((spec.kind ?? 0) > 0 ? [kindMark(line.kind)] : []),
    { text: parts.dir, dim: true },
    line.preview === undefined ? named : { ...named, preview: line.preview },
    { text: spaces(spec.name - cells(parts.dir) - cells(parts.base)) },
  ]
  if (spec.boundary > 0) {
    // A chip: the boundary's colour on its swatch, its name in a text tone, the same on every row.
    const chipped = chip(line.boundary, hues, spec.boundary)
    segments.push({ text: ' ' }, ...chipped, { text: spaces(spec.boundary - segmentsWidth(chipped)) })
  }
  if (spec.bar > 0) {
    const glyphs = bar(line.barValue ?? line.values[0] ?? 0, line.max, spec.bar)
    segments.push({ text: ' ' }, { text: glyphs, ...style }, { text: TRACK.repeat(spec.bar - cells(glyphs)), color: FAINT })
  }
  spec.numbers.forEach((w, i) => {
    const text = ` ${padStart((spec.compact ? compact : grouped)(line.values[i] ?? 0), w)}`
    segments.push(line.sorted === undefined || line.sorted === i ? { text, color: HEADING } : { text, dim: true })
  })
  // Files are left-aligned and cut from the front: a column of them reads down the file names.
  if ((spec.place ?? 0) > 0 && (line.place ?? '') !== '') segments.push({ text: ' ' }, opensFile(fitStart(line.place ?? '', spec.place ?? 0), line.placeLoc ?? null, { dim: true }))
  const kept = segments.filter(s => s.text !== '')
  if (line.selected) return { key, segments: tinted(kept, SELECTED_BG), tint: SELECTED_BG }
  return line.lit === true ? { key, segments: tinted(kept, FLASH_BG), tint: FLASH_BG } : { key, segments: kept }
}

/** A number column's width: its title, or its widest figure as the table prints it (`compact` on a narrow pane). */
export const numberWidth = (title: string, values: number[], tier?: Tier, compacted = tier === 'narrow') =>
  Math.max(cells(title), ...values.map(v => cells(compacted ? compact(v) : grouped(v))))
