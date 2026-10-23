/**
 * Allowing a root knossos refused (`a`, then `y`): the question first, then
 * `knossos allow-root` through the wrapper, and the root's first scan once it is.
 */
import type { AllowState, RescanState } from '../../types'
import { parseAllowRoot } from '../lib/envelopes'
import { refusedRoot } from '../lib/empty-state'
import { disable, wrapperAnswer } from './port'
import type { Port } from './port'
import { mod } from './state'
import { rescanRoot } from './watcher'

/** Whether an allow-root run is under way, which only these presses keep. */
const { allow } = mod

/** The wrapper bounds allow-root at 15 s. */
const ALLOW_TIMEOUT_MS = 20_000

/** `a`: asks the person to confirm allowing the refused root. Runs nothing. */
export async function offerAllow(io: Port): Promise<void> {
  const refused = refusedRoot(await io.state.brief.read(), await io.state.rescan.read())
  const current = await io.state.allow.read()
  const root = refused?.root ?? (current.phase === 'failed' ? current.root : null)
  if (root === null || current.phase === 'running') return
  await io.state.allow.update((): AllowState => ({ phase: 'confirming', root, reason: null }))
}

/**
 * `y` on the question: allows the root it asked about, once the press has
 * resolved. Nothing runs unless the pane is asking; a second press while a
 * run is queued or under way adds nothing.
 */
export async function confirmAllow(io: Port): Promise<void> {
  const asked = await io.state.allow.read()
  if (asked.phase !== 'confirming' || asked.root === null || allow.inFlight) return
  allow.inFlight = true
  const root = asked.root
  await io.state.allow.update((): AllowState => ({ phase: 'running', root, reason: null }))
  io.clock.after(0, () => {
    void runAllow(io, root)
      .catch(() => undefined)
      .finally(() => {
        allow.inFlight = false
      })
  })
}

/**
 * Runs `knossos allow-root <root> --execute` through the wrapper, which
 * writes the roots file baked in at install. Allowed, the root's first scan
 * runs at once so the band and pane pick the project up.
 */
async function runAllow(io: Port, root: string): Promise<void> {
  const { stdout, refusal } = await wrapperAnswer(io, 'allow-root', [], ALLOW_TIMEOUT_MS, root)
  const parsed = parseAllowRoot(stdout)
  if (parsed?.status === 'no-binary') {
    await io.state.allow.update((): AllowState => ({ phase: 'idle', root: null, reason: null }))
    await disable(io)
    return
  }
  if (parsed === null) {
    // A refusal from the wrapper names its reason (no roots file baked in, say); an empty answer has none.
    const reason = refusal ?? 'knossos did not allow it'
    await io.state.allow.update((): AllowState => ({ phase: 'failed', root, reason }))
    return
  }
  await io.state.allow.update((): AllowState => ({ phase: 'done', root, reason: null }))
  await io.state.rescan.update((r): RescanState => ({ ...r, refusedRoot: null }))
  // A watcher refused for this root may watch it now, once the scan below lands a dashboard.
  mod.watch.refused = false
  // The turn brief would answer `unscanned` for a project nobody has scanned, so the pane's own rescan runs first:
  // it creates the project at exactly this root, shows scanning, done or failed, and reloads the dashboard.
  // Later turns go back to the turn brief.
  await rescanRoot(io, root)
}
