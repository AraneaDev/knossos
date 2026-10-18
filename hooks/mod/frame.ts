/**
 * What the pane draws, before any element is: the source its tree comes from
 * (nothing, the empty state, or the pane's input) and the spinner bookkeeping
 * of the rows laid out. Plain TypeScript, so a host without the engine's
 * bundler draws from the same decisions as the hooks module.
 */
import { allowInput, emptyRows, noGraphOf, tierOf } from '../lib/layout'
import type { PaneInput, Row, Segment } from '../lib/layout'
import { BUSY_SPIN_MS, isBusy, spun } from '../lib/motion'
import { cells } from '../lib/rows'
import { currentInput } from './input'
import { startMotion } from './motion'
import type { Port } from './port'
import { mod } from './state'

/** What the pane's tree is drawn from: nothing (the mod is off), the empty state's rows, or the input of a tab or a detail. */
export type PaneSource = { kind: 'off' } | { kind: 'empty'; rows: Row[] } | { kind: 'pane'; key: 'pane' | 'detail'; input: PaneInput }

/** The pane's source for a pane `columns` wide, noting in state what the pane now shows (its text, its width, the empty state). */
export async function paneSource(io: Port, columns: number, terminal: boolean): Promise<PaneSource> {
  mod.paneText = null
  mod.emptyShown = false
  if (mod.disabled) return { kind: 'off' }
  const d = await io.state.dashboard.read()
  const v = await io.state.view.read()
  // Whether the pane is wide enough for the detail beside the tab: the lookup it needs runs from the next press or tick.
  mod.paneWide = tierOf(columns) === 'wide'
  if (d === null || d.status !== 'ok') {
    const rescan = await io.state.rescan.read()
    const offer = allowInput(await io.state.brief.read(), rescan, await io.state.allow.read())
    mod.emptyShown = true
    // Only the web's `scan it` starts a scan from the empty pane, so only there does the pane say one runs; Claude Code's is drawn as it was.
    const web = mod.host === 'web'
    return { kind: 'empty', rows: emptyRows(noGraphOf(d, await io.state.refresh.read()), offer, columns, web ? 'web' : undefined, web && rescan.phase === 'scanning') }
  }
  const input = await currentInput(io, terminal)
  if (input === null) return { kind: 'empty', rows: [] }
  mod.paneText = input.status.text
  return { kind: 'pane', key: v.inspect === null ? 'pane' : 'detail', input }
}

/**
 * The spinners of the pane about to be drawn. A line that says something is
 * on its way spins: drawn at the motion's frame (set here, so call it before
 * the rows are drawn), and while one shows the frames go on.
 */
export async function noteSpinners(io: Port, rows: readonly Row[]): Promise<void> {
  mod.spinFrame = mod.disabled ? 0 : (await io.state.motion.read()).frame
  const seen = rows.some(row => row.segments.some(s => isBusy(s.text)))
  const now = await io.clock.now()
  if (seen && !mod.paneBusy) mod.busySince = now
  mod.paneBusy = seen
  // Past its minute a line under way stands still: a redraw then starts no frames, or each would draw the next.
  if (mod.paneBusy && mod.motionTimer === null && now - mod.busySince < BUSY_SPIN_MS) await startMotion(io, null)
}

/** `row` with its spinners turned to `frame`, the one the pane draws at (see {@link noteSpinners}, which also keeps the frames going while one shows). */
export function spunRow(row: Row, frame: number): Row {
  if (!row.segments.some(s => isBusy(s.text))) return row
  return { ...row, segments: row.segments.map(s => (isBusy(s.text) ? { ...s, text: spun(s.text, frame) } : s)) }
}

/** A segment no press, field or ground sets apart: plain text. */
const plain = (s: Segment): boolean => s.press === undefined && s.field === undefined

/**
 * The room each field of a row `columns` wide takes, by the field's index: a
 * field takes the blank cells laid out after it (`next` is the first
 * segment past them) and stops where the row's next drawn segment (a card's
 * frame) begins. Every host draws a field this wide.
 */
export function fieldRooms(segments: readonly Segment[], columns: number): Map<number, { room: number; next: number }> {
  const rooms = new Map<number, { room: number; next: number }>()
  let x = 0
  for (let i = 0; i < segments.length; i++) {
    const s = segments[i]!
    if (s.field === undefined) {
      x += s.hidden === true ? 0 : cells(s.text)
      continue
    }
    let next = i + 1
    while (next < segments.length && plain(segments[next]!) && segments[next]!.bg === undefined && /^ *$/.test(segments[next]!.text)) next++
    const trailing = segments.slice(next).reduce((n, t) => n + (t.hidden === true ? 0 : cells(t.text)), 0)
    const room = Math.max(1, columns - x - trailing)
    rooms.set(i, { room, next })
    x += room
    i = next - 1
  }
  return rooms
}

/**
 * Where a hover card goes for a segment at `row` and `column` of a pane: under
 * its row, or over it when the rows below cannot hold the card and the rows
 * above can; never past the pane's right edge. In cells, so Claude Code and a
 * page place a card by the one rule.
 */
export function cardPlace(row: number, column: number, card: { rows: number; columns: number }, pane: { rows: number; columns: number }): { row: number; column: number } {
  const below = row + 1 + card.rows <= pane.rows || row < card.rows
  return { row: below ? row + 1 : row - card.rows, column: Math.max(0, Math.min(column, pane.columns - card.columns)) }
}
