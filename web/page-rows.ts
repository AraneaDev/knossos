/**
 * The pane's rows as a page draws them: every decision a host makes when it
 * turns a row into elements is made here, by the functions Claude Code's
 * hooks module makes it with, so the page only maps ready values to the
 * document. A segment's look is `textStyle`'s, its colours resolved for the
 * page's theme; a press is labelled as its Button is (`pressLabel`); a field
 * takes the room `fieldRooms` gives it; a hover card is the card's lines on
 * its ground, as wide as the pane allows; a diff is `parseHunks`' lines,
 * numbered.
 */
import { parseHunks } from '../hooks/lib/diff'
import type { Row, Segment } from '../hooks/lib/layout'
import { cells, pressLabel, textStyle } from '../hooks/lib/rows'
import { fieldRooms } from '../hooks/mod/frame'

/** A look with its colours resolved: what an element's inline style takes. */
export type Look = { color?: string; background?: string; bold?: true }

/** One segment, ready to draw. A press keeps its id and hotkey (the page's keys and focus ring go by them) and its full name as `title`. */
export type PageSegment = {
  text: string
  look: Look
  /** Display cells from the shared layout, independent of browser font metrics. */
  columns?: number
  press?: { id: string; label: string; title: string; hotkey?: string; dim?: true }
  hidden?: true
  field?: { id: string; value: string; placeholder: string; width: number }
  card?: { key: string; width: number; lines: string[] }
}

/** One diff line: its number in the gutter (padded to the hunk's widest), its marker and text, and its ground. */
export type PageLine = { gutter: string; text: string; look: Look }

/** One row, ready to draw: its segments, or for a diff its lines and their gutter's look (dim, as the element draws it). */
export type PageRow = { key: string; segments: PageSegment[]; lines?: PageLine[]; gutter?: Look }

/** The colour of a theme key under the page's theme, or undefined for one it has none for. */
export type Colour = (key: string | undefined) => string | undefined

/** A look from {@link textStyle}, its keys resolved. */
function lookOf(s: Segment, colour: Colour): Look {
  const style = textStyle(s)
  const color = colour(style.color)
  const background = colour(style.backgroundColor)
  return { ...(color === undefined ? {} : { color }), ...(background === undefined ? {} : { background }), ...(style.bold === true ? { bold: true as const } : {}) }
}

/** A diff's lines, numbered as the engine's diff element numbers them: an added or kept line by the new side, a removed one by the old. */
function linesOf(source: string, colour: Colour): PageLine[] {
  const lines: PageLine[] = []
  for (const hunk of parseHunks(source)) {
    let old = hunk.oldStart
    let now = hunk.newStart
    const width = String(Math.max(old, now) + hunk.lines.length).length
    for (const line of hunk.lines) {
      const marker = line[0]!
      const number = marker === '-' ? old++ : now++
      if (marker === ' ') old++
      const ground = colour(marker === '+' ? 'diffAdded' : marker === '-' ? 'diffRemoved' : undefined)
      lines.push({ gutter: String(number).padStart(width), text: `${marker} ${line.slice(1)}`, look: ground === undefined ? {} : { background: ground } })
    }
  }
  return lines
}

/** One segment ready to draw; a field `width` cells wide. */
function segmentOf(s: Segment, colour: Colour, columns: number, width: number | undefined): PageSegment {
  const out: PageSegment = { text: s.text, look: lookOf(s, colour), columns: cells(s.text) }
  if (s.press !== undefined) {
    out.press = { id: s.press.id, label: pressLabel(s), title: s.press.label, ...(s.press.hotkey === undefined ? {} : { hotkey: s.press.hotkey }), ...(s.dim === true ? { dim: true as const } : {}) }
  }
  if (s.hidden === true) out.hidden = true
  if (s.field !== undefined) out.field = { id: s.field.id, value: s.field.value, placeholder: s.field.placeholder, width: width ?? 1 }
  // The card as Claude Code hangs it: its lines' text on the card's ground, never wider than the pane.
  if (s.preview !== undefined) out.card = { key: s.preview.key, width: Math.min(s.preview.width, columns), lines: s.preview.rows.map(r => r.segments.map(c => c.text).join('')) }
  return out
}

/** `row` ready to draw in a pane `columns` wide: the blank cells a field takes are its own, so they are left out. */
export function pageRow(row: Row, colour: Colour, columns: number): PageRow {
  if (row.code !== undefined) return { key: row.key, segments: [], lines: linesOf(row.code.source, colour), gutter: lookOf({ text: '', dim: true }, colour) }
  const rooms = fieldRooms(row.segments, columns)
  const segments: PageSegment[] = []
  for (let i = 0; i < row.segments.length; i++) {
    const room = rooms.get(i)
    segments.push(segmentOf(row.segments[i]!, colour, columns, room?.room))
    if (room !== undefined) i = room.next - 1
  }
  return { key: row.key, segments }
}
