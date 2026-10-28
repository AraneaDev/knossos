/**
 * Paints the pane's frames as terminal lines. The frame decides everything
 * (colours, glyphs, widths, cards); this only turns a {@link Frame} and the
 * little a terminal adds (which press has the focus, the field being edited,
 * a toast, the card in view, the scroll of a pinned body) into lines of 24-bit
 * colour text, and writes the lines that changed.
 */
import { cardPlace } from '../hooks/lib/place'
import { cells } from '../hooks/lib/text'
import type { Frame } from '../web/hub'
import type { PageRow, PageSegment } from '../web/page-rows'

/** What the terminal adds to a frame: the focus, the field being edited, a toast, the card in view and the body's scroll. */
export type Overlay = {
  /** The id of the press or field with the focus. */
  focused: string | null
  /** The field being edited: its id, its value and the cursor's place in the value. */
  field: { id: string; value: string; cursor: number } | null
  toast: string | null
  /** The key of the card to show. */
  card: string | null
  /** The first body line shown when the frame is pinned. */
  scroll: number
}

/** The terminal as the lines of one screen: each is the full SGR-coded text of a terminal row, exactly as wide as the terminal; the cursor is where a field is edited. */
export type Screen = { lines: string[]; cursor: { row: number; column: number } | null }

/** Where a segment with something to point at sits on a screen line, in 0-based cells. */
export type Hit = { column: number; width: number; press?: string; field?: string; card?: { key: string; width: number; lines: string[] } }

type Size = { columns: number; rows: number }
type Style = { fg: string | null; bg: string | null; bold: boolean; reverse: boolean; faint: boolean }
/** One terminal cell; the right half of a wide character is a cell with no text. */
type Cell = { ch: string; style: Style }
type Line = { cells: Cell[]; hits: Hit[]; cursor: number | null }

const segmenter = new Intl.Segmenter(undefined, { granularity: 'grapheme' })
const split = (text: string): string[] => Array.from(segmenter.segment(text), s => s.segment)

/** `#rrggbb` as the channels of a 24-bit SGR colour; null for anything else. */
const channels = (hex: string | null | undefined): string | null => {
  const found = hex === null || hex === undefined ? null : /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex)
  return found === null ? null : `${parseInt(found[1]!, 16)};${parseInt(found[2]!, 16)};${parseInt(found[3]!, 16)}`
}

const sameStyle = (a: Style, b: Style): boolean => a.fg === b.fg && a.bg === b.bg && a.bold === b.bold && a.reverse === b.reverse && a.faint === b.faint

const blank = (columns: number, style: Style): Line => ({ cells: Array.from({ length: columns }, () => ({ ch: ' ', style })), hits: [], cursor: null })

/**
 * Writes `text` into `line` from `column`, cut or padded to `width` cells and
 * never past the line's end. A wide character split by the edge becomes a space.
 */
function lay(line: Line, column: number, text: string, style: Style, width: number): void {
  const end = Math.min(column + width, line.cells.length)
  if (column >= end) return
  const row = line.cells
  if (row[column]!.ch === '' && column > 0) row[column - 1] = { ch: ' ', style: row[column - 1]!.style }
  let at = column
  for (const grapheme of split(text)) {
    const w = cells(grapheme)
    if (w === 0) continue
    if (at + w > end) break
    row[at] = { ch: grapheme, style }
    if (w === 2) row[at + 1] = { ch: '', style }
    at += w
  }
  while (at < end) row[at++] = { ch: ' ', style }
  if (end < row.length && row[end]!.ch === '') row[end] = { ch: ' ', style: row[end]!.style }
}

/** The last graphemes of `text` that fit in `room` cells. */
function tail(text: string, room: number): string {
  let used = 0
  let out = ''
  for (const g of split(text).reverse()) {
    used += cells(g)
    if (used > room) break
    out = g + out
  }
  return out
}

