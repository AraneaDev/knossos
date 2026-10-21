/**
 * What this session changed, added up: the turn briefs folded into the
 * session's running total, and the scan ledger's account of everything
 * changed in the project since the session began, whoever changed it.
 *
 * Pure. `accumulate` folds one `ok` brief in (the mod stores the total after
 * each turn's scan); `fromLedger` puts in its place what the ledger says
 * while a live watcher keeps that ledger. Every list is capped, and a cap that
 * bit says so. The Changes tab that draws either is in `changes.ts`.
 */
import type { JsRunner, SessionChanges, SessionLedger, TouchStatus, TurnBrief } from '../../types'
import { baselineWords } from './baseline'

/** The most files, tests and violations a session keeps; past them `truncated` is set. */
export const FILE_CAP = 500
export const TEST_CAP = 500
export const VIOLATION_CAP = 200

export const NO_CHANGES: SessionChanges = { turns: 0, files: {}, tests: {}, violations: [], truncated: false }

/**
 * How a file stands after a turn that reports it as `now`: a file added this
 * session stays added while it is edited, a deleted one is deleted, and one
 * written again after its deletion is changed.
 */
function settle(before: TouchStatus | undefined, now: TouchStatus): TouchStatus {
  if (now === 'deleted') return 'deleted'
  if (before === 'added' || (before === undefined && now === 'added')) return 'added'
  return 'changed'
}

/**
 * The session's changes with one more `ok` brief folded in: its files (with
 * their dependents and boundaries as this brief's graph has them), the tests
 * that reach them at their nearest distance, and the violations it introduced.
 */
export function accumulate(changes: SessionChanges, brief: TurnBrief): SessionChanges {
  if (brief.status !== 'ok') return changes
  const files = { ...changes.files }
  const tests = { ...changes.tests }
  const violations = new Set(changes.violations)
  // A brief that could not search every changed file for tests lists only some of them.
  let truncated = changes.truncated || brief.tests_truncated === true
  const reported: [string, TouchStatus][] = [
    ...brief.changed_files.map((f): [string, TouchStatus] => [f, 'changed']),
    ...brief.added_files.map((f): [string, TouchStatus] => [f, 'added']),
    ...brief.deleted_files.map((f): [string, TouchStatus] => [f, 'deleted']),
  ]
  for (const [path, status] of reported) {
    const before = files[path]
    if (before === undefined && Object.keys(files).length >= FILE_CAP) {
      truncated = true
      continue
    }
    const impact = brief.impact[path]
    // How many tests reach it, as the latest brief that knew says.
    const reached = impact?.tests ?? before?.tests
    files[path] = {
      status: settle(before?.status, status),
      dependents: impact?.dependent_files ?? before?.dependents ?? 0,
      boundaries: impact?.boundaries ?? before?.boundaries ?? [],
      boundary: impact === undefined ? (before?.boundary ?? null) : (impact.boundary ?? null),
      ...(reached === undefined || status === 'deleted' ? {} : { tests: reached }),
    }
  }
  const runners = { ...(changes.js_runners ?? {}) }
  for (const test of brief.tests) {
    const before = tests[test.path]
    if (before === undefined && Object.keys(tests).length >= TEST_CAP) {
      truncated = true
      continue
    }
    tests[test.path] = Math.min(before ?? test.distance, test.distance)
    if (test.js_runner !== undefined) runners[test.path] = test.js_runner
  }
  const recordedAt = { ...(changes.violation_snapshots ?? {}) }
  for (const v of brief.policy.violations) {
    const key = `${v.source} → ${v.target}`
    if (!violations.has(key) && violations.size >= VIOLATION_CAP) {
      truncated = true
      continue
    }
    violations.add(key)
    // Which graph reported it: a policy check older than that one never saw it (see `stillReported`).
    if (brief.snapshot_id !== null) recordedAt[key] = brief.snapshot_id
  }
  return { turns: changes.turns + 1, files, tests, js_runners: runners, violations: [...violations], violation_snapshots: recordedAt, truncated }
}

/**
 * The session's changes as the scan ledger has them: every file changed in
 * the project since the session began, whoever changed it, each labelled by
 * where its change came from, and the tests that reach them. A file is the
 * session's when its own edit tools wrote it (`edited`: project-relative
 * paths, main loop or subagent) or when a scan that changed it took in
 * changes noticed while the session's tools ran (`scans`: the snapshots
 * those scans produced, see `activity.ts`); otherwise it is outside. The
 * turn count and the violations introduced stay the turn briefs': they are
 * the model's own, and only its own edits are judged.
 */
