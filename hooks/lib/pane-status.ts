/**
 * The pane's status and its figures: the header's pill (`paneStatus`) and
 * the glyph that moves in it, the summary line, and the Overview's stat
 * tiles. Pure.
 */
import type { Dashboard, LiveState, RefreshState, RescanState } from '../../types'
import { formatAge } from './band'
import { countLabel } from './envelopes'
import { declaredOf, HEADING, STAT_HUES } from './palette'
import type { Tone } from './palette'
import { grouped, plural } from './text'
import { LIVE_OFF } from './live'
import type { Stat } from './tiles'
import { dotGlyph, spinGlyph } from './motion'
import type { Motion } from './motion'
import { cycleCount } from './cycles'
import { SEVERITY } from './marks'

/** The header's status: its tone, the words in its pill, and why, when the pill alone does not say. */
export type PaneStatus = { tone: Tone; text: string; note?: string }

/**
 * The header's status, as its pill says it: the snapshot's state and age
 * (`stale 38m`), or what the rescan, the refresh or the live watcher is
 * doing. While the watcher keeps a fresh graph current it says `live` and how
 * long ago it last moved (`live · 7s`; `following · 7s` when another
 * session's watcher leads). Why, when the pill alone does not say, is its
 * `note`: a rescan's reason, a stuck leader.
 */
export function paneStatus(d: Dashboard, refresh: RefreshState, rescan: RescanState, now: number, live: LiveState = LIVE_OFF): PaneStatus {
  const sinceFetch = refresh.fetchedAt === null ? 0 : now - refresh.fetchedAt
  const age = d.freshness.age_seconds === null ? '' : formatAge(d.freshness.age_seconds * 1000 + sinceFetch)
  const aged = (words: string, joint = ' ') => (age === '' ? words : `${words}${joint}${age}`)
  const state = d.freshness.state
  // The figures on show are still the old snapshot's while a scan runs: say how old, never only that a scan runs.
  if (rescan.phase === 'scanning') return { tone: 'warn', text: refresh.failed ? aged('scanning… · refresh failed') : state === 'fresh' ? 'scanning…' : aged(`scanning… · ${state}`) }
  if (rescan.phase === 'failed') return { tone: 'alert', text: 'scan failed', ...(rescan.reason ? { note: rescan.reason } : {}) }
  if (refresh.failed) return { tone: 'alert', text: aged('refresh failed') }
  if (live.phase === 'scanning') return { tone: 'warn', text: state === 'fresh' ? 'scanning…' : aged(`scanning… · ${state}`) }
  // Another process holds the scan lock: a rescan from here would only wait for it.
  if (live.external !== undefined) {
    const note =
      live.external.host !== undefined
        ? `another host is scanning (${live.external.host}, a container or another machine)`
        : live.external.pid === null
          ? 'another process is scanning'
          : `another process is scanning (pid ${live.external.pid})`
    return { tone: 'warn', text: state === 'fresh' ? 'scanning…' : aged(`scanning… · ${state}`), note }
  }
  // The leader's watcher is stuck or gone quiet: the graph is not being kept current, whatever its age says.
  if (live.phase === 'following' && live.stale === true) return { tone: 'warn', text: aged(state), note: "another session's watcher is stuck" }
  if ((live.phase === 'live' || live.phase === 'following') && state === 'fresh' && d.freshness.drift_files === 0) {
    return { tone: 'ok', text: aged(live.phase === 'live' ? 'live' : 'following', ' · ') }
  }
  return { tone: state === 'fresh' ? 'ok' : 'warn', text: aged(state) }
}

const LANGUAGES: Record<string, string> = { php: 'PHP', javascript: 'JS', typescript: 'TS', python: 'PY', rust: 'RS', go: 'GO', ruby: 'RB', java: 'JAVA' }

/** The project's languages as the summary line names them, most files first; empty when the dashboard does not say. */
export const languagesOf = (d: Dashboard): string => (d.summary?.languages ?? []).map(l => LANGUAGES[l.language] ?? l.language.toUpperCase()).join(' ')

