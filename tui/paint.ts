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
/** A colour's red, green and blue, 0-255 each. */
export type Rgb = [number, number, number]
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

/**
 * A background as it sits on the terminal's own `ground` rather than the
 * theme's: moved by the difference between the two, so a tint keeps its
 * distance from whatever the terminal is. With no ground known, as it is.
 */
export function onGround(hex: string | undefined, theme: string, ground: Rgb | null): string | undefined {
  const from = channels(theme)
  const value = channels(hex)
  if (ground === null || from === null || value === null) return hex
  const base = from.split(';').map(Number)
  const moved = value.split(';').map((c, i) => Math.min(255, Math.max(0, Number(c) - base[i]! + ground[i]!)))
  return `#${moved.map(c => c.toString(16).padStart(2, '0')).join('')}`
}

const sameStyle = (a: Style, b: Style): boolean => a.fg === b.fg && a.bg === b.bg && a.bold === b.bold && a.reverse === b.reverse && a.faint === b.faint

const blank = (columns: number, style: Style): Line => ({ cells: Array.from({ length: columns }, () => ({ ch: ' ', style })), hits: [], cursor: null })

/**
 * `text` as a terminal may be given it: a tab is two spaces, as the Claude pane
 * draws a diff's, and any other control character is a replacement mark, so that
 * nothing in it moves the cursor or starts a sequence.
 */
// eslint-disable-next-line no-control-regex -- finding control characters is what it is for
const printable = (text: string): string => text.replace(/\t/g, '  ').replace(/[\u0000-\u001f\u007f-\u009f]/g, '\ufffd')

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
  for (const grapheme of split(printable(text))) {
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

/** `text` without its first `n` cells (a wide character cut by that edge goes with them). */
function dropCells(text: string, n: number): string {
  let used = 0
  let at = 0
  const graphemes = split(text)
  while (at < graphemes.length && used < n) used += cells(graphemes[at++]!)
  return graphemes.slice(at).join('')
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

/** What a row paints with: the frame's own colours, the overlay, and a background moved onto the terminal's ground. */
type Context = { frame: Frame; columns: number; overlay: Overlay; base: Style; tint: (hex: string | undefined) => string | undefined }

/** `base` under a segment's look, its background on the terminal's ground. */
const styleOf = (ctx: Context, base: Style, look: PageSegment['look']): Style => ({ ...base, fg: look.color ?? base.fg, bg: ctx.tint(look.background) ?? base.bg, bold: look.bold === true })

/** The cells a segment takes: its columns, else its text's characters; a field takes its width. */
const widthOf = (s: PageSegment): number => s.field?.width ?? s.columns ?? [...s.text].length

/** One segment laid into `line` at `column`; the cursor's column when it is the field being edited. */
function put(line: Line, column: number, s: PageSegment, ctx: Context): void {
  const width = widthOf(s)
  let style = styleOf(ctx, ctx.base, s.look)
  const id = s.press?.id ?? s.field?.id
  if (id !== undefined && ctx.overlay.focused === id) style = { ...style, reverse: true }
  if (s.field !== undefined) {
    const edited = ctx.overlay.field?.id === s.field.id ? ctx.overlay.field : null
    const value = edited === null ? s.field.value : edited.value
    if (value === '') lay(line, column, s.field.placeholder, { ...style, faint: true }, width)
    else {
      // Longer than the field: the window starts so that the cursor, when there is one, is inside it.
      const upTo = edited === null ? 0 : cells(value.slice(0, Math.max(0, edited.cursor)))
      lay(line, column, dropCells(value, Math.max(0, upTo - (width - 1))), style, width)
      if (edited !== null) line.cursor = column + Math.min(width - 1, upTo)
    }
    line.hits.push({ column, width, field: s.field.id })
    return
  }
  if (s.press !== undefined) {
    const hotkey = s.press.hotkey
    lay(line, column, hotkey === undefined ? s.press.label : `${hotkey}: ${s.press.label}`, style, width)
    if (hotkey !== undefined) lay(line, column, hotkey, { ...style, fg: ctx.frame.colours.suggestion, faint: s.press.dim === true }, Math.min(width, cells(hotkey)))
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
      // The gutter sits inside the tinted line, as the page nests it.
      const ground = styleOf(ctx, ctx.base, l.look)
      lay(line, 0, l.gutter, styleOf(ctx, ground, row.gutter ?? {}), cells(l.gutter))
      lay(line, cells(l.gutter), ' ', ground, 1)
      lay(line, cells(l.gutter) + 1, l.text, ground, ctx.columns)
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

/** Whether the footer stays at the bottom with the body scrolling above it: a pinned frame, and one too tall for the terminal. */
export function pinnedFor(frame: Frame, size: Size): boolean {
  const { body, footer } = linesOf(frame)
  return frame.pinned || body + footer > size.rows
}

/** The screen lines of the body that are not under the footer: what a pinned body scrolls through. */
export function roomOf(frame: Frame, size: Size): number {
  return size.rows - Math.min(linesOf(frame).footer, size.rows)
}

/** The first body line (0-based, in the whole body) of the press or field `id`; null when the body has none. */
export function bodyLineOf(frame: Frame, id: string): number | null {
  let at = 0
  for (const row of frame.body) {
    if (row.lines === undefined && row.segments.some(s => s.hidden !== true && (s.press?.id === id || s.field?.id === id))) return at
    at += row.lines === undefined ? 1 : row.lines.length
  }
  return null
}

/**
 * The ink every cell starts with: the theme's text on no background at all,
 * so the terminal's own shows through whatever colour it is.
 */
const baseOf = (frame: Frame): Style => ({ fg: frame.colours.text, bg: null, bold: false, reverse: false, faint: false })

/** The frame laid out on `size.rows` lines: unpinned and fitting, body then footer; otherwise the footer at the bottom and the body from `scroll` above it. `bodyEnd` is the line after the last body line. */
function layout(frame: Frame, size: Size, overlay: Overlay, ground: Rgb | null): { lines: Line[]; bodyEnd: number } {
  const base = baseOf(frame)
  const tint = (hex: string | undefined) => onGround(hex, frame.colours.ground, ground)
  const ctx: Context = { frame, columns: size.columns, overlay, base, tint }
  const body = linesOfRows(frame.body, ctx)
  const footer = linesOfRows(frame.footer, ctx)
  const empty = () => blank(size.columns, base)
  if (!pinnedFor(frame, size)) {
    const lines = [...body, ...footer]
    const bodyEnd = body.length
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

/** The frame painted at `size` under `overlay`, its backgrounds on the terminal's `ground` when that is known. */
export function screenOf(frame: Frame, size: Size, overlay: Overlay, ground: Rgb | null = null): Screen {
  const { lines, bodyEnd } = layout(frame, size, overlay, ground)
  const base = baseOf(frame)
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
      const style: Style = { ...base, bg: onGround(frame.colours.card, frame.colours.ground, ground) ?? null }
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
  return layout(frame, size, { focused: null, field: null, toast: null, card: null, scroll }, null).lines.map(line => line.hits)
}

/** The bytes that turn `before` into `after`: each line that differs at its place, then the cursor shown or hidden. A null `before` writes every line. */
export function bytesFor(before: Screen | null, after: Screen): string {
  let out = ''
  after.lines.forEach((line, row) => {
    if (before === null || before.lines[row] !== line) out += `\x1b[${row + 1};1H${line}\x1b[0m`
  })
  return out + (after.cursor === null ? '\x1b[?25l' : `\x1b[${after.cursor.row + 1};${after.cursor.column + 1}H\x1b[?25h`)
}
