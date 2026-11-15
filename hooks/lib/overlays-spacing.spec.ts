import { describe, expect, it } from 'vitest'
import type { Dashboard, GraphSearch, KnossosView, PathBetween, RouteState, SearchState } from '../../types'
import { allowRows, emptyRows } from './empty-state'
import { keysBlock } from './chrome'
import { noteFooterRows } from './notes-card'
import { paneLayout } from './layout'
import { paneInput } from './pane-input'
import { previewCard, componentFigures } from './hover'
import { diffSection } from './diff'
import { cardRows } from './cards'
import { tierOf } from './table'
import { findRow, rawText } from './__tests__/plain-text'
import type { Row } from './types'

const ROOT = '/work/app'
const d = {
  status: 'ok',
  path: ROOT,
  project_root: ROOT,
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
  trend: [],
  fan_in: [],
  fan_in_truncated: false,
} as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({
  inspect: null,
  isBandHidden: false,
  tab: 'issues',
  selected: 0,
  showKeys: false,
  filter: '',
  filtering: false,
  sort: 'in',
  ...over,
})
const base = {
  dashboard: d,
  brief: null,
  refresh: { fetchedAt: 0, failed: false },
  rescan: { phase: 'idle', reason: null },
  now: 0,
  terminal: true,
  detail: null,
  allow: null,
  sessionRoot: null,
} as const
const WIDTHS = [60, 100, 140] as const

/** The rows keyed `keys`, as drawn, trailing spaces off. */
const drawn = (rows: Row[], keys: string[]) => keys.map(k => rawText(findRow(rows, k)!).trimEnd())
const body = (input: ReturnType<typeof paneInput>, columns: number) => paneLayout(input, columns, 60).body

describe("the finder on the pane's spacing", () => {
  const search: GraphSearch = {
    status: 'ok',
    query: 'dash',
    truncated: false,
    results: [
      {
        type: 'component',
        name: 'cycle',
        canonical_name: 'App\\Query\\DashboardService::cycle',
        kind: 'method',
        path: 'src/Query/deeply/nested/DashboardService.php',
        line: 40,
        boundary: 'core',
      },
      {
        type: 'file',
        name: 'src/Query/Dashboard.php',
        canonical_name: 'src/Query/Dashboard.php',
        kind: 'file',
        path: 'src/Query/Dashboard.php',
        line: null,
        boundary: null,
      },
    ],
    notes: [
      {
        canonical_name: 'App\\Old\\DashboardLegacy',
        author: 'user',
        text: 'Replaced in 0.40.',
      },
    ],
  } as GraphSearch
  const state: SearchState = {
    query: 'dash',
    for: 'dash',
    phase: 'idle',
    answer: search,
  }
  it('sets the field and the matches at the body, the marker and a gone name note mark in the gutter, each place in the one form', () => {
    const at: Record<number, string[]> = {
      60: ['    find: dash', '    DashboardService::cycle ■ core', ' ›  src/Query/Dashboard.php', '    Notes on names the graph no longer holds:', '  ▲ DashboardLegacy  you  Replaced in 0.40.'],
      100: ['     find: dash', '     m DashboardService::cycle ■ core src/Query/deeply/nested/DashboardService.php:40', '  ›    src/Query/Dashboard.php', '     Notes on names the graph no longer holds:', '   ▲ DashboardLegacy  you  Replaced in 0.40.'],
      140: [
        '     find: dash',
        '     m DashboardService::cycle ■ core src/Query/deeply/nested/DashboardService.php:40',
        '  ›    src/Query/Dashboard.php',
        '     Notes on names the graph no longer holds:',
        '   ▲ DashboardLegacy  you  Replaced in 0.40.',
      ],
    }
    for (const w of WIDTHS)
      expect(
        drawn(
          body(
            paneInput({
              ...base,
              view: view({ finding: true, selected: 1 }),
              extras: { search: state },
            } as never),
            w,
          ),
          ['find-row', 'found-0', 'found-1', 'found-notes', 'found-note-0'],
        ),
        `${w}`,
      ).toEqual(at[w])
  })
})

