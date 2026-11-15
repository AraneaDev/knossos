/**
 * The pane's one spacing: the margin everything stands on, the gutter the
 * marker and a row's mark sit in, the body column after it, the blank rows
 * that part one card or group from the next, the gap between cards side by
 * side, and the shared forms built on them (a column of labelled facts, the
 * `+N more` under a list). Pure; it reads no state and draws no frame of its
 * own (the card frame in `cards.ts` lays rows on the margin).
 *
 * Measured from the pane's edge, in cells:
 *
 *     ── ◇ Title ──────────── note ──      the rule, at MARGIN
 *                                         one blank row
 *     ›▲ name   ...                       marker and mark in the GUTTER
 *        where   src/File.php:12          the body, at BODY
 */
import { ACCENT } from './palette'
import { cells, fit, grouped, padEnd, spaces, wrapWords } from './text'
import type { Row, Segment } from './types'
import type { Tier } from './table'

/** The cells between the pane's edge and everything on it: header, tab bar, rules, card titles, card rows, footer. */
export const MARGIN = 2
/** The margin of a narrow pane, which cannot spare a second cell. */
export const NARROW_MARGIN = 1
/**
 * The cells before a card's body, after the margin: the selection marker, a
 * row's mark, and the cell that keeps the mark off the body (`›▲ name`, not
 * `›▲name`).
 */
export const GUTTER = 3
/** Where a card's body starts, from the pane's edge. */
export const BODY = MARGIN + GUTTER
/** The blank rows above a card's title and below it: inside a card, only before a block of a different kind ({@link groupGap}). */
export const CARD_GAP_ROWS = 1
/** The cells between two cards side by side, rule end to rule start, so two rules never read as one. */
export const GRID_GAP = 4
/** The cells between a fact's label and its value, past the longest label. */
export const LABEL_GAP = 3

/** The margin at `tier`. */
export const marginOf = (tier: Tier): number => (tier === 'narrow' ? NARROW_MARGIN : MARGIN)

/**
 * The gutter before a body row, the one way a row fills it: the selection
 * marker `›` when `marked`, the row's own one-cell `mark` (a severity, a
 * rank, a fold, a change) or a blank, then the cell that keeps the mark off
 * the body.
 */
export function gutter(marked = false, mark?: Segment): Segment[] {
  return [{ text: marked ? '›' : ' ', color: ACCENT, bold: true }, mark ?? { text: ' ' }, { text: spaces(GUTTER - 2) }]
}

/** `segments` moved in to the body, past an empty gutter. */
export const atBody = (segments: readonly Segment[]): Segment[] => [{ text: spaces(GUTTER) }, ...segments]

/** Rows moved in to the body, past an empty gutter. */
export const toBody = (rows: readonly Row[]): Row[] => rows.map(row => ({ ...row, segments: atBody(row.segments) }))

/** A row of `segments` at the body. */
export const bodyRow = (key: string, segments: readonly Segment[]): Row => ({ key, segments: atBody(segments) })

/** One line of text at the body in a card `width` wide, cut with `…` to fit; dim unless `style` says otherwise. */
export const bodyLine = (key: string, text: string, width: number, style: Omit<Segment, 'text'> = { dim: true }): Row => bodyRow(key, [{ text: fit(text, Math.max(1, width - GUTTER)), ...style }])

/**
 * Text at the body in a card `width` wide, wrapped onto as many rows as it
 * needs (keyed `key-0` on), dim unless `style` says otherwise; a `mark`
 * (one cell: a warning) stands in the gutter before its first line.
 */
export function bodyText(key: string, text: string, width: number, style: Omit<Segment, 'text'> = { dim: true }, mark?: Segment): Row[] {
  return wrapWords(text, Math.max(1, width - GUTTER)).map((line, i): Row => ({ key: `${key}-${i}`, segments: [...(i === 0 && mark !== undefined ? gutter(false, mark) : [{ text: spaces(GUTTER) }]), { text: line, ...style }] }))
}

/** A blank row, `width` cells of space where a card's rows are all as wide as the card: the one way the pane draws one. */
export const blankRow = (key: string, width = 1): Row => ({ key, segments: [{ text: spaces(Math.max(1, width)) }] })

/**
 * The one blank row a card holds inside it: before a block of a different
 * kind (a table after a verdict line, a command line after a table). Facts
 * of one card, its rows and its list items stand on consecutive rows.
 */
