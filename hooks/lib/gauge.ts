/**
 * The width gauge in the pane's header: which of the three layouts the pane
 * is drawn in, where its width stands within that layout's span, and how
 * wide it has to be for the next (`medium ━━━━━━▮─── wide  118/131`), so
 * the person can see how far to drag. The widest layout has no next one:
 * there it says the width alone.
 *
 * Pure. Given less room it shrinks to the width and the layout
 * (`118 · medium`), and with less still it draws nothing.
 */
import { ACCENT, FAINT } from './palette'
import { cells, MEDIUM_MAX, MEDIUM_MIN, tierOf } from './rows'
import type { Segment } from './types'
import type { Tier } from './rows'

/** The cells the track takes between the two layouts' names. */
const TRACK = 10

/** Where the track of a layout starts, where the next layout begins (null for the widest), and that layout's name. */
const SPANS: Record<Tier, { from: number; next: number | null; after: Tier | null }> = {
  narrow: { from: 40, next: MEDIUM_MIN, after: 'medium' },
  medium: { from: MEDIUM_MIN, next: MEDIUM_MAX + 1, after: 'wide' },
  wide: { from: MEDIUM_MAX + 1, next: null, after: null },
}

/** The gauge for a pane `columns` wide in at most `room` cells: the full slider, the short form, or nothing. */
export function widthGauge(columns: number, room: number): Segment[] {
  const tier = tierOf(columns)
  const span = SPANS[tier]
  const full = slider(columns, tier, span)
  if (cells(full.map(s => s.text).join('')) <= room) return full
  const short = `${columns} · ${tier}`
  return cells(short) <= room ? [{ text: short, dim: true }] : []
}

function slider(columns: number, tier: Tier, span: (typeof SPANS)[Tier]): Segment[] {
  // The widest layout runs on: its track is full from where it begins.
  const share = span.next === null ? 1 : Math.min(1, Math.max(0, (columns - span.from) / (span.next - 1 - span.from)))
  const at = Math.min(TRACK - 1, Math.round(share * (TRACK - 1)))
  const track: Segment[] = [
    { text: '━'.repeat(at), color: ACCENT },
    { text: '▮', color: ACCENT },
    { text: '─'.repeat(TRACK - 1 - at), color: FAINT },
  ].filter(s => s.text !== '')
  const next: Segment[] = span.after === null ? [] : [{ text: ` ${span.after}`, dim: true }]
  const figure: Segment = { text: `  ${span.next === null ? columns : `${columns}/${span.next}`}`, dim: true }
  return [{ text: `${tier} `, dim: true }, ...track, ...next, figure]
}