/**
 * The stat tiles: components and boundaries (from a dashboard that reports
 * them), cycles and the largest degree, each with how it moved since the
 * previous snapshot and its trend when every snapshot carries one, then the
 * issues and the drifted files (a press that lists them, when the dashboard
 * names them). Issues is one figure for everything the Issues tab lists: the
 * dead-code candidates, the diagnostics' errors and warnings, the policy
 * violations and the functions over budget, and a press opens that tab. It
 * takes the worst tone among them, violations an error and diagnostics a
 * warning, and no delta or trend, since no snapshot carries that sum. Cycles
 * above zero warn and drift above zero is the accent; the cycles' delta takes
 * the status colours.
 */
export function statsOf(d: Dashboard, summary: string[], policy: string | null, diagnostics: number | null, drift: boolean): Stat[] {
  const above = (value: string) => value !== '0'
  const cycles = cycleCount(d.cycles)
  const maxDegree = d.trend.at(-1)?.max_degree ?? null
  const drifted = d.freshness.drift_files
  const deltas = d.deltas ?? null
  // How a figure moved since the previous snapshot, when the dashboard says; `worse` for a figure that is a status.
  const moved = (figure: 'components' | 'cycles' | 'max_degree' | 'diagnostics', worse = false): Pick<Stat, 'delta' | 'worse'> =>
    deltas === null || typeof deltas[figure] !== 'number' ? {} : { delta: deltas[figure], ...(worse ? { worse: 'up' as const } : {}) }
  // A series only when every snapshot carries it: an older knossos sends cycles and the largest degree alone.
  const series = (pick: (t: Dashboard['trend'][number]) => number | null | undefined): Pick<Stat, 'trend'> => {
    const values = d.trend.map(pick)
    return values.every((v): v is number => typeof v === 'number') ? { trend: values } : {}
  }
  // From the summary line's own parts, so a figure reads the same in both: `9,008 components`, `7+ boundaries`.
  const counted = (part: string | undefined, label: string, extra: Partial<Stat> = {}): Stat[] => (part === undefined ? [] : [{ key: label, label, value: part.slice(0, part.indexOf(' ')), ...extra }])
  // A figure that counts a set the pane lists presses to that list (see the `stat:` press); at zero there is nothing to go to.
  const listing = (key: string, value: string): Pick<Stat, 'press'> => (above(value) ? { press: `stat:${key}` } : {})
  // A summary part's figure, as its tile shows it: `9,008 components` gives `9,008`.
  const figureOf = (part: string | undefined): string => (part === undefined ? '0' : part.slice(0, part.indexOf(' ')))
  return [
    ...(d.summary === undefined
      ? []
      : [
          ...counted(summary[0], 'components', { hue: STAT_HUES.components, ...moved('components'), ...series(t => t.components), ...listing('components', figureOf(summary[0])) }),
          ...counted(summary[1], summary[1]?.endsWith('boundary') ? 'boundary' : 'boundaries', { hue: STAT_HUES.boundaries, ...listing('boundaries', figureOf(summary[1])) }),
        ]),
    // Cycles between types only: the ones inside one type show on the Cycles tab alone.
    { key: 'cycles', label: d.cycles.count === 1 && !d.cycles.truncated ? 'cycle' : 'cycles', value: cycles, hue: STAT_HUES.cycles, ...series(t => t.cycles), ...moved('cycles', true), ...(above(cycles) ? { tone: 'warn' as const } : {}), ...listing('cycles', cycles) },
    ...(maxDegree === null ? [] : [{ key: 'degree', label: 'max degree', value: String(maxDegree), hue: STAT_HUES.degree, ...series(t => t.max_degree), ...moved('max_degree') }]),
    issuesTile(d, policy, diagnostics, listing),
    { key: 'drifted', label: 'drifted', value: grouped(drifted), hue: STAT_HUES.drifted, ...(drifted > 0 ? { tone: 'accent' as const } : {}), ...(drift ? { press: 'drifted' } : {}) },
  ]
}

