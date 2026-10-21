/**
 * Where the session began and where the checkout stands: the baseline a
 * session settles on when it starts or comes back, and the `session-head`
 * reads behind it and behind the header's commit and branch. Each runs on a
 * timer, never inside a render.
 */
import type { SessionRev } from '../../types'
import { parseSessionDiff, parseSessionHead, parseSessionRev } from '../lib/envelopes'
import { currentSession, request, requestDiff, saveBaseline, storedBaselines } from './loaders'
import { disable, wrapper } from './port'
import type { Port } from './port'
import { mod } from './state'

/** The wrapper bounds session-head at 15 s. */
const HEAD_TIMEOUT_MS = 20_000

/**
 * Where the session began. A session this mod has seen before (continued
 * or resumed, in this process or another) gets its commit and its first
 * snapshot back from the store, so its Changes and diffs keep their
 * baseline; any other session records the commit it begins at. Either way
 * the baseline is stored under the session's id.
 */
export async function settleBaseline(io: Port): Promise<void> {
  const id = await currentSession(io)
  const kept = id === null ? undefined : (await storedBaselines(io))[id]
  if (id !== null && kept !== undefined) {
    if (kept.rev !== null) await io.state.sessionRev.update(() => kept.rev)
    if (kept.snapshot !== null) await io.state.sessionStart.update(() => kept.snapshot)
    await io.state.sessionBegan.update(() => kept.startedAt)
    mod.session.baselineOf = id
    return rereadDiff(io)
  }
  // Asked once per session: a head read later would name a commit made during it.
  if (!mod.session.headAsked) {
    mod.session.headAsked = true
    await recordHead(io)
  }
  await saveBaseline(io)
  await rereadDiff(io)
}

/**
 * The diff of the file the detail still shows, read against the baseline
 * just settled: a session that followed a /clear or a resume dropped the
 * last one with its commit, and nothing else asks again until the graph
 * changes, so the card would say it is reading the change for good.
 */
async function rereadDiff(io: Port): Promise<void> {
  // A view torn down under it (the session ending again) asks for nothing.
  const shown = (await io.state.view.read().catch(() => null))?.inspect ?? null
  if (shown !== null) await requestDiff(io, shown)
}

/**
 * Records the commit the project is at as the one the session began at,
 * unless one is recorded. Silence leaves none: a later read would name a
 * commit made during the session, and the diff would leave its changes out.
 */
async function recordHead(io: Port): Promise<void> {
  if (mod.session.disabled || (await io.state.sessionRev.read()) !== null) return
  const stdout = await wrapper(io, 'session-head', [], HEAD_TIMEOUT_MS)
  if (parseSessionDiff(stdout)?.status === 'no-binary') return disable(io)
  const rev = parseSessionRev(stdout)
  if (rev !== null) await io.state.sessionRev.update(() => rev)
  await noteGitHead(io, stdout)
}

/**
 * Reads where the checkout stands, its commit and branch, for the header:
 * at start-up and after each of the main loop's turns (one may commit or
 * switch branches), always on a timer, never in a render.
 */
export async function readGitHead(io: Port): Promise<void> {
  await readHeadRev(io)
}

/** {@link readGitHead}, handing back the commit the answer names: null for silence, an error, or a mod turned off; `no-git` for a project git does not hold. */
export async function readHeadRev(io: Port): Promise<SessionRev | null> {
  if (mod.session.disabled) return null
  const stdout = await wrapper(io, 'session-head', [], HEAD_TIMEOUT_MS)
  if (parseSessionDiff(stdout)?.status === 'no-binary') {
    await disable(io)
    return null
  }
  await noteGitHead(io, stdout)
  return parseSessionRev(stdout)
}

/** Keeps what a `session-head` answer says of the checkout; silence keeps what was read before. */
async function noteGitHead(io: Port, stdout: string): Promise<void> {
  mod.session.gitAsked = true
  const head = parseSessionHead(stdout)
  if (head !== undefined) await io.state.gitHead.update(() => head)
  // The Churn tab's history ends at the checkout's commit: a new one reads it again.
  await request(io, 'churn')
}
