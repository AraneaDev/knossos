/**
 * The pane's state cells and what each one starts as. The hooks module declares
 * the atoms (the engine takes `read` and `update` only on atoms declared there)
 * and takes each initial value from here; any other host keeps the same cells
 * from this list. Plain TypeScript, so a host without the engine's bundler can
 * load it.
 */
import type { PluginState } from 'claude-code'

import { NO_CHANGES } from '../lib/session-changes'
import { withScrollsKept } from '../lib/layout'
import { LIVE_OFF } from '../lib/live'
import { NO_MOTION } from '../lib/motion'

/** Every cell the pane keeps, by key, as the plugin's state declares it. */
export type CellValues = { [K in keyof PluginState['knossos']]-?: PluginState['knossos'][K] }

/** The finder before anything was typed. */
const NO_SEARCH: CellValues['search'] = { query: '', for: null, phase: 'idle', answer: null }

export const INITIAL: CellValues = {
  brief: null,
  dashboard: null,
  job: { phase: 'idle', lastAttemptAt: null },
  view: {
    inspect: null,
    isBandHidden: false,
    tab: 'overview',
    selected: 0,
    showKeys: false,
    filter: '',
    filtering: false,
    sort: 'in',
  },
  detail: null,
  refresh: { fetchedAt: null, failed: false },
  rescan: { phase: 'idle', reason: null },
  allow: { phase: 'idle', root: null, reason: null },
  /** The person's Claude Code theme by name (`dark`, `light`, ...): what the heat map's raw colours are resolved for. */
  theme: 'dark',
  /** Everything this session's turn briefs reported, added up: what the Changes tab and "Look at now" draw. */
  changes: NO_CHANGES,
  /** The session's root with its links followed: a test command run from it changes to the project root first when they differ. */
  sessionRoot: null,
  /** The live watcher, as the header shows it. */
  live: LIVE_OFF,
  /** Everything the scan ledger says changed in the project since the session began, whoever changed it; null until read. */
  sessionLedger: null,
  /** The snapshot the graph was at when the session began. */
  sessionStart: null,
  /** When the session began (milliseconds), as its stored baseline says: what Changes names when the scan record does not reach back to it. */
  sessionBegan: null,
  /** The paths the session's own edit tools wrote, main loop and subagents alike: the Changes tab's "this session". */
  sessionEdits: [],
  /** The snapshots of the scans that took in changes made while the session's tools ran (see `lib/activity.ts`). */
  sessionScans: [],
  /** The commit the project was at when the session began: what a changed file's diff is taken against. */
  sessionRev: null,
  /** The change since the session began of the file the detail shows, as last read. */
  fileDiff: null,
  /** How far the detail's diff is opened (its hunks shown whole, the first hunk shown), for the diff it was opened on. */
  diffFold: null,
  /** Whether the Changes list holds only the changed files no test reaches: the press on that count, and `u`. */
  untestedOnly: false,
  /** Where the checkout stands (commit and branch), for the header; null until read, and without git. */
  gitHead: null,
  /** The Boundaries tab's marked heat map cell spelled out, as last read. */
  couplings: null,
  /** What the footer says for a moment after an action in the pane. */
  feedback: null,
  /** The finder's search: what was typed, and what `graph-search` answered for the query last read. */
  search: NO_SEARCH,
  /** The marked row's detail, drawn beside the tab on a wide pane: what is shown, and its lookup. */
  peek: null,
  /** The Branch tab's comparison with the merge base, for the snapshot it was read at. */
  branch: null,
  /** The rows the latest scan changed, lit for a moment after it landed. */
  flash: null,
  motion: NO_MOTION,
  /** The Churn tab's hotspots, for the commit they were read at. */
  churn: null,
  /** The Notes tab's notes, for the snapshot, filter and text they were read for. */
  notes: null,
  /** The blast radius of the component the detail shows, for its name and snapshot. */
  rings: null,
  /** The route the path explorer draws, for its two ends and snapshot. */
  route: null,
  /** A note being added on the detail, until it is recorded or dropped. */
  note: null,
  /**
   * The cycles the graph held when the session began: how many, each listed one by its members, and
   * whether the list held them all. What a commit note counts as new; state, so a reload keeps it.
   */
  startCycles: null,
}

/**
 * The change function a cell's update runs through. The view lets the wheel's
 * windows go when the marker, the tab or what is open moves (withScrollsKept);
 * every other cell is changed as asked.
 */
export function cellChange<K extends keyof CellValues>(key: K, change: (v: CellValues[K]) => CellValues[K]): (v: CellValues[K]) => CellValues[K] {
  if (key !== 'view') return change
  const view = change as unknown as (v: CellValues['view']) => CellValues['view']
  return ((v: CellValues['view']) => withScrollsKept(v, view(v))) as unknown as (v: CellValues[K]) => CellValues[K]
}
