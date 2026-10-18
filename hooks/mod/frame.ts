/**
 * What the pane draws, before any element is: the source its tree comes from
 * (nothing, the empty state, or the pane's input) and the spinner bookkeeping
 * of the rows laid out. Plain TypeScript, so a host without the engine's
 * bundler draws from the same decisions as the hooks module.
 */
import { allowInput, emptyRows, noGraphOf, tierOf } from '../lib/layout'
import type { PaneInput, Row } from '../lib/layout'
import { BUSY_SPIN_MS, isBusy } from '../lib/motion'
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
    const offer = allowInput(await io.state.brief.read(), await io.state.rescan.read(), await io.state.allow.read())
    mod.emptyShown = true
    return { kind: 'empty', rows: emptyRows(noGraphOf(d, await io.state.refresh.read()), offer, columns, mod.host === 'web' ? 'web' : undefined) }
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
