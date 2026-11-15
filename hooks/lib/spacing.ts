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
import { cells, fit, grouped, padEnd, spaces } from './text'
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
/** The blank rows above a card's title, below it, and between two groups inside a card. */
export const CARD_GAP_ROWS = 1
/** The cells between two cards side by side, rule end to rule start, so two rules never read as one. */
export const GRID_GAP = 4
/** The cells between a fact's label and its value, past the longest label. */
export const LABEL_GAP = 3

/** The margin at `tier`. */
export const marginOf = (tier: Tier): number => (tier === 'narrow' ? NARROW_MARGIN : MARGIN)

/** A blank row, `width` cells of space where a card's rows are all as wide as the card: the one way the pane draws one. */
export const blankRow = (key: string, width = 1): Row => ({ key, segments: [{ text: spaces(Math.max(1, width)) }] })

/** The blank row between two groups inside a card (a verdict, a table, a command line). */
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

/** One labelled fact: its row key, its one-word label, and its value as lines no wider than the width it is given. */
export type Fact = { key: string; label: string; lines: (width: number) => Segment[][] }

/**
 * Facts of different kinds, stacked in `width` with their labels in one dim
 * column and their values in the next, a blank row between two facts. A
 * value wider than its column wraps under its own column, never under the
 * label. The first row of a fact is keyed by it, the rest `key-1` on.
 */
export function labelledRows(facts: readonly Fact[], width: number): Row[] {
  const column = Math.max(0, ...facts.map(f => cells(f.label))) + LABEL_GAP
  const room = Math.max(1, width - GUTTER - column)
  return facts.flatMap((fact, n): Row[] => {
    const lines = fact.lines(room)
    const rows = (lines.length === 0 ? [[]] : lines).map((line, i): Row => ({
      key: i === 0 ? fact.key : `${fact.key}-${i}`,
      segments: [{ text: spaces(GUTTER) }, i === 0 ? { text: padEnd(fact.label, column), dim: true } : { text: spaces(column) }, ...line],
    }))
    return n === 0 ? rows : [groupGap(`${fact.key}-gap`), ...rows]
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
