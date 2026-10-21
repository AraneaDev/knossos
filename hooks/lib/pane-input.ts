/**
 * Everything the pane draws, read from state once per render
 * (`paneInput`): an `ok` dashboard, the view and the mod's state in, a
 * {@link PaneInput} out. Pure.
 */
import type { AllowState, BranchState, ChurnState, CouplingState, Dashboard, Feedback, GitHead, KnossosView, LiveState, NotesState, NoteState, RefreshState, RescanState, RingsState, RouteState, SearchState, SessionChanges, TurnBrief } from '../../types'
import { boundariesInput, couplingView, markedCell } from './boundaries'
import type { BoundariesInput, CouplingView } from './boundaries'
import { changesInput, lookAtOf } from './changes'
import { NO_CHANGES } from './session-changes'
import { driftInput } from './files'
import { huesOf } from './palette'
import { baseName } from './paths'
import { LIVE_OFF } from './live'
import { overviewData } from './overview'
import { branchInput } from './branch'
import { grown, NO_MOTION } from './motion'
import type { Motion } from './motion'
import { churnInput } from './churn'
import { notesInput } from './notes-view'
import { ringsInput } from './rings'
import { routeInput } from './route'
import { litAt } from './flash'
import { finderInput } from './finder'
import type { Flash } from './flash'
import { cyclesInput } from './cycles'
import { issuesInput, locIn } from './views'
import type { DetailInput, NoteInput } from './views'
import type { PaneInput } from './pane-types'
import { paneStatus, languagesOf, statsOf, summaryParts, pulseOf } from './pane-status'
import { allowInput } from './empty-state'
import { mergeRanked } from './pane-list'

/** The pane's state the layout does not read from the dashboard: where the checkout stands, the footer's word, and the Boundaries tab's cell. */
export type PaneExtras = {
  git?: GitHead | undefined
  /** `'web'` when the pane is drawn outside Claude Code (see {@link PaneInput.host}). */
  host?: 'web'
  feedback?: Feedback | null
  couplings?: CouplingState | null
  flash?: Flash | null
  search?: SearchState | null
  branch?: BranchState | null
  churn?: ChurnState | null
  /** The Notes tab's notes, as last read. */
  notes?: NotesState | null
  /** The blast radius of the component the detail shows, as last read. */
  rings?: RingsState | null
  route?: RouteState | null
  /** A note being added on the detail. */
  note?: NoteState | null
  /** Whether the Changes list holds only the files no test reaches. */
  untestedOnly?: boolean
  /** When the last scan landed and the frames drawn since: what the pane's motion draws from. */
  motion?: Motion
}

/**
 * What {@link paneInput} reads: an `ok` dashboard, the turn brief, the
 * refresh and rescan states, the view, the time, whether the pane is in the
 * terminal, and what the mod holds beside them. `detail` is the component on
 * show, if any; `peek` the marked row's detail drawn beside the tab.
 */
export type PaneInputArgs = {
  dashboard: Dashboard
  brief: TurnBrief | null
  refresh: RefreshState
  rescan: RescanState
  view: KnossosView
  now: number
  terminal: boolean
  detail?: DetailInput | null
  allow?: AllowState | null
  session?: SessionChanges
  sessionRoot?: string | null
  live?: LiveState
  extras?: PaneExtras
  peek?: DetailInput | null
}