/**
 * The issues tile: the dead-code candidates, the diagnostics' errors and
 * warnings, the policy violations and the functions over budget, as one
 * figure that presses to the Issues tab. A dead-code or policy list the search cut short
 * makes it a floor, `+`. Its tone is the worst among them.
 */
function issuesTile(d: Dashboard, policy: string | null, diagnostics: number | null, listing: (key: string, value: string) => Pick<Stat, 'press'>): Stat {
  const violations = policy === null ? 0 : Number(policy.replace(/\D/g, '')) || 0
  const errors = diagnostics ?? 0
  const total = d.dead_code_candidates + errors + violations + (d.over_budget?.total ?? 0)
  const floor = d.dead_code_truncated || (policy?.endsWith('+') ?? false)
  const figure = floor ? `${grouped(total)}+` : grouped(total)
  // The worst finding's mark beside the figure, so the tile says how bad as well as how many: a violation or a
  // diagnostic error is an error, a warning alone a warning, as the Issues cards mark them.
  const diagnosticErrors = d.diagnostics?.errors ?? 0
  const worst = violations > 0 || diagnosticErrors > 0 ? SEVERITY.error : errors > 0 ? SEVERITY.warn : null
  const value = worst === null ? figure : `${worst.glyph} ${figure}`
  const tone = worst === SEVERITY.error ? { tone: 'alert' as const } : worst === SEVERITY.warn ? { tone: 'warn' as const } : {}
  // The clean colour only while there are none: dead code or functions over budget alone carry no tone, and are not clean.
  const hue = total === 0 && !floor ? STAT_HUES.issues : HEADING
  return { key: 'issues', label: total === 1 && !floor ? 'issue' : 'issues', value, hue, ...tone, ...listing('issues', value) }
}

/**
 * The header's summary line: components, boundaries, drift and languages
 * from a dashboard that reports them; hubs, cycles and dead code otherwise.
 * Boundaries count the declared ones when there are any.
 */
export function summaryParts(d: Dashboard, hubs: number): string[] {
  const drift = `${d.freshness.drift_files} drifted`
  if (d.summary === undefined) {
    return [
      plural(hubs, 'hub', 'hubs'),
      `${cycleCount(d.cycles)} ${d.cycles.count === 1 && !d.cycles.truncated ? 'cycle' : 'cycles'}`,
      `${countLabel(d.dead_code_candidates, d.dead_code_truncated)} dead code${d.dead_code_probable === undefined ? '' : ` (${d.dead_code_probable} probable)`}`,
      drift,
    ]
  }
  const all = d.boundaries?.items ?? []
  const declared = declaredOf(d).size
  const boundaries = declared > 0 ? declared : all.length
  // Cut short: the declared names past their own cap, or, without declared ones, the list past its.
  // An older knossos names declared ones only in the list: a floor when they fill it.
  const listedDeclared = all.filter(b => b.source === 'explicit').length
  const more =
    d.boundaries?.declared !== undefined && declared > 0
      ? d.boundaries.declared_truncated === true
      : d.boundaries?.truncated === true && (listedDeclared === 0 || listedDeclared === all.length)
  const languages = languagesOf(d)
  // Languages last: the line drops parts from its end, and drift says more than the languages.
  return [
    `${grouped(d.summary.components)} components`,
    `${countLabel(boundaries, more)} ${boundaries === 1 && !more ? 'boundary' : 'boundaries'}`,
    drift,
    ...(languages === '' ? [] : [languages]),
  ]
}

/**
 * What moves in the status pill: a spinner while a scan runs, the live dot
 * (breathing for a moment after a scan lands) while the watcher keeps a fresh
 * graph current, and nothing on any other status.
 */
export function pulseOf(live: LiveState, rescan: RescanState, d: Dashboard, refresh: RefreshState, now: number, motion: Motion): string {
  if (live.phase === 'scanning' || rescan.phase === 'scanning') return spinGlyph(motion.frame)
  const status = paneStatus(d, refresh, rescan, now, live)
  return status.tone === 'ok' && (live.phase === 'live' || live.phase === 'following') && /^(live|following)\b/.test(status.text) ? dotGlyph(motion, now) : ''
}
