/**
 * The pane's lists and the selection that walks them: hubs and hotspots
 * merged, filtered and sorted, the rows the marker walks on each tab, in a
 * detail or in the finder (`listFor`), and what the marked row is about
 * (`subjectOf`, `editTarget`). Pure.
 */
import type { Dashboard, HubSort, Ranked } from '../../types'
import { boundariesList, markedCell } from './boundaries'
import { changesList } from './changes'
import { driftList, fileDetailList } from './files'
import { displayName } from './rows'
import type { Loc } from './types'
import { overviewList as overviewWalk } from './overview'
import { branchList } from './branch'
import { churnList } from './churn'
import { notesList } from './notes-view'
import { routeList } from './route'
import { finderList } from './finder'
import { cyclesList } from './cycles'
import { detailList, issuesList, locIn } from './views'
import type { DetailInput, Openable } from './views'
import type { Item, FileHub, PaneInput } from './pane-types'

export const SORTS: HubSort[] = ['in', 'out', 'cross']

/**
 * Hubs and hotspots as one list, one row per component: the two rankings
 * mostly name the same components, so listing both repeats them. Most
 * depended on first; a hotspot that is not a hub keeps its place after the
 * hubs with the same in-degree.
 */
export function mergeRanked(d: Pick<Dashboard, 'hubs' | 'hotspots'> & Partial<Pick<Dashboard, 'project_root'>>): Item[] {
  const seen = new Map<string, Item>()
  const add = (r: Ranked, hotspotOnly: boolean) => {
    if (seen.has(r.canonical_name)) return
    seen.set(r.canonical_name, {
      name: displayName(r),
      canonical: r.canonical_name,
      kind: r.kind,
      boundary: r.boundary ?? null,
      in: r.in_degree ?? 0,
      out: r.out_degree ?? 0,
      cross: r.cross_boundary_degree ?? 0,
      files: r.dependent_files ?? null,
      hotspotOnly,
      loc: locIn(d.project_root ?? null, r.path ?? null, r.line ?? null),
      top: r.top_dependents ?? [],
    })
  }
  d.hubs.forEach(hub => add(hub, false))
  d.hotspots.forEach(spot => add(spot, true))
  return [...seen.values()].sort((a, b) => b.in - a.in || Number(a.hotspotOnly) - Number(b.hotspotOnly))
}

/**
 * The hubs tab's list: the components whose name (shown or canonical) holds
 * the filter, any case, most first by the sort, ties by in-degree then name.
 */
export function hubList(items: Item[], filter: string, sort: HubSort): Item[] {
  const needle = filter.trim().toLowerCase()
  const kept = needle === '' ? items : items.filter(i => i.name.toLowerCase().includes(needle) || i.canonical.toLowerCase().includes(needle))
  return [...kept].sort((a, b) => b[sort] - a[sort] || b.in - a.in || a.name.localeCompare(b.name))
}

/** The files most depended on whose path holds the filter, any case: the Hubs tab's second list. */
export function fileHubList(files: FileHub[], filter: string): FileHub[] {
  const needle = filter.trim().toLowerCase()
  return needle === '' ? files : files.filter(f => f.path.toLowerCase().includes(needle))
}

/** What a list addresses: the fields the selection, `o`, `e`, `c` and `q` read. */
export type ListInput = Pick<PaneInput, 'tab' | 'items' | 'filter' | 'sort' | 'degree' | 'issues' | 'detail' | 'changes' | 'cycles' | 'boundaries' | 'lookAt' | 'overview' | 'drift' | 'driftOpen' | 'fileHubs'> & Partial<Pick<PaneInput, 'finder' | 'branch' | 'churn' | 'notes' | 'route' | 'showKeys'>>

/** A file row of a list: it opens as the file's detail, and `e` opens the file. */
const fileRow = (path: string, loc: Loc | null): Openable => ({ name: path, canonical: path, loc, file: true })

/**
 * The Overview's walkable rows, in the order a narrow pane draws them: the
 * way to this session's changes, the in-degree buckets, then the
 * cross-boundary flows. Each opens another tab (see `overview.ts`).
 */
export function overviewList(input: Pick<PaneInput, 'overview' | 'changes' | 'host'>): Openable[] {
  return overviewWalk(input.overview, input.changes, input.host)
}

/** The Hubs list, narrowed to the in-degree range when one is set. */
export function hubsShown(input: Pick<PaneInput, 'items' | 'filter' | 'sort' | 'degree'>): Item[] {
  const listed = hubList(input.items, input.filter, input.sort)
  const range = input.degree
  return range === null ? listed : listed.filter(i => i.in >= range.from && (range.to === null || i.in <= range.to))
}