/** Everything the pane draws, from state (see {@link PaneInputArgs}). */
export function paneInput(args: PaneInputArgs): PaneInput {
  const { dashboard: d, brief, refresh, rescan, view, now, terminal, detail = null, allow = null, session = NO_CHANGES, sessionRoot = null, live = LIVE_OFF, extras = {}, peek = null } = args
  const items = mergeRanked(d)
  const summary = summaryParts(d, items.length)
  const issues = issuesInput(d)
  const hues = huesOf(d)
  const changes = changesInput(session, d.project_root, hues, sessionRoot, extras.untestedOnly === true)
  const drift = driftInput(d)
  const boundaries = boundariesInput(d)
  const turn = brief?.status === 'ok' && brief.policy.status === 'evaluated' ? String(brief.policy.total) : null
  const policy = issues.policy?.evaluated ? issues.policy.total : turn
  const diagnostics = issues.diagnostics === null ? null : issues.diagnostics.errors + issues.diagnostics.warnings
  const lit = litAt(extras.flash ?? null, now)
  const motion = extras.motion ?? NO_MOTION
  const allowing = allowInput(brief, rescan, allow)
  const route = view.route ?? null
  return {
    project: baseName(d.project_root ?? d.path) || (d.project_root ?? d.path),
    status: paneStatus(d, refresh, rescan, now, live),
    // Always on offer, fresh or not: freshness is the snapshot's own account,
    // and a person who knows the code moved should not have to wait for it to
    // agree. Only a scan already running, a rescan or the watcher's, holds it back.
    canRescan: rescan.phase !== 'scanning' && live.phase !== 'scanning' && live.external === undefined,
    summary,
    languages: languagesOf(d),
    tab: view.tab,
    selected: view.selected,
    scrolls: view.scrolls ?? {},
    showKeys: view.showKeys,
    terminal,
    items,
    partial: d.hubs_truncated,
    stats: statsOf(d, summary, policy, diagnostics, drift !== null).map(stat => (lit.has(`tile:${stat.key}`) ? { ...stat, lit: true as const } : stat)),
    overview: overviewData(d, hues, width => grown(width, motion, now)),
    pulse: pulseOf(live, rescan, d, refresh, now, motion),
    fileHubs: [...d.fan_in]
      .sort((a, b) => b.dependent_files - a.dependent_files || a.path.localeCompare(b.path))
      .map(f => ({ path: f.path, dependents: f.dependent_files, boundary: f.boundary ?? null, loc: locIn(d.project_root, f.path), top: f.top_dependents ?? [] })),
    degree: degreeOf(d, view),
    filter: view.filter ?? '',
    filtering: view.filtering ?? false,
    sort: view.sort ?? 'in',
    issues,
    cycles: cyclesInput(d, view.unfolded ?? []),
    boundaries,
    detail: componentDetail(detail, d.project_root, extras, allowing?.phase !== 'confirming', view.inspect?.name ?? null),
    allow: allowing,
    hues,
    changes,
    lookAt: lookAtOf(changes),
    drift,
    driftOpen: drift !== null && view.drift === true,
    git: extras.git,
    ...(extras.host === undefined ? {} : { host: extras.host }),
    feedback: extras.feedback !== undefined && extras.feedback !== null && now < extras.feedback.until ? extras.feedback : null,
    target: view.target ?? null,
    couplings: shownCouplings(boundaries, view, d.snapshot_id ?? null, extras.couplings ?? null),
    lit,
    finder: view.finding === true && extras.search !== undefined && extras.search !== null ? finderInput(extras.search, d.project_root, view.picking?.label ?? null, (view.moving ?? null) !== null) : null,
    branch: branchInput(extras.branch ?? null, d.project_root, view.folds ?? []),
    peek,
    churn: churnInput(extras.churn ?? null, d.project_root),
    notes: notesInput(extras.notes ?? null, view.notesFilter ?? 'all', view.notesText ?? '', view.tab === 'notes' && (view.filtering ?? false), view.notesAsk ?? null, view.folds ?? []),
    route: route === null ? null : routeInput(extras.route ?? null, route.from, route.to, route.index, d.project_root),
  }
}

/**
 * A component's own detail with what only it draws: its blast radius and
 * the notes card that adds one (`m`), with the note under way. A file's
 * detail, and a detail still loading, are left as they are.
 */
function componentDetail(detail: DetailInput | null, root: string | null, extras: PaneExtras, keys: boolean, asked: string | null): DetailInput | null {
  if (detail === null || detail.file !== undefined || detail.component === null) return detail
  const canonical = detail.component.canonical
  // The rings are read by the name the detail was asked for (a short name typed after /knossos inspect, or the full one).
  const rings = extras.rings ?? null
  const ringsOf = rings !== null && (rings.name === asked || rings.name === canonical) ? rings.name : canonical
  const note = extras.note ?? null
  const shown: NoteInput | null = note === null || note.component !== canonical ? null : { phase: note.phase, value: note.value, previous: note.previous, reason: note.reason, keys }
  return { ...detail, rings: ringsInput(rings, ringsOf, root), note: shown, notable: true }
}

/** The in-degree range Hubs is narrowed to, with how many components the Overview's bucket for it counts. */
function degreeOf(d: Dashboard, view: KnossosView): PaneInput['degree'] {
  const range = view.degree
  if (range === undefined || range === null) return null
  const bucket = (d.in_degree?.buckets ?? []).find(b => b.from === range.from && b.to === range.to)
  return { from: range.from, to: range.to, components: bucket?.components ?? null }
}

/**
 * The marked cell's couplings as the card draws them: the stored answer when
 * it is for this cell of this graph, else loading (a read of this one is on
 * its way); null when no cell is marked.
 */
function shownCouplings(boundaries: BoundariesInput | null, view: KnossosView, snapshot: string | null, state: CouplingState | null): CouplingView | null {
  if (boundaries === null || view.tab !== 'boundaries') return null
  const cell = markedCell(boundaries, Math.min(Math.max(0, view.selected), boundaries.boundaries.length - 1), view.target)
  if (cell === null) return null
  const from = boundaries.boundaries[cell.from]!.name
  const to = boundaries.boundaries[cell.to]!.name
  const same = state !== null && state.from === from && state.to === to && state.snapshot === snapshot
  return couplingView(same ? state : { phase: 'loading', answer: null })
}
