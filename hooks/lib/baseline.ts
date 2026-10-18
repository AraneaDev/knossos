/**
 * Where a session began, kept across processes: the commit it started at
 * (what a changed file's diff is taken against), the snapshot its changes
 * are read since (its place in the scan ledger), and when it started.
 *
 * `$.state` lives as long as the process, so a session continued in a new
 * one (`claude --continue`, `--resume`) would otherwise begin again at the
 * graph and the commit as they stand, and lose its Changes and its diffs.
 * The mod keeps one baseline per session id in `$.store` and reads it back
 * when a session it has seen starts again; a session id it has not seen
 * starts fresh. Pure: the store's value in, the store's value out.
 */
import type { SessionRev } from '../../types'

/** One session's baseline. `rev` is null when no git answered as it began: its diffs say so, and none is read later. */
export type Baseline = { rev: SessionRev | null; snapshot: string | null; startedAt: number }

/** The `$.store` key the baselines are kept under, by session id. */
export const BASELINES_KEY = 'sessionBaselines'
/** The most sessions remembered; the oldest started are forgotten first. */
export const BASELINES_KEPT = 50

const isRev = (value: unknown): value is SessionRev => {
  if (typeof value !== 'object' || value === null) return false
  const v = value as { status?: unknown; rev?: unknown }
  return (v.status === 'ok' && typeof v.rev === 'string' && /^[0-9a-f]{7,64}$/.test(v.rev)) || v.status === 'no-git'
}

/** The baselines in a stored value, by session id; anything malformed is left out, never trusted. */
export function baselinesOf(value: unknown): Record<string, Baseline> {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return {}
  const out: Record<string, Baseline> = {}
  for (const [id, entry] of Object.entries(value as Record<string, unknown>)) {
    if (typeof entry !== 'object' || entry === null) continue
    const e = entry as { rev?: unknown; snapshot?: unknown; startedAt?: unknown }
    const rev = e.rev === null ? null : isRev(e.rev) ? e.rev : undefined
    const snapshot = e.snapshot === null || typeof e.snapshot === 'string' ? e.snapshot : undefined
    if (rev === undefined || snapshot === undefined || typeof e.startedAt !== 'number' || !Number.isFinite(e.startedAt)) continue
    out[id] = { rev, snapshot, startedAt: e.startedAt }
  }
  return out
}

/**
 * The baselines with `id`'s set to `baseline`, keeping the start it was
 * first recorded with, the newest {@link BASELINES_KEPT} by start.
 */
export function remember(all: Record<string, Baseline>, id: string, baseline: Baseline, kept = BASELINES_KEPT): Record<string, Baseline> {
  const startedAt = all[id]?.startedAt ?? baseline.startedAt
  const merged = { ...all, [id]: { ...baseline, startedAt } }
  const newest = Object.entries(merged)
    .sort(([, a], [, b]) => b.startedAt - a.startedAt)
    .slice(0, kept)
  return Object.fromEntries(newest)
}

/**
 * How the pane names the point its changes count from. In Claude Code that is
 * where the session began; outside it (`host` `'web'`) it is the last commit,
 * since there is no session. Every line that names the baseline takes its
 * words from here, so the two hosts never mix them.
 */
export type BaselineWords = {
  /** The Overview card of the changes. */
  card: string
  /** The Changes tab's list. */
  list: string
  /** The changes as the end of a sentence: `Nothing changed in this project <since>.` */
  since: string
  /** `N files <changed>`: what `c` copies from the Overview's changes row. */
  changed: string
  /** A changed file's diff card. */
  diff: string
  /** What `t` copies the tests for, in the key list. */
  tests: string
  /** A diff's messages: no commit was read, no git, the commit is gone, the file is back as it was. */
  noRev: string
  noGit: string
  goneRev: string
  unchanged: string
}

const SESSION_WORDS: BaselineWords = {
  card: 'This session',
  list: 'Changes this session',
  since: 'since this session began',
  changed: 'changed this session',
  diff: 'Changed since the session began',
  tests: "this session's changes",
  noRev: 'No diff: the commit this session began at was not recorded (no git answered when it started).',
  noGit: 'No diff: the project is not in a git repository, or had no commit when the session began.',
  goneRev: 'No diff: the commit this session began at is no longer in the repository.',
  unchanged: 'Back as it was when the session began.',
}

const COMMIT_WORDS: BaselineWords = {
  card: 'Since the last commit',
  list: 'Changes since the last commit',
  since: 'since the last commit',
  changed: 'changed since the last commit',
  diff: 'Changed since the last commit',
  tests: 'the changes since the last commit',
  noRev: 'No diff: the last commit was not read (no git answered).',
  noGit: 'No diff: the project is not in a git repository, or has no commit yet.',
  goneRev: 'No diff: the last commit read is no longer in the repository.',
  unchanged: 'Back as it was at the last commit.',
}

/** The words for the baseline of `host`: the session's in Claude Code, the last commit's on the web. */
export const baselineWords = (host?: 'web'): BaselineWords => (host === 'web' ? COMMIT_WORDS : SESSION_WORDS)
