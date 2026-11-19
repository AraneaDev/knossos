import { describe, expect, it } from 'vitest'
import type { Dashboard } from '../../types'
import { arrange } from './cards'
import { NO_HUES } from './palette'
import { findRow, rawText } from './__tests__/plain-text'
import { tierOf } from './table'
import { issuesArrangement, issuesInput, issuesWalk } from './views'

const dash = {
  status: 'ok', path: '/work/p', project_root: '/work/p', project_id: 'p1', snapshot_id: 's1', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
  hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 2, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false,
  policy: { status: 'evaluated', total: 1, truncated: false, truncation_reasons: [], items: [{ policy_id: 'p', source: 'Knossos\\Query\\ArchitectureQueryService::fileMetrics', source_kind: 'method', source_boundary: 'core', target: 'KnossosPhpScanner\\FactCollector', target_kind: 'class', target_boundary: 'php-worker', path: 'src/Query/ArchitectureQueryService.php', line: 191 }] },
  diagnostics: { total: 1, errors: 1, warnings: 0, infos: 0, items: [{ severity: 'error', code: 'TS2307', message: "Cannot find module '@scope/missing' or its corresponding type declarations.", path: 'workers/typescript/src/scanner.ts', line: 14 }] },
  dead_code: [{ name: 'beforeTraverse', canonical_name: 'KnossosPhpScanner\\FactCollector::beforeTraverse', kind: 'method', boundary: 'php-worker', reachability: 'unreferenced', confidence: 'possible', path: 'workers/php/src/FactCollector.php', line: 108 }],
} as unknown as Dashboard

/** The three issue rows as drawn at `columns`, each a grid row's half for its own card at 140. */
const drawn = (columns: number) => {
  const rows = arrange(issuesArrangement(issuesInput(dash), 0, tierOf(columns), NO_HUES), columns, 1000)
  return ['pol-0', 'diag-0', 'dead-0'].map(k => rawText(findRow(rows, k)!).trimEnd())
}

describe('an issue row gives up its cells in the order its kind asks', () => {
  it('keeps both names of a policy row first: the folders go, then the file name is cut from its front, then the boundary chip', () => {
    expect(drawn(60)[0]).toBe(' ›✗ ArchitectureQueryServic… → ■ FactCollector …ice.php:191')
    expect(drawn(100)[0]).toBe('  ›✗ ArchitectureQueryService::fileMetrics → ■ FactCollector ■ core …hitectureQueryService.php:191')
    expect(drawn(140)[0]).toBe('  ›✗ ArchitectureQueryService::fileM… → ■ FactCollector …ice.php:191')
  })

  it('keeps the place of a row of one name: its folders go first, then the name to its floor, then the file name', () => {
    expect(drawn(60).slice(1)).toEqual([
      "  ✗ TS2307 Cannot find module '@scope… …/src/scanner.ts:14",
      '  ○ ∅ m FactCollector::… ■ php-worker FactCollector.php:108',
    ])
    expect(drawn(100).slice(1)).toEqual([
      "   ✗ TS2307 Cannot find module '@scope/missing' or its correspo… …/typescript/src/scanner.ts:14",
      '   ○ ∅ m FactCollector::beforeTraverse ■ php-worker …/php/src/FactCollector.php:108',
    ])
    expect(drawn(140).slice(1)).toEqual([
      "   ✗ TS2307 Cannot find module '@scope/miss… …/src/scanner.ts:14",
      '   ○ ∅ m FactCollector::beforeTraverse ■ php-worker workers/php/src/FactCollector.php:108',
    ])
  })
})

describe('the Issues cards stacked', () => {
  it('stand in the order the marker walks them: violations, diagnostics, test findings, dead code, hotspots, then the files over budget', () => {
    const full = {
      ...dash,
      complexity_hotspots: [{ path: 'src/Big.php', language: 'php', lines: 900, dependent_files: 3, score: 2700 }],
      over_budget: { source: 'maintainability-budgets.json', max_function_lines: 205, total: 1, files: [{ path: 'src/Long.php', functions: 1, longest: 260, line: 4 }] },
    } as unknown as Dashboard
    const titles = (columns: number) => arrange(issuesArrangement(issuesInput(full), 0, tierOf(columns), NO_HUES), columns, 1000).filter(r => r.key.endsWith('-head')).map(r => r.key.slice(0, -5))
    expect(titles(60)).toEqual(['policy', 'diag', 'tests', 'dead', 'hotspots', 'budget'])
    expect(titles(100)).toEqual(['policy', 'diag', 'tests', 'dead', 'hotspots', 'budget'])
    expect(issuesWalk(issuesInput(full))).toMatchObject({ violations: { offset: 0 }, diagnostics: { offset: 1 }, tests: { offset: 2 }, dead: { offset: 2 }, hotspots: { offset: 3 }, budget: { offset: 4 } })
  })
})