export const groupGap = (key: string): Row => blankRow(key)

/** Whether `row` shows nothing: only spaces, and nothing pressed, typed in, tinted or drawn as cells or a diff. */
export function isBlank(row: Row): boolean {
  if (row.code !== undefined || row.raster !== undefined || row.tint !== undefined) return false
  return row.segments.every(s => s.text.trim() === '' && s.press === undefined && s.field === undefined && s.cell === undefined && s.preview === undefined)
}

/** `rows` with no blank row first or last and no two blank rows running, so the frame's own blank rows are never doubled. */
export function trimBlanks(rows: readonly Row[]): Row[] {
  const kept = rows.filter((row, i) => !isBlank(row) || (i > 0 && !isBlank(rows[i - 1]!)))
  let start = 0
  let end = kept.length
  while (start < end && isBlank(kept[start]!)) start++
  while (end > start && isBlank(kept[end - 1]!)) end--
  return kept.slice(start, end)
}

/**
 * What a list does not show, under it at the body: `+N more`, its count in
 * groups of three, with what was left out when `kind` names it (`+1,204 more
 * hubs`); cut to `width`, dim.
 */
export function moreRow(n: number, key: string, width = Number.POSITIVE_INFINITY, kind?: string): Row {
  const said = `+${grouped(n)} more${kind === undefined ? '' : ` ${kind}`}`
  return { key, segments: [{ text: spaces(GUTTER) }, { text: fit(said, Math.max(1, width - GUTTER)), dim: true }] }
}

/** One labelled fact: its row key, its one-word label, and its value as lines no wider than the width it is given; `numbered`, every row keyed `key-N` from 0. */
export type Fact = { key: string; label: string; lines: (width: number) => Segment[][]; numbered?: true }

/**
 * Facts of different kinds, stacked in `width` on consecutive rows with
 * their labels in one dim column and their values in the next. A value
 * wider than its column wraps under its own column, never under the label,
 * and runs straight on into the next fact. The first row of a fact is keyed
 * by it, the rest `key-1` on (all `key-N` from 0 when it is `numbered`).
 */
export function labelledRows(facts: readonly Fact[], width: number): Row[] {
  const column = Math.max(0, ...facts.map(f => cells(f.label))) + LABEL_GAP
  const room = Math.max(1, width - GUTTER - column)
  return facts.flatMap((fact): Row[] => {
    const lines = fact.lines(room)
    return (lines.length === 0 ? [[]] : lines).map((line, i): Row => ({
      key: i === 0 && fact.numbered !== true ? fact.key : `${fact.key}-${i}`,
      segments: [{ text: spaces(GUTTER) }, i === 0 ? { text: padEnd(fact.label, column), dim: true } : { text: spaces(column) }, ...line],
    }))
  })
}

/** Where a word too long for a line breaks best: just after one of these, the separators of names and paths. */
const BREAKS = /[\\/.,:(\-_]/

/**
 * Text wrapped onto rows no wider than `width`, as words; a word wider than
 * a whole row (a name or a path with no space in it) is broken, after a
 * separator in its latter half where it has one, else at the width, and
 * never cut.
 */
export function wrapValue(text: string, width: number): string[] {
  const w = Math.max(1, width)
  const lines: string[] = []
  let line = ''
  for (const word of text.split(/\s+/).filter(part => part !== '')) {
    if (cells(word) > w) {
      if (line !== '') lines.push(line)
      let rest = word
      while (cells(rest) > w) {
        const chars = Array.from(rest)
        let fits = 0
        let used = 0
        while (fits < chars.length && used + cells(chars[fits]!) <= w) used += cells(chars[fits++]!)
        // The last separator in the latter half of what fits, so a piece ends on a whole segment of the name.
        let cut = fits
        for (let i = fits; i > Math.floor(fits / 2); i--) {
          if (BREAKS.test(chars[i - 1]!)) {
            cut = i
            break
          }
        }
        lines.push(chars.slice(0, Math.max(1, cut)).join(''))
        rest = chars.slice(Math.max(1, cut)).join('')
      }
      line = rest
      continue
    }
    const next = line === '' ? word : `${line} ${word}`
    if (cells(next) <= w) line = next
    else {
      lines.push(line)
      line = word
    }
  }
  if (line !== '') lines.push(line)
  return lines
}
