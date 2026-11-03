/**
 * Starting Knossos in a folder on purpose (`/knossos`, `knossos`, the web
 * server) is the person's consent to scan it: the folder is claimed through
 * `knossos claim`, which writes the roots file under the same rules as
 * `allow-root`, and a root that has no graph is scanned. A session that only
 * begins never gets here.
 */
import type { AllowState, RescanState } from '../../types'
import { parseClaim } from '../lib/envelopes'
import { afterGrant } from './allow-actions'
import { disable, wrapperAnswer } from './port'
import type { Port } from './port'
import { mod } from './state'

/** The wrapper bounds claim at 15 s. */
const CLAIM_TIMEOUT_MS = 20_000

/** Whether a claim is under way: a second start while it runs adds nothing. */

/**
 * Claims the folder the session is in and, when that put it on the roots
 * list or it is listed and knossos says it was never scanned, scans it. Every other answer (a folder
 * to ask about, a setup to fix, silence, a refusal) scans nothing and raises
 * nothing, except a roots file that cannot be used, whose reason the pane
 * shows. Never throws.
 */
export async function startDeliberately(io: Port): Promise<void> {
  if (mod.deliberate.claiming) return
  mod.deliberate.claiming = true
  try {
    const { stdout, refusal } = await wrapperAnswer(io, 'claim', ['--execute'], CLAIM_TIMEOUT_MS)
    // The wrapper refused (no roots file known, say): the person is told why nothing was added.
    if (refusal !== null) {
      const root = await io.session.root().catch(() => '')
      return void (await io.state.allow.update((): AllowState => ({ phase: 'failed', root, reason: refusal })))
    }
    const claim = parseClaim(stdout)
    if (claim === null) return
    if (claim.status === 'no-binary') return await disable(io)
    const { root } = claim
    if (claim.status === 'added') return await afterGrant(io, root)
    if (claim.status === 'present') {
      // Only a project knossos says it never scanned: a dashboard that is slow, failed or not loaded yet is no reason to scan.
      if ((await io.state.dashboard.read())?.status === 'unscanned') await afterGrant(io, root)
      return
    }
    // Too broad to allow unasked: left refused, so the pane makes today's offer.
    if (claim.status === 'ask') return void (await io.state.rescan.update((r): RescanState => ({ ...r, refusedRoot: root })))
    if (claim.status === 'error') await io.state.allow.update((): AllowState => ({ phase: 'failed', root, reason: claim.reason ?? 'the roots file could not be used' }))
  } catch {
    // A torn-down session fails quietly.
  } finally {
    mod.deliberate.claiming = false
  }
}
