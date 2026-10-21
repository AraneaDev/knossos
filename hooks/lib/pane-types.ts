/**
 * What the pane draws, as one value: the components a list walks, the files
 * most depended on, and {@link PaneInput}, everything a render reads from
 * state. Types only; `pane-input.ts` builds it.
 */
import type { Feedback, GitHead, HubSort, PaneTab } from '../../types'
import type { BoundariesInput, CouplingView } from './boundaries'
import type { ChangesInput, LookAt } from './changes'
import type { DriftInput } from './files'
import type { Hues } from './palette'
import type { Loc } from './types'
import type { Stat } from './tiles'
import type { OverviewData } from './overview'
import type { ChurnInput } from './churn'
import type { NotesInput } from './notes-view'
import type { RouteInput } from './route'
import type { BranchInput } from './branch'
import type { FinderInput } from './finder'
import type { CyclesInput } from './cycles'
import type { DetailInput, IssuesInput } from './views'
import type { PaneStatus } from './pane-status'
import type { AllowInput } from './empty-state'

/** One component in a list the selection walks: hubs and hotspots merged. */
export type Item = {
  name: string
  canonical: string
  kind: string
  boundary: string | null
  in: number
  out: number
  cross: number
  /** How many other files reference it; null from a knossos that does not say. */
  files: number | null
  /** Ranked as a hotspot but not as a hub: marked `✦`. */
  hotspotOnly: boolean
  /** Where it is declared, so `e` opens its file; null when the dashboard places it nowhere. */
  loc: Loc | null
  /** The files that depend on it most, for its hover card. */
  top: string[]
}

/** A file many others depend on, as the fan-in map lists it: `loc` opens it. */
export type FileHub = { path: string; dependents: number; boundary: string | null; loc: Loc | null; top: string[] }

/** Everything the pane draws, read from state once per render. */
export type PaneInput = {
  project: string
  status: PaneStatus
  canRescan: boolean
  summary: string[]
  /** The project's languages as the summary line names them (`PHP TS`), or empty. */
  languages: string
  tab: PaneTab
  selected: number
  /** The first item the wheel scrolled each list to, by its card's key (see {@link scrolledTo}). */
  scrolls: Readonly<Record<string, number>>
  showKeys: boolean
  /** Whether plain Buttons are drawn as text (`1: label`): the terminal. Elsewhere they are native. */
  terminal: boolean
  items: Item[]
  partial: boolean
  /** The stat tiles across the top of the Overview. */
  stats: Stat[]
  /** What the Overview's charts draw. */
  overview: OverviewData
  /** The files most depended on, most first. */
  fileHubs: FileHub[]
  /** The in-degree range Hubs is narrowed to (an Overview bucket pressed), and how many components the bucket counts; null for none. */
  degree: { from: number; to: number | null; components: number | null } | null
  /** The hubs tab's filter text, whether its field is open, and its sort. */
  filter: string
  filtering: boolean
  sort: HubSort
  issues: IssuesInput
  cycles: CyclesInput
  /** Null when the knossos that answered sends no boundary matrix. */
  boundaries: BoundariesInput | null
  /** The component on show instead of a tab, or null. */
  detail: DetailInput | null
  /** The allow-root offer and its progress, or null when no root was refused. */
  allow: AllowInput | null
  /** Each boundary's colour, the project's largest first. */
  hues: Hues
  /** Everything this session changed, for the Changes tab. */
  changes: ChangesInput
  /** The Overview's "Look at now", or null before anything changed. */
  lookAt: LookAt | null
  /** The files drifted since the snapshot, when the dashboard names any. */
  drift: DriftInput | null
  /** Whether they are listed under the header: the marker walks them then. */
  driftOpen: boolean
  /** Where the checkout stands; undefined until read, null without git. */
  git: GitHead | undefined
  /** Set when the pane is drawn outside Claude Code: nothing in it asks Claude to do anything. Absent in Claude Code. */
  host?: 'web'
  /** The footer's word after an action, while it lasts. */
  feedback: Feedback | null
  /** The boundary the Boundaries tab's cell runs to, as last stepped to; null for the marked one's top dependency. */
  target: string | null
  /** That cell spelled out, as last read. */
  couplings: CouplingView | null
  /** The rows the latest scan changed, lit for a moment after it landed (see `flash.ts`). */
  lit: ReadonlySet<string>
  /** The finder, while it is open over the pane (`f`); null otherwise. */
  finder: FinderInput | null
  /** The Branch tab: the branch against the snapshot at its merge base. */
  branch: BranchInput
  /** The marked row's detail, drawn beside the tab on a wide pane (master-detail); null when there is none to show. */
  peek: DetailInput | null
  /** The Churn tab: the files changed most that much depends on. */
  churn: ChurnInput
  /** The Notes tab: every note, grouped so what needs the person comes first. */
  notes: NotesInput
  /** The route the path explorer draws instead of a tab or a detail, or null. */
  route: RouteInput | null
  /** The glyph that moves in the status pill (see {@link pulseOf}), or ''. */
  pulse: string
}
