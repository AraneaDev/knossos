import { describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView } from '../../types'
import { boundariesInput, boundaryCountText } from './boundaries'
import { paneInput } from './pane-input'
import { statsOf, summaryParts } from './pane-status'
import { tileRows } from './tiles'
import { paneRows } from './__tests__/pane'
import { plainText } from './__tests__/plain-text'
import type { Row } from './types'

// The Overview's boundaries tile and the Boundaries tab's title count the same boundaries the same way: the declared
// ones, and beside them the inferred ones that label a component, so the two figures add up to what the tab lists.

const declaredNames = Array.from({ length: 28 }, (_, i) => `d${String(i).padStart(2, '0')}`)
const inferredNames = Array.from({ length: 6 }, (_, i) => `ns:I${i}`)

/** A dashboard whose matrix lists `axes`, with `declared` and `inferred` beside the short list (`inferred` absent: an older knossos). */
const dash = (axes: string[], declared: string[], inferred: string[] | undefined): Dashboard => ({
  status: 'ok',
  path: '/work/p',
  project_root: '/work/p',
  project_id: 'p1',
  snapshot_id: 's1',
  freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
  hubs: [],
  hubs_truncated: false,
  hubs_truncation_reasons: [],
  hotspots: [],
  dead_code_candidates: 0,
  dead_code_truncated: false,
  cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
  trend: [{ snapshot_id: 's1', cycles: 0, max_degree: 40 }],
  fan_in: [],
  fan_in_truncated: false,
  summary: { components: 9214, kinds: [], kinds_truncated: false, files: 900, languages: [{ language: 'php', files: 900 }], languages_truncated: false },
  boundaries: {
    items: axes.slice(0, 12).map(name => ({ name, source: declared.includes(name) ? 'explicit' : 'inferred', members: 10 })),
    truncated: axes.length > 12,
    declared,
    declared_truncated: false,
    ...(inferred === undefined ? {} : { inferred, inferred_truncated: false }),
  },
  boundary_matrix: {
    boundaries: axes,
    members: axes.map(() => 10),
    boundaries_truncated: false,
    cells: axes.map((_, from) => axes.map((__, to) => (from === to ? 1 : 0))),
    forbidden: [],
    edges: 9214,
    truncated: false,
    truncation_reasons: [],
  },
})

