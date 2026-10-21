/**
 * The rows every view of the pane builds: measuring them, pressable and
 * tinted segments, laying segments out across a width, cutting them to it,
 * wrapping groups of them onto rows, and the small rows lists end in. Pure;
 * nothing here reads state. Text is measured and cut in `text.ts`, the
 * self-fitting table is in `table.ts`, boundary chips in `chips.ts`.
 */
import { ACCENT, SECONDARY, SELECTED_BG } from './palette'
import { cells, fit, grouped, spaces } from './text'
import type { Row, Segment } from './types'

export const rowWidth = (row: Row): number => row.segments.reduce((n, s) => n + cells(s.text), 0)
/**
 * The rows `row` takes on screen: any row one, and a diff element one per
 * line under its `@@` header, since it wraps nothing (`truncate-end`) and
 * cuts a line at the edge. The header itself is drawn as no row: the
 * element numbers the lines in its gutter instead. Neither the engine's
 * types nor its docs say so; a live session showed it, a hunk of a header
 * and seven lines drawn in seven rows. What the pane counts its height and
 * its scrolling in. Each element holds one hunk (see `hunkSource`), and
 * never a header alone: `parseHunks` passes a hunk with no lines by, and
 * `hunkSource` keeps at least one line of the rest.
 */
export const rowHeight = (row: Row): number => (row.code === undefined ? 1 : row.code.source.split('\n').length - 1)
/** The rows `rows` take on screen together (see {@link rowHeight}). */
export const rowsHeight = (rows: readonly Row[]): number => rows.reduce((n, row) => n + rowHeight(row), 0)
export const segmentsWidth = (segments: Segment[]): number => segments.reduce((n, s) => n + cells(s.text), 0)

/** A pressable segment; its text is what the terminal draws for it. */
export function button(id: string, label: string, hotkey?: string, style: Omit<Segment, 'text' | 'press'> = {}): Segment {
  const text = hotkey === undefined ? label : `${hotkey}: ${label}`
  return { ...style, text, press: hotkey === undefined ? { id, label } : { id, label, hotkey } }
}

/**
 * The label a pressable segment's Button is drawn with: the segment's own
 * text, less the `k: ` the engine draws before a hotkey's label itself. The
 * layout fits a segment's text to the cells it has (a box's name is cut with
 * an ellipsis), so the Button draws exactly what was laid out; `press.label`
 * may still hold the whole name, which would widen the row or wrap it.
 */
export function pressLabel(s: Segment): string {
  const hotkey = s.press?.hotkey
  const prefix = hotkey === undefined ? '' : `${hotkey}: `
  return prefix !== '' && s.text.startsWith(prefix) ? s.text.slice(prefix.length) : s.text
}

/** How a segment's `Text` is styled: its colour, else `inactive` when dim; bold; the background laid under it. */
export function textStyle(s: Segment): { color?: string; bold?: boolean; backgroundColor?: string } {
  const color = s.color ?? (s.dim ? SECONDARY : undefined)
  return { ...(color === undefined ? {} : { color }), ...(s.bold ? { bold: true } : {}), ...(s.bg === undefined ? {} : { backgroundColor: s.bg }) }
}

/** `segments` with `bg` laid under each that has none of its own. */
export const tinted = (segments: Segment[], bg: string): Segment[] => segments.map(s => (s.bg === undefined ? { ...s, bg } : s))

/**
 * A row of `segments`, laid on `bg` (the marked row's ground unless another
 * is named) and carrying it as its tint when `on`, as it is otherwise.
 * `columns`, when given, pads a tinted row out to that width first, so the
 * ground runs to the pane's edge.
 */
export function tintedRow(key: string, segments: Segment[], on: boolean, { bg = SELECTED_BG, columns }: { bg?: string; columns?: number } = {}): Row {
  if (!on) return { key, segments }
  const used = segmentsWidth(segments)
  const padded = columns !== undefined && used < columns ? [...segments, { text: spaces(columns - used) }] : segments
  return { key, segments: tinted(padded, bg), tint: bg }
}

/** A row of a list the marker walks: `›` when marked, then `segments`, cut to `columns`, tinted to the edge when marked. */
export function walkedRow(key: string, marked: boolean, segments: Segment[], columns: number): Row {
  return tintedRow(key, clip([{ text: marked ? '›' : ' ', color: ACCENT, bold: true }, ...segments], columns), marked, { columns })
}

/** Segments laid out as `left`, a gap, then `right` against the right edge; `right` goes first when both do not fit. */
export function spread(key: string, left: Segment[], right: Segment[], columns: number): Row {
  const gap = columns - segmentsWidth(left) - segmentsWidth(right)
  if (right.length > 0 && gap >= 1) return { key, segments: [...left, { text: spaces(gap) }, ...right] }
  return { key, segments: clip(left, columns) }
}

/**
 * Segments cut to `columns`: the last text segment that crosses the edge is
 * truncated with an ellipsis, and nothing after it is kept. A pressable
 * segment is never cut: it is dropped whole, since its label is its address.
 */
export function clip(segments: Segment[], columns: number): Segment[] {
  const out: Segment[] = []
  let used = 0
  for (const s of segments) {
    const w = cells(s.text)
    if (used + w <= columns) {
      out.push(s)
      used += w
      continue
    }
    if (s.press === undefined && s.field === undefined && columns - used > 0) out.push({ ...s, text: fit(s.text, columns - used) })
    break
  }
  return out
}

/**
 * Groups of segments wrapped onto rows: each group stays whole on one row,
 * `gap` apart, and a group wider than a row is clipped to it.
 */
export function wrapGroups(key: string, groups: Segment[][], columns: number, gap = 2, indent = 0): Row[] {
  const rows: Row[] = []
  let line: Segment[] = []
  let used = 0
  const flush = () => {
    if (line.length > 0) rows.push({ key: rows.length === 0 ? key : `${key}-${rows.length}`, segments: line })
    line = []
    used = 0
  }
  for (const group of groups) {
    const w = segmentsWidth(group)
    const lead = line.length === 0 ? indent : gap
    if (line.length > 0 && used + lead + w > columns) flush()
    const pad = line.length === 0 ? indent : gap
    if (pad > 0) line.push({ text: spaces(pad) })
    line.push(...clip(group, columns - used - pad))
    used = segmentsWidth(line)
  }
  flush()
  return rows
}

export const blank = (key: string): Row => ({ key, segments: [{ text: ' ' }] })
export const dimRow = (key: string, text: string, columns: number): Row => ({ key, segments: [{ text: fit(text, columns), dim: true }] })

/**
 * The `+N not listed` under a list that holds fewer than its count: none when
 * it holds them all. With a `kind` it says what was left out, its count in
 * groups of three: `+1,204 more hubs not listed`; `indent` is the cells
 * before the `+`.
 */
export const notListedRow = (key: string, count: number, shown: number, columns: number, options: { kind?: string; indent?: number } = {}): Row[] => {
  if (count <= shown) return []
  const left = count - shown
  const said = options.kind === undefined ? `+${left}` : `+${grouped(left)} more ${options.kind}`
  return [dimRow(key, `${spaces(options.indent ?? 3)}${said} not listed`, columns)]
}

/** The marker's index in a list `length` long (`local`, its place counted from the list's first item), or -1 when it is not in the list. */
export const inList = (local: number, length: number): number => (local >= 0 && local < length ? local : -1)
