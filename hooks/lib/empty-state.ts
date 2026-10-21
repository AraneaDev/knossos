/**
 * The pane with no graph to draw, and the allow-root offer: why there are no
 * figures (`noGraphOf`), the rows that say so with at most one thing to do
 * about it, and the offer to allow a root knossos refused. Pure.
 */
import type { AllowState, Dashboard, RefreshState, RescanState, TurnBrief } from '../../types'
import { HEADING, STATUS_COLOURS } from './palette'
import { blank, button, dimRow, fit, wrapGroups, wrapWords } from './rows'
import type { Row, Segment } from './types'
import { busy } from './motion'
import { SEVERITY } from './marks'

/** A refused root the pane offers to allow: the root, the roots file it would join, and where the action stands. */
export type AllowInput = { root: string; rootsFile: string | null; phase: AllowState['phase']; reason: string | null }

/** The widest the empty pane's prose runs: a line of text past it is hard to read. */
export const CONTENT_MAX = 100

/**
 * The root a scan was refused for: the turn brief's, else the pane's last
 * rescan's; null when neither was refused.
 */
export function refusedRoot(brief: TurnBrief | null, rescan: RescanState): { root: string; rootsFile: string | null } | null {
  if (brief?.status === 'not-allowed') return { root: brief.refused_root ?? brief.path, rootsFile: brief.roots_file }
  return rescan.refusedRoot ? { root: rescan.refusedRoot, rootsFile: null } : null
}

/** The allow-root offer: the action under way for its root, else an offer for a refused root, else null. */
export function allowInput(brief: TurnBrief | null, rescan: RescanState, allow: AllowState | null): AllowInput | null {
  const refused = refusedRoot(brief, rescan)
  if (allow !== null && allow.phase !== 'idle' && allow.root !== null) {
    return { root: allow.root, rootsFile: refused?.root === allow.root ? refused.rootsFile : null, phase: allow.phase, reason: allow.reason }
  }
  return refused === null ? null : { ...refused, phase: 'idle', reason: null }
}

/**
 * The allow-root rows: the offer with its button, the question with its two
 * answers, the action running, or how it ended. The offer never runs
 * anything itself: `a` only asks, and only `y` on the question allows.
 */
export function allowRows(allow: AllowInput, columns: number): Row[] {
  const lines = (key: string, text: string, style: Omit<Segment, 'text'>): Row[] =>
    wrapWords(text, Math.max(1, columns - 3)).map((line, i) => ({ key: `${key}-${i}`, segments: [{ text: i === 0 ? `${SEVERITY.warn.glyph}  ` : '   ', ...style }, { text: line, ...style }] }))
  const answers = (groups: Segment[][]) => wrapGroups('allow-keys', groups, columns, 2, 3)
  if (allow.phase === 'confirming') {
    const where = allow.rootsFile === null ? 'the roots file knossos reads' : allow.rootsFile
    return [
      ...lines('allow-ask', `Allow knossos to scan ${allow.root}? This adds it to ${where}.`, { color: STATUS_COLOURS.warn }),
      ...answers([[button('allow-yes', 'allow', 'y', { dim: false })], [button('allow-no', 'cancel', 'n', { dim: true })]]),
    ]
  }
  if (allow.phase === 'running') return [dimRow('allow-running', `   allowing ${allow.root}…`, columns)]
  if (allow.phase === 'done') {
    return wrapWords(`${SEVERITY.ok.glyph} ${allow.root} allowed · scanning it now`, columns).map((line, i) => ({
      key: `allow-done-${i}`,
      segments: [{ text: line, color: STATUS_COLOURS.ok }],
    }))
  }
  const said = allow.phase === 'failed' ? `allow-root failed${allow.reason ? `: ${allow.reason}` : ''} · ${allow.root}` : `${allow.root} is not an allowed root`
  return [
    ...lines('allow-offer', said, { color: allow.phase === 'failed' ? STATUS_COLOURS.alert : STATUS_COLOURS.warn }),
    ...answers([[button('allow', 'allow root', 'a', { dim: false })]]),
  ]
}

/** What "ask Claude to scan it" asks: the model scans through the Knossos server, into the graph the pane reads. */
export const SCAN_PROMPT = 'Scan this project with Knossos (scan_project), then give me a short summary of its architecture.'

/**
 * Why the pane has no figures to draw: the first load is still on its way,
 * a load is on its way again after one that failed, the last load came back
 * silent or as an error, or knossos answered that it has never scanned this
 * project. Only the last is a reason to scan.
 */
export type NoGraph = 'loading' | 'retrying' | 'unreadable' | 'unscanned'

/**
 * Which {@link NoGraph} state a dashboard that is not `ok` (or none yet) is
 * in. A load on its way is said as one, even after a failure: a knossos
 * still starting up answers the next read, and the pane does not call that
 * an error while it waits for it.
 */
export function noGraphOf(d: Dashboard | null, refresh: RefreshState): NoGraph {
  const failed = refresh.failed || d?.status === 'error'
  if (refresh.loading === true && d?.status !== 'unscanned') return failed ? 'retrying' : 'loading'
  if (failed) return 'unreadable'
  if (d?.status === 'unscanned') return 'unscanned'
  return refresh.fetchedAt === null ? 'loading' : 'unreadable'
}

const NO_GRAPH: Record<NoGraph, { head: string; why: string; busy?: true }> = {
  loading: { head: 'Reading the graph…', why: 'Knossos is loading the architecture graph of this project.', busy: true },
  retrying: { head: 'Reading the graph…', why: 'Knossos did not answer the last read; asking again.', busy: true },
  unreadable: { head: 'Could not read the graph', why: 'Knossos did not answer, or answered with an error; the pane asks again shortly.' },
  unscanned: { head: 'No architecture graph yet', why: 'Knossos has not scanned this project, so there is nothing to draw.' },
}

/**
 * The pane with no dashboard to draw: a heading saying which state it is
 * in, why, and at most one thing to do about it. When the project's root
 * was refused, that one thing is the allow-root offer. Otherwise only a
 * project knossos says it never scanned offers asking Claude to scan it:
 * a load still on its way, or one that failed, is no reason to scan, so a
 * habitual `q` there sends nothing. Outside Claude Code (`host`) there is no
 * one to ask: the offer is to scan it directly. While a rescan runs
 * (`scanning`) the pane says so, with its spinner, and offers nothing: the
 * graph it waits for lands on its own.
 */
export function emptyRows(state: NoGraph, allow: AllowInput | null, columns: number, host?: 'web', scanning = false): Row[] {
  const width = Math.max(1, Math.min(CONTENT_MAX, columns))
  if (scanning) {
    return [
      { key: 'empty-head', segments: [{ text: fit(busy('Scanning the project…'), width), bold: true, color: HEADING }] },
      ...wrapWords('Knossos is scanning this project; the graph appears here when it is done.', width).map((line, i) => dimRow(`empty-${i}`, line, width)),
    ]
  }
  const { head: heading, why, busy: spins } = NO_GRAPH[state]
  const head: Row[] = [
    { key: 'empty-head', segments: [{ text: fit(spins === true ? busy(heading) : heading, width), bold: true, color: HEADING }] },
    ...wrapWords(why, width).map((line, i) => dimRow(`empty-${i}`, line, width)),
  ]
  if (allow !== null) return [...head, blank('gap-empty'), ...allowRows(allow, width)]
  if (state !== 'unscanned') return head
  return [...head, blank('gap-empty'), { key: 'empty-action', segments: [host === 'web' ? button('scan', 'scan it', 'q', { dim: false }) : button('scan-ask', 'ask Claude to scan it', 'q', { dim: false })] }]
}