const view = (tab: KnossosView['tab']): KnossosView => ({ inspect: null, isBandHidden: false, tab, selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' })

const rowsOf = (d: Dashboard, tab: KnossosView['tab'], columns: number): Row[] =>
  paneRows(paneInput({ dashboard: d, brief: null, refresh: { fetchedAt: 0, failed: false }, rescan: { phase: 'idle', reason: null }, view: view(tab), now: 0, terminal: true }), columns, 60)

/** The tiles' figure line (narrow, the summary line) as the pane draws it. */
const tileText = (d: Dashboard, columns: number): string =>
  rowsOf(d, 'overview', columns)
    .filter(r => r.key === 'tiles-0-value' || r.key.startsWith('tiles-line'))
    .map(plainText)
    .join('\n')

/** The Boundaries card's title row (and, beside the map, the table's). */
const titleText = (d: Dashboard, columns: number): string =>
  rowsOf(d, 'boundaries', columns)
    .filter(r => r.key.split('|').some(k => k === 'bounds-list-head' || k === 'bounds-head'))
    .map(plainText)
    .join('\n')

const CASES = {
  both: dash([...declaredNames, ...inferredNames], declaredNames, inferredNames),
  declared: dash(declaredNames, declaredNames, []),
  inferred: dash(inferredNames, [], inferredNames),
  older: dash([...declaredNames, ...inferredNames], declaredNames, undefined),
}

/** `d` with its boundary lists' flags changed, and the matrix cut at its axes when `matrixCut`. */
const cut = (d: Dashboard, over: Partial<NonNullable<Dashboard['boundaries']>>, matrixCut = false): Dashboard => ({
  ...d,
  boundaries: { ...d.boundaries!, ...over },
  boundary_matrix: { ...d.boundary_matrix!, boundaries_truncated: matrixCut },
})

describe('the boundaries tile and the Boundaries title', () => {
  it('say 28 declared and 6 inferred, adding up to the 34 the tab lists', () => {
    const d = CASES.both
    expect(tileText(d, 60)).toBe('9,214 components   28 boundaries +6 inferred   0 cycles\n40 max degree   0 issues   0 drifted')
    expect(tileText(d, 100)).toBe('9,214          │ 28 +6 inferred     │ 0          │ 40             │ 0          │ 0')
    expect(tileText(d, 140)).toBe('9,214                 │ 28 +6 inferred            │ 0                 │ 40                    │ 0                │ 0')
    // Narrow, the edges go before the counts do.
    expect(titleText(d, 60)).toBe('Boundaries                28 declared · 6 inferred')
    expect(titleText(d, 100)).toBe('Boundaries                                 28 declared · 6 inferred · 9,214 edges across')
    expect(titleText(d, 140)).toBe('Boundaries                        28 declared · 6 inferred ──    ── ◈ A d00 ──────────────────────────────────── ■ 10 components')
  })

  it('say only the declared figure when no inferred boundary labels a component', () => {
    const d = CASES.declared
    expect(tileText(d, 60)).toBe('9,214 components   28 boundaries   0 cycles\n40 max degree   0 issues   0 drifted')
    expect(tileText(d, 100)).toBe('9,214           │ 28              │ 0           │ 40              │ 0          │ 0')
    expect(tileText(d, 140)).toBe('9,214                  │ 28                     │ 0                 │ 40                    │ 0                 │ 0')
    expect(titleText(d, 60)).toBe('Boundaries        28 declared · 9,214 edges across')
    expect(titleText(d, 100)).toBe('Boundaries                                              28 declared · 9,214 edges across')
    expect(titleText(d, 140)).toBe('Boundaries                28 declared · 9,214 edges across ──    ── ◈ A d00 ──────────────────────────────────── ■ 10 components')
  })

  it('count the listed boundaries as today when none is declared, with no note', () => {
    const d = CASES.inferred
    expect(tileText(d, 60)).toBe('9,214 components   6 boundaries   0 cycles   40 max degree\n0 issues   0 drifted')
    expect(tileText(d, 100)).toBe('9,214           │ 6               │ 0           │ 40              │ 0          │ 0')
    expect(tileText(d, 140)).toBe('9,214                  │ 6                      │ 0                 │ 40                    │ 0                 │ 0')
    expect(titleText(d, 60)).toBe('Boundaries              6 boundaries · 9,214 edges\nPer boundary                          edges across')
    expect(titleText(d, 100)).toBe('Boundaries                                                    6 boundaries · 9,214 edges\nPer boundary                                                                edges across')
    expect(titleText(d, 140)).toBe('Boundaries                      6 boundaries · 9,214 edges ──    ── ▥ Per boundary ──────────────────────────────── edges across')
  })

  it('stay as they were from a knossos that does not count the inferred boundaries, never reading the missing count as none', () => {
    const d = CASES.older
    expect(tileText(d, 60)).toBe('9,214 components   28 boundaries   0 cycles\n40 max degree   0 issues   0 drifted')
    expect(tileText(d, 100)).toBe('9,214           │ 28              │ 0           │ 40              │ 0          │ 0')
    expect(tileText(d, 140)).toBe('9,214                  │ 28                     │ 0                 │ 40                    │ 0                 │ 0')
    expect(titleText(d, 60)).toBe('Boundaries      34 boundaries · 9,214 edges across')
    expect(titleText(d, 100)).toBe('Boundaries                                            34 boundaries · 9,214 edges across')
    expect(titleText(d, 140)).toBe('Boundaries              34 boundaries · 9,214 edges across ──    ── ◈ A d00 ──────────────────────────────────── ■ 10 components')
  })

  it('mark as a floor only the figure whose own list was cut', () => {
    // The matrix holding fewer axes than label components leaves the dashboard's own counts exact.
    expect(titleText(cut(CASES.both, {}, true), 100)).toBe('Boundaries                                 28 declared · 6 inferred · 9,214 edges across')
    expect(titleText(cut(CASES.both, { inferred_truncated: true }), 100)).toBe('Boundaries                                28 declared · 6+ inferred · 9,214 edges across')
    expect(tileText(cut(CASES.both, { inferred_truncated: true }), 100)).toBe('9,214          │ 28 +6+ inferred     │ 0          │ 40             │ 0          │ 0')
    expect(boundaryCountText(boundariesInput(cut(CASES.declared, { declared_truncated: true }))!)).toBe('28+ declared')
    expect(boundaryCountText(boundariesInput(cut(CASES.inferred, { inferred_truncated: true }))!)).toBe('6+ boundaries')
    // An older knossos: the axes, a floor when the matrix holds fewer than label components.
    expect(titleText(cut(CASES.older, {}, true), 100)).toBe('Boundaries                                           34+ boundaries · 9,214 edges across')
  })

  it('say one boundary as one', () => {
    expect(boundaryCountText(boundariesInput(dash(['ns:I0'], [], ['ns:I0']))!)).toBe('1 boundary')
    expect(boundaryCountText(boundariesInput(dash(['d00', 'ns:I0'], ['d00'], ['ns:I0']))!)).toBe('1 declared · 1 inferred')
  })

  it("drop the tile's note before the tiles wrap or a figure is cut", () => {
    const stats = statsOf(CASES.both, summaryParts(CASES.both, 0), null, null, false)
    const band = (width: number) =>
      tileRows(stats, width, 'medium')
        .filter(r => r.key.endsWith('-value'))
        .map(plainText)
    // 72 holds every tile and the note on one line; at 70 the note goes and the tiles stay on that line, their figures whole.
    expect(band(72)).toEqual(['9,214      │ 28 +6 inferred │ 0      │ 40         │ 0      │ 0'])
    expect(band(70)).toEqual(['9,214       │ 28          │ 0      │ 40         │ 0      │ 0'])
    // Narrow, a line too short for the figure, its label and the note keeps the figure and its label.
    expect(tileRows(stats, 24, 'narrow').map(plainText)).toEqual(['9,214 components', '28 boundaries   0 cycles', '40 max degree   0 issues', '0 drifted'])
  })
})