describe("the route explorer on the pane's spacing", () => {
  const FROM = {
    name: 'App\\Cli\\BriefCommand::answer',
    label: 'BriefCommand::answer',
  }
  const TO = { name: 'App\\Store\\StableId', label: 'StableId' }
  const node = (canonical: string, boundary = 'core') => ({
    name: canonical.slice(canonical.lastIndexOf(':') + 1),
    canonical_name: canonical,
    kind: 'method',
    boundary,
  })
  const answer: PathBetween = {
    status: 'ok',
    from: node(FROM.name),
    to: node(TO.name),
    reversed: true,
    truncated: false,
    routes: [
      {
        nodes: [node(FROM.name), node('App\\Query\\TurnBriefService::brief'), node('App\\Store\\StableId::scan')],
        hops: [0, 1].map(i => ({
          kind: 'calls',
          confidence: 'certain',
          path: `src/Deep/Directory/File${i}.php`,
          line: 10 + i,
        })),
      },
      {
        nodes: [node(FROM.name), node('App\\Store\\StableId::scan')],
        hops: [
          {
            kind: 'constructs',
            confidence: 'possible',
            path: null,
            line: null,
          },
        ],
      },
    ],
  } as PathBetween
  const state: RouteState = {
    from: FROM.name,
    to: TO.name,
    snapshot: 's1',
    phase: 'done',
    answer,
  }
  const v = view({
    inspect: TO,
    tab: 'hubs',
    route: { from: FROM, to: TO, index: 0, back: TO },
  })
  it('says the search at the body with its warning in the gutter, numbers each route in the gutter, and draws the boxes at the body', () => {
    const at: Record<number, string[]> = {
      60: [
        '  ▲ No route from BriefCommand::answer to StableId within 6',
        ' ›1 BriefCommand::answer → TurnBriefService::brie… · 2 hops',
        '  2 BriefCommand::answer → StableId::scan · 1 hop',
        ' ╭─────────────────────────╮',
        ' │ BriefCommand::answer    │  ■ core',
        '              │ calls · src/Deep/Directory/File0.php:10',
      ],
      100: [
        '   ▲ No route from BriefCommand::answer to StableId within 6 hops; StableId reaches',
        '  ›1 BriefCommand::answer → TurnBriefService::brief → StableId::scan · 2 hops',
        '   2 BriefCommand::answer → StableId::scan · 1 hop',
        '  ╭─────────────────────────╮',
        '  │ BriefCommand::answer    │  ■ core',
        '               │ calls · src/Deep/Directory/File0.php:10',
      ],
      140: [
        '   ▲ No route from BriefCommand::answer to StableId within 6 hops; StableId reaches BriefCommand::answer: 2 routes, the strongest first.',
        '  ›1 BriefCommand::answer → TurnBriefService::brief → StableId::scan · 2 hops',
        '   2 BriefCommand::answer → StableId::scan · 1 hop',
        '  ╭─────────────────────────╮',
        '  │ BriefCommand::answer    │  ■ core',
        '               │ calls · src/Deep/Directory/File0.php:10',
      ],
    }
    for (const w of WIDTHS)
      expect(
        drawn(
          body(
            paneInput({
              ...base,
              view: v,
              extras: { route: state },
            } as never),
            w,
          ),
          ['route-said-0-0', 'route-other-0', 'route-other-1', 'route-0', 'route-1', 'route-3'],
        ),
        `${w}`,
      ).toEqual(at[w])
  })
})

