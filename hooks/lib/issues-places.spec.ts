import { describe, expect, it } from 'vitest'
import type { Dashboard } from '../../types'
import { arrange } from './cards'
import { NO_HUES } from './palette'
import { findRow, rawText } from './__tests__/plain-text'
import { tierOf } from './table'
import { issuesArrangement, issuesInput } from './views'

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
  it('keeps both names of a policy row first: the boundary chip goes, then the folders, then the file name is cut from its front', () => {
    expect(drawn(60)[0]).toBe(' ›✗ ArchitectureQueryServic… → ■ FactCollector …ice.php:191')
    expect(drawn(100)[0]).toBe('  ›✗ ArchitectureQueryService::fileMetrics → ■ FactCollector ArchitectureQueryService.php:191')
    expect(drawn(140)[0]).toBe('  ›✗ ArchitectureQueryService::fileM… → ■ FactCollector …ice.php:191')
  })

  it('keeps the place of a row of one name: its folders go first, then the name to its floor, then the file name', () => {
    expect(drawn(60).slice(1)).toEqual([
      "  ✗ TS2307 Cannot find module '@scope… …t/src/scanner.ts:14",
      '  ○ ∅ m FactCollector::… ■ php-worker FactCollector.php:108',
    ])
    expect(drawn(100).slice(1)).toEqual([
      "   ✗ TS2307 Cannot find module '@scope/missing' or its correspo… …ers/typescript/src/scanner.ts:14",
      '   ○ ∅ m FactCollector::beforeTraverse ■ php-worker …rs/php/src/FactCollector.php:108',
    ])
    expect(drawn(140).slice(1)).toEqual([
      "   ✗ TS2307 Cannot find module '@scope/miss… …ript/src/scanner.ts:14",
      '   ○ ∅ m FactCollector::beforeTraverse ■ php-worker workers/php/src/FactCollector.php:108',
    ])
  })
})