/** The files Hubs lists beside the components: none while an in-degree range narrows it, since a file has no in-degree. */
export const filesShown = (input: Pick<PaneInput, 'fileHubs' | 'filter' | 'degree'>): FileHub[] => (input.degree === null ? fileHubList(input.fileHubs, input.filter) : [])

/** The rows the selection walks: the detail's counterparts, else the drifted files when listed, else the tab's list. */
export function listFor(input: ListInput): Openable[] {
  // The key list stands over everything, the finder included, and lists nothing to walk.
  if (input.showKeys === true) return []
  // The finder stands over everything else: its matches are what the marker walks.
  if (input.finder !== undefined && input.finder !== null) return finderList(input.finder)
  if (input.route !== undefined && input.route !== null) return routeList(input.route)
  if (input.detail !== null) return input.detail.file === undefined ? detailList(input.detail) : input.detail.file === null ? [] : fileDetailList(input.detail.file)
  if (input.driftOpen && input.drift !== null) return driftList(input.drift)
  if (input.tab === 'overview') return overviewList(input)
  if (input.tab === 'hubs') return [...hubsShown(input), ...filesShown(input).map(f => fileRow(f.path, f.loc))]
  if (input.tab === 'changes') return changesList(input.changes)
  if (input.tab === 'cycles') return cyclesList(input.cycles)
  if (input.tab === 'boundaries') return boundariesList(input.boundaries)
  if (input.tab === 'branch') return input.branch === undefined ? [] : branchList(input.branch)
  if (input.tab === 'churn') return input.churn === undefined ? [] : churnList(input.churn)
  if (input.tab === 'notes') return input.notes === undefined ? [] : notesList(input.notes)
  return input.tab === 'issues' ? issuesList(input.issues) : []
}

/** The heat map cell the Boundaries tab marks, by its two boundaries' names: what the couplings are read for; null off that tab or with none. */
export function couplingPair(input: Pick<PaneInput, 'tab' | 'detail' | 'boundaries' | 'selected' | 'target'>): { from: string; to: string } | null {
  if (input.detail !== null || input.tab !== 'boundaries' || input.boundaries === null || input.boundaries.boundaries.length === 0) return null
  const cell = markedCell(input.boundaries, Math.min(Math.max(0, input.selected), input.boundaries.boundaries.length - 1), input.target)
  return cell === null ? null : { from: input.boundaries.boundaries[cell.from]!.name, to: input.boundaries.boundaries[cell.to]!.name }
}

/**
 * The component a copy or an "Ask Claude" is about: the one on show in the
 * detail, else the marked row of the tab's list; null when there is none.
 */
export function subjectOf(input: ListInput & Pick<PaneInput, 'selected'>): Openable | null {
  // A route drawn over a detail: the marked box is the subject, as on any list.
  if (input.detail !== null && (input.route === undefined || input.route === null)) {
    const f = input.detail.file
    if (f !== undefined) return f === null ? null : fileRow(f.path, f.loc)
    const c = input.detail.component
    return c === null ? null : { name: c.name, canonical: c.canonical, loc: c.loc }
  }
  const list = listFor(input)
  const marked = list[Math.min(Math.max(0, input.selected), Math.max(0, list.length - 1))]
  if (marked === undefined) return null
  const { name, canonical, loc, file, inert, copy, ask } = marked
  return { name, canonical, loc: loc ?? null, ...(file ? { file } : {}), ...(inert ? { inert } : {}), ...(copy === undefined ? {} : { copy }), ...(ask === undefined ? {} : { ask }) }
}

/**
 * The place `e` opens in the editor: the file of what the detail shows, else
 * of the marked row, whatever list the marker is in; null when it has none
 * (a hub, a cycle, a boundary).
 */
export function editTarget(input: ListInput & Pick<PaneInput, 'selected'>): Loc | null {
  return subjectOf(input)?.loc ?? null
}

/** The prompt "Ask Claude" submits for a component. */
export const askPrompt = (canonical: string): string =>
  `Using the Knossos graph, what depends on ${canonical} and what would break if I changed it?`

/** The rows the detail beside the tab lists, which its `peek:N` presses open. */
export function peekList(peek: DetailInput): Openable[] {
  return peek.file === undefined ? detailList(peek) : peek.file === null ? [] : fileDetailList(peek.file)
}