describe("the key list, the screens and the footer's note on the pane's spacing", () => {
  it('lists each key at the body, its action in one column, and the line about clicks after a blank row', () => {
    const at: Record<number, string[]> = {
      60: ['    1–9    switch tabs (or click one)', '    j k    move the marker (or Tab, the arrows, or a', '', '    A file:line opens in your editor on a click. Every'],
      100: ['     1–9    switch tabs (or click one)', '     j k    move the marker (or Tab, the arrows, or a click)', '', '     A file:line opens in your editor on a click. Every key has a button.'],
      140: ['     1–9    switch tabs (or click one)', '     j k    move the marker (or Tab, the arrows, or a click)', '', '     A file:line opens in your editor on a click. Every key has a button.'],
    }
    for (const w of WIDTHS) {
      const rows = cardRows(keysBlock().make(w - 4, 100)!, w, tierOf(w))
      expect(drawn(rows, ['help-0', 'help-1', 'key-note-gap', 'key-note-0']), `${w}`).toEqual(at[w])
    }
  })

  it('sets the no-graph screen and the allow-root offer on the margin, the offer mark in the gutter and its keys at the body', () => {
    const offer = {
      root: '/home/me/project',
      rootsFile: '/home/me/.knossos/roots.json',
      phase: 'idle' as const,
      reason: null,
    }
    const at: Record<number, string[]> = {
      60: [' No architecture graph yet', '    Knossos has not scanned this project, so there is', '    nothing to draw.', '', '  ▲ /home/me/project is not an allowed root', '    a: allow root'],
      100: ['  No architecture graph yet', '     Knossos has not scanned this project, so there is nothing to draw.', '', '   ▲ /home/me/project is not an allowed root', '     a: allow root'],
      140: ['  No architecture graph yet', '     Knossos has not scanned this project, so there is nothing to draw.', '', '   ▲ /home/me/project is not an allowed root', '     a: allow root'],
    }
    for (const w of WIDTHS) {
      const rows = emptyRows('unscanned', offer, w)
      expect(
        rows.map(r => rawText(r).trimEnd()),
        `${w}`,
      ).toEqual(at[w])
    }
    expect(allowRows({ ...offer, phase: 'running' }, 100).map(r => rawText(r).trimEnd())).toEqual(['     allowing /home/me/project…'])
  })

  it('labels the note under way as the notes card labels a note, what Enter does in its value column', () => {
    const note = {
      phase: 'editing',
      value: 'Keep it pure',
      previous: null,
      reason: null,
      keys: true,
    } as never
    expect(noteFooterRows('StableId', note, 56).map(r => rawText(r).trimEnd())).toEqual(['note   Keep it pure', '       Enter checks it with knossos; nothing is written', '       yet.'])
    expect(noteFooterRows('StableId', { ...(note as object), phase: 'confirming' } as never, 96).map(r => rawText(r).trimEnd())).toEqual(['Record this note on StableId? "Keep it pure"', 'y: record  n: cancel'])
  })

  it('lists the files a hover card names in the one form of a place, and the diff card says what it folds as +N more', () => {
    expect(
      previewCard(
        'card',
        {
          name: 'StableId',
          boundary: 'core',
          figures: componentFigures(3, 5, 0),
          top: ['src/Query/a/rather/long/folder/DashboardService.php'],
        },
        40,
      ).rows.map(r => rawText(r)),
    ).toEqual(['╭──────────────────────────────────────╮', '│ StableId  ■ core                     │', '│ 3 files depend on it · in 5 · out 0  │', '│ ← …/long/folder/DashboardService.php │', '╰──────────────────────────────────────╯'])
    const hunk = (at: number) => ({
      oldStart: at,
      newStart: at,
      heading: '',
      lines: Array.from({ length: 14 }, (_, i) => `+line ${i}`),
    })
    const view = {
      phase: 'diff',
      path: 'src/New/Name.php',
      added: 14,
      removed: 0,
      hunks: [1, 40, 80, 120, 160, 200].map(hunk),
      renamed: 'src/Old/Name.php',
      truncated: true,
    } as const
    const keys = ['diff-renamed', 'diff-more-0', 'diff-hunks-more', 'diff-cut', 'diff-full']
    expect(drawn(diffSection(view, 56, true).body, keys)).toEqual(['   src/Old/Name.php', '   +2 more lines', '   +2 more changes further down the file', '   The diff is cut here: open the file to see the rest.', '   open the full diff'])
    expect(
      diffSection(
        {
          phase: 'message',
          text: 'The file is not in the scan record: it was added outside the graph, so there is nothing to compare.',
        },
        56,
      ).body.map(r => rawText(r).trimEnd()),
    ).toEqual(['   The file is not in the scan record: it was added', '   outside the graph, so there is nothing to compare.'])
  })
})
