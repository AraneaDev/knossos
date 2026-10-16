/**
 * The frames the pane's motion draws: started when a scan lands (the live
 * dot breathes, the Overview's bars grow in) or while one runs (the
 * spinner), each frame a step of `motion` that redraws the pane, and stopped
 * on their own once nothing moves. A pane nobody has open draws none, and an
 * idle graph never ticks for motion's sake.
 */
import { MOTION_FRAME_MS, moving } from '../lib/motion'
import type { Port } from './port'
import { mod } from './state'

/** Starts the frames: from `landed` when a scan just landed (null while one only runs); one timer however often it is called. */
export async function startMotion(io: Port, landed: number | null): Promise<void> {
  if (mod.paneText === null) return
  if (landed !== null) await io.state.motion.update(() => ({ since: landed, frame: 0 }))
  mod.motionTimer ??= io.clock.every(MOTION_FRAME_MS, () => void frame(io).catch(() => undefined))
}

/** One frame: a step on, or, once the moment after the last scan is over and no scan runs, the end of the frames. */
async function frame(io: Port): Promise<void> {
  const now = await io.clock.now()
  const scanning = (await io.state.live.read()).phase === 'scanning' || (await io.state.rescan.read()).phase === 'scanning'
  if (!scanning && !moving(await io.state.motion.read(), now)) {
    mod.motionTimer?.cancel()
    mod.motionTimer = null
    return
  }
  await io.state.motion.update(m => ({ ...m, frame: m.frame + 1 }))
}
