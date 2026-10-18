/**
 * The frames the pane's motion draws: started when a scan lands (the live
 * dot breathes, the Overview's bars grow in) or while one runs or the pane
 * shows a line under way (the spinner), each frame a step of `motion` that
 * redraws the pane, and stopped on their own once nothing moves. A pane
 * nobody has open draws none, and an idle graph never ticks for motion's
 * sake.
 */
import { BUSY_SPIN_MS, MOTION_FRAME_MS, moving, NO_MOTION } from '../lib/motion'
import type { Port } from './port'
import { mod } from './state'

/** Starts the frames: from `landed` when a scan just landed (null while one only runs, or a line is under way); one timer however often it is called. */
export async function startMotion(io: Port, landed: number | null): Promise<void> {
  if (mod.paneText === null && !mod.paneBusy) return
  mod.motionGen++
  if (landed !== null) await io.state.motion.update(() => ({ since: landed, frame: 0 }))
  mod.motionTimer ??= io.clock.every(MOTION_FRAME_MS, () => void frame(io).catch(() => undefined))
}

/**
 * One frame: a step on while the moment after a scan lasts, a scan runs or
 * the pane shows a line under way, for {@link BUSY_SPIN_MS} at most (every
 * other tick when only those, so a long one costs half as much), else the
 * end of the frames and the pane drawn at rest. A frame whose reads saw the motion start again leaves the
 * deciding to the next.
 */
async function frame(io: Port): Promise<void> {
  const gen = mod.motionGen
  const now = await io.clock.now()
  const scanning = (await io.state.live.read()).phase === 'scanning' || (await io.state.rescan.read()).phase === 'scanning'
  const lasting = moving(await io.state.motion.read(), now)
  const busy = mod.paneBusy && now - mod.busySince < BUSY_SPIN_MS
  if (gen !== mod.motionGen) return
  if (busy || (mod.paneText !== null && (lasting || scanning))) {
    mod.motionTicks++
    if (!lasting && mod.motionTicks % 2 === 1) return
    await io.state.motion.update(m => ({ ...m, frame: m.frame + 1 }))
    return
  }
  mod.motionTimer?.cancel()
  mod.motionTimer = null
  // At rest: the dot full, the bars whole, unless the motion started again meanwhile.
  await io.state.motion.update(m => (gen === mod.motionGen ? NO_MOTION : m))
}