/** The SGR text of a line: one escape sequence per run of cells that share a style. */
function render(line: Line): string {
  let out = ''
  let run = ''
  let style: Style | null = null
  const flush = () => {
    if (style === null) return
    const fg = channels(style.fg)
    const bg = channels(style.bg)
    const colour = [...(fg === null ? [] : [`38;2;${fg}`]), ...(bg === null ? [] : [`48;2;${bg}`])]
    // Reset, then each attribute as its own sequence, then the colours.
    out += `\x1b[0m${style.bold ? '\x1b[1m' : ''}${style.faint ? '\x1b[2m' : ''}${style.reverse ? '\x1b[7m' : ''}${colour.length === 0 ? '' : `\x1b[${colour.join(';')}m`}${run}`
  }
  for (const cell of line.cells) {
    if (style === null || !sameStyle(style, cell.style)) {
      flush()
      style = cell.style
      run = ''
    }
    run += cell.ch
  }
  flush()
  return `${out}\x1b[0m`
}

/** What a row paints with: the frame's own colours and the overlay. */
type Context = { frame: Frame; columns: number; overlay: Overlay; base: Style }

/** `base` under a segment's look. */
const styleOf = (base: Style, look: PageSegment['look']): Style => ({ ...base, fg: look.color ?? base.fg, bg: look.background ?? base.bg, bold: look.bold === true })

/** The cells a segment takes: its columns, else its text's characters; a field takes its width. */
const widthOf = (s: PageSegment): number => s.field?.width ?? s.columns ?? [...s.text].length

/** One segment laid into `line` at `column`; the cursor's column when it is the field being edited. */
function put(line: Line, column: number, s: PageSegment, ctx: Context): void {
  const width = widthOf(s)
  let style = styleOf(ctx.base, s.look)
  const id = s.press?.id ?? s.field?.id
  if (id !== undefined && ctx.overlay.focused === id) style = { ...style, reverse: true }
  if (s.field !== undefined) {
    const edited = ctx.overlay.field?.id === s.field.id ? ctx.overlay.field : null
    const value = edited === null ? s.field.value : edited.value
    if (value === '') lay(line, column, s.field.placeholder, { ...style, faint: true }, width)
    else if (edited === null || cells(value) < width) lay(line, column, value, style, width)
    else {
      // Longer than the field: the end up to the cursor stays in view.
      const shown = tail(value.slice(0, edited.cursor), width - 1)
      lay(line, column, shown, style, width)
    }
    if (edited !== null) {
      const before = value.slice(0, Math.max(0, edited.cursor))
      line.cursor = column + Math.min(width - 1, cells(cells(value) < width ? before : tail(before, width - 1)))
    }
    line.hits.push({ column, width, field: s.field.id })
    return
  }
  if (s.press !== undefined) {
    const hotkey = s.press.hotkey
    lay(line, column, hotkey === undefined ? s.press.label : `${hotkey}: ${s.press.label}`, style, width)
    if (hotkey !== undefined) lay(line, column, hotkey, { ...style, fg: ctx.frame.colours.suggestion }, Math.min(width, cells(hotkey)))
  } else lay(line, column, s.text, style, width)
  const hit: Hit = { column, width }
  if (s.press !== undefined) hit.press = s.press.id
  if (s.card !== undefined) hit.card = s.card
  if (hit.press !== undefined || hit.card !== undefined) line.hits.push(hit)
}

/** The screen lines of one row: one for a row of segments, one per line for a diff. */
function linesOfRow(row: PageRow, ctx: Context): Line[] {
  if (row.lines !== undefined) {
    return row.lines.map(l => {
      const line = blank(ctx.columns, ctx.base)
      const gutter = styleOf(ctx.base, row.gutter ?? {})
      lay(line, 0, l.gutter, gutter, cells(l.gutter))
      lay(line, cells(l.gutter), ' ', ctx.base, 1)
      lay(line, cells(l.gutter) + 1, l.text, styleOf(ctx.base, l.look), ctx.columns)
      return line
    })
  }
  const line = blank(ctx.columns, ctx.base)
  let column = 0
  for (const s of row.segments) {
    if (s.hidden === true) continue
    put(line, column, s, ctx)
    column += widthOf(s)
  }
  return [line]
}

const linesOfRows = (rows: PageRow[], ctx: Context): Line[] => rows.flatMap(row => linesOfRow(row, ctx))

/** The screen lines each part of a frame takes: a diff row takes one per line it has. */
export function linesOf(frame: Frame): { body: number; footer: number } {
  const count = (rows: PageRow[]) => rows.reduce((n, row) => n + (row.lines === undefined ? 1 : row.lines.length), 0)
  return { body: count(frame.body), footer: count(frame.footer) }
}

/** The frame laid out on `size.rows` lines: unpinned, body then footer cut at the bottom; pinned, the footer at the bottom and the body from `scroll` above it. `bodyEnd` is the line after the last body line. */
function layout(frame: Frame, size: Size, overlay: Overlay): { lines: Line[]; bodyEnd: number } {
  const base: Style = { fg: frame.colours.text, bg: frame.colours.ground, bold: false, reverse: false, faint: false }
  const ctx: Context = { frame, columns: size.columns, overlay, base }
  const body = linesOfRows(frame.body, ctx)
  const footer = linesOfRows(frame.footer, ctx)
  const empty = () => blank(size.columns, base)
  if (!frame.pinned) {
    const lines = [...body, ...footer].slice(0, size.rows)
    const bodyEnd = Math.min(body.length, size.rows)
    while (lines.length < size.rows) lines.push(empty())
    return { lines, bodyEnd }
  }
  const kept = Math.min(footer.length, size.rows)
  const room = size.rows - kept
  const first = Math.min(Math.max(0, overlay.scroll), Math.max(0, body.length - room))
  const shown = body.slice(first, first + room)
  while (shown.length < room) shown.push(empty())
  return { lines: [...shown, ...footer.slice(0, kept)], bodyEnd: room }
}

/** The frame painted at `size` under `overlay`. */
export function screenOf(frame: Frame, size: Size, overlay: Overlay): Screen {
  const { lines, bodyEnd } = layout(frame, size, overlay)
  const base: Style = { fg: frame.colours.text, bg: frame.colours.ground, bold: false, reverse: false, faint: false }
  if (overlay.toast !== null && bodyEnd > 0) {
    const line = blank(size.columns, { ...base, reverse: true })
    lay(line, 0, ` ${overlay.toast}`, { ...base, reverse: true }, size.columns)
    lines[bodyEnd - 1] = line
  }
  const at = lines.findIndex(line => line.cursor !== null)
  const cursor: Screen['cursor'] = at < 0 ? null : { row: at, column: lines[at]!.cursor! }
  if (overlay.card !== null) {
    for (let row = 0; row < lines.length; row++) {
      const hit = lines[row]!.hits.find(h => h.card?.key === overlay.card)
      if (hit?.card === undefined) continue
      const width = Math.min(hit.card.width, size.columns)
      const place = cardPlace(row, hit.column, { rows: hit.card.lines.length, columns: width }, size)
      const style: Style = { ...base, bg: frame.colours.card }
      hit.card.lines.forEach((text, i) => {
        const target = lines[place.row + i]
        if (target !== undefined && place.row + i >= 0) lay(target, place.column, text, style, width)
      })
      break
    }
  }
  return { lines: lines.map(render), cursor }
}

/** Where the segments with something to point at sit on the screen `screenOf` paints, a list per line. */
export function hitsOf(frame: Frame, size: Size, scroll: number): Hit[][] {
  return layout(frame, size, { focused: null, field: null, toast: null, card: null, scroll }).lines.map(line => line.hits)
}

/** The bytes that turn `before` into `after`: each line that differs at its place, then the cursor shown or hidden. A null `before` writes every line. */
export function bytesFor(before: Screen | null, after: Screen): string {
  let out = ''
  after.lines.forEach((line, row) => {
    if (before === null || before.lines[row] !== line) out += `\x1b[${row + 1};1H${line}\x1b[0m`
  })
  return out + (after.cursor === null ? '\x1b[?25l' : `\x1b[${after.cursor.row + 1};${after.cursor.column + 1}H\x1b[?25h`)
}