export function fromLedger(ledger: SessionLedger, turns: SessionChanges, edited: ReadonlySet<string>, scans: ReadonlySet<string> = new Set(), withOrigins = true): SessionChanges {
  const tests: Record<string, number> = {}
  const runners: Record<string, JsRunner | null> = {}
  for (const t of ledger.tests) {
    tests[t.path] = t.distance
    if (t.js_runner !== undefined) runners[t.path] = t.js_runner
  }
  // Scans the ledger merged are named by their key: the session's own are matched by theirs.
  const own = new Set([...scans].map(scanKey))
  const ours = (id: string): boolean => scans.has(id) || own.has(scanKey(id))
  const origins: Record<string, 'session' | 'outside'> = {}
  if (withOrigins) for (const [path, file] of Object.entries(ledger.files)) origins[path] = edited.has(path) || (file.scans ?? []).some(ours) ? 'session' : 'outside'
  const timeline = (ledger.scans ?? []).map(scan => ({ snapshot: scan.snapshot_id, origin: ours(scan.snapshot_id) ? ('session' as const) : ('outside' as const) }))
  return {
    turns: turns.turns,
    files: ledger.files,
    tests,
    js_runners: runners,
    violations: turns.violations,
    ...(turns.violation_snapshots === undefined ? {} : { violation_snapshots: turns.violation_snapshots }),
    truncated: ledger.files_truncated || ledger.tests_truncated,
    ...(withOrigins ? { origins } : {}),
    timeline,
    ...(ledger.scans_truncated === true ? { timeline_truncated: true } : {}),
  }
}

/** The session's own scans as a timeline, every one the session's: what the Changes tab draws without the scan ledger. */
export const ownTimeline = (scans: readonly string[]): { snapshot: string; origin: 'session' }[] => scans.map(snapshot => ({ snapshot, origin: 'session' }))

/** How much of a snapshot id the scan ledger keeps for the scans it merged: `scan_` and 48 bits of hash (as `ScanLedgerSpan::key`). */
const SCAN_KEY = 17
/** A snapshot id as the scan ledger names a merged scan. */
export const scanKey = (id: string): string => (id.length > SCAN_KEY ? id.slice(0, SCAN_KEY) : id)

const pad2 = (n: number): string => String(n).padStart(2, '0')
/** A moment (milliseconds) as a local date and time to the minute: `2026-10-04 09:14`. */
export function stamp(ms: number): string {
  const d = new Date(ms)
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`
}

/** Said when the session began among scans the ledger keeps merged. */
export const APPROXIMATE_START = 'This session began among older scans the record keeps merged, so its start is approximate: a file changed shortly before it may be listed.'

/**
 * The session's changes from a `session-changes` answer for its start, as
 * the Changes tab shows them. When the ledger cannot reach back to that
 * start, it says so with when the session began (`began`, milliseconds;
 * null when not known), and lists every change since the oldest point the
 * ledger still holds, said with its date; with no such point (an older
 * knossos, or nothing recorded since) it falls back to the turns'
 * own (`scans` drawn as the timeline). A start among merged scans is said to
 * be approximate.
 *
 * On the web (`host` `'web'`), where the session's own edits are not told
 * apart, the files carry no origin and a complete ledger counts from the
 * last commit, as {@link baselineWords} names it. There an answer that is
 * not `ok` (no git, a read that failed) is said as why nothing is listed.
 */
export function ledgerChanges(ledger: SessionLedger, turns: SessionChanges, edited: ReadonlySet<string>, scans: readonly string[], began: number | null, host?: 'web'): SessionChanges {
  const origins = host !== 'web'
  if (ledger.status !== 'ok') {
    const words = baselineWords(host)
    return { ...turns, files: {}, tests: {}, fallback: ledger.status === 'no-git' ? words.notGit : words.unread }
  }
  const when = began === null ? '' : ` (${stamp(began)})`
  if (!ledger.complete) {
    const reached = ledger.reached
    if (reached === undefined || reached === null || typeof reached.at !== 'number' || !Number.isFinite(reached.at)) {
      return { ...turns, fallback: `The scan record does not reach back to when this session began${when}, so only what its turns reported is listed.`, timeline: ownTimeline(scans) }
    }
    const from = stamp(reached.at * 1000)
    return {
      ...fromLedger(ledger, turns, edited, new Set(scans), origins),
      fallback: `The scan record does not reach back to when this session began${when}. It lists every change since ${from}, the oldest point it still holds.`,
      since: `since ${from}`,
    }
  }
  const listed = fromLedger(ledger, turns, edited, new Set(scans), origins)
  // Without origins the files are still every change since a start; the list says which one.
  const shown = origins ? listed : { ...listed, since: baselineWords(host).since }
  return ledger.start_approximate === true ? { ...shown, fallback: APPROXIMATE_START } : shown
}
