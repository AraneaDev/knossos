import { describe, expect, it } from 'vitest'
import type { BoundaryMatrix, Dashboard } from '../../types'
import { boundariesArrangement, boundariesInput, couplingView } from './boundaries'
import { arrange } from './cards'
import { NO_HUES } from './palette'
import { findRow, rawText } from './__tests__/plain-text'
import { tierOf } from './table'
import { cyclesArrangement, cyclesInput } from './cycles'
import { churnArrangement, churnInput } from './churn'

const matrix: BoundaryMatrix = { boundaries: ['tests', 'core', 'typescript-worker', 'module:hooks (+typescript:hooks/tsconfig.json)'], members: [4933, 1633, 403, 175], boundaries_truncated: false, cells: [[12617, 10779, 0, 0], [0, 3513, 0, 0], [0, 0, 765, 0], [0, 0, 0, 438]], forbidden: [[1, 0], [1, 2]], edges: 28112, truncated: false, truncation_reasons: [] }
const dash = { status: 'ok', path: '/work/p', project_root: '/work/p', project_id: 'p1', snapshot_id: 's1', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false, boundary_matrix: matrix } as Dashboard
const answer = { status: 'ok' as const, edges: 812, truncated: false, couplings: [
  { source: { name: 'scan', canonical_name: 'Tests\\ScanTest::scan', kind: 'method', path: 'tests/Scan/ScanTest.php', line: 14 }, target: { name: 'StableId', canonical_name: 'App\\Store\\StableId', kind: 'class', path: 'src/Store/StableId.php', line: 9 }, edges: 700 },
  { source: { name: 'Fixtures', canonical_name: 'Tests\\Fixtures', kind: 'class' }, target: { name: 'open', canonical_name: 'App\\Db\\SqliteConnection::open', kind: 'method', path: 'src/Db/SqliteConnection.php', line: 31 }, edges: 97 },
] }
const ROOT = '/work/app'
const node = (canonical: string, boundary: string) => ({ name: canonical.slice(canonical.lastIndexOf(':') + 1), canonical_name: canonical, kind: 'method', boundary })
const cyc = { count: 2, truncated: false, truncation_reasons: [], largest: [
  { size: 3, members: ['A', 'B', 'C'], nodes: [node('App\\Http\\Router::route', 'http'), node('App\\Core\\Kernel::boot', 'core'), node('App\\Core\\Config::load', 'core')], within_type: false, type: null },
  { size: 2, members: ['D', 'E'], nodes: [node('App\\D::run', 'core'), node('App\\E::run', 'core')], within_type: false, type: null },
] }
const file = (i: number) => ({ path: `src/a/rather/deep/directory/Service${i}.php`, commits: Math.max(1, 30 - i), dependents: Math.max(0, 300 - i * 9), score: Math.max(0, 30 - i) * Math.max(0, 300 - i * 9), boundary: i % 2 === 0 ? 'core' : 'tests' })
const churned = { head: 'abc', phase: 'done' as const, answer: { status: 'ok' as const, days: 30, head: 'abc', commits: 120, truncated: false, files: Array.from({ length: 4 }, (_, i) => file(i)) } }


/** The rows keyed `keys` in `rows`, as drawn, a grid row's half for its own card. */
const drawn = (rows: ReturnType<typeof arrange>, keys: string[]) => keys.map(k => rawText(findRow(rows, k)!).trimEnd())
const WIDTHS = [60, 100, 140] as const

describe("Boundaries on the pane's spacing", () => {
  it('sets the map, its key, the table, the marked boundary and its cell at the body, marks and swatches in the gutter', () => {
    const at: Record<number, string[]> = {
    60: [
      "    from→to               A      B      C      D",
      "  ■ A tests             ██████ ██████   ·      ·",
      "    fewer ░░▒▒▓▓██ more edges   · none   × forbidden",
      "    boundary                           comps    in   out",
      " ›  A tests             ━━━━━━━━━━━━━━  4.9k     0 10.8k",
      "    depends on   ■ core 10,779",
      "    used by      nothing outside itself",
      " │ tests ├───────── 812 edges ────────►│ core │",
      "    ScanTest::scan ──► StableId                700",
      "    …/ScanTest.php:14  src/Store/StableId.php:9",
      "    Fixtures       ──► SqliteConnection::open   97",
      "    ?                  src/Db/SqliteConnection.php:31",
    ],
    100: [
      "     from→to               A      B      C      D",
      "   ■ A tests             ██████ ██████   ·      ·",
      "     fewer ░░▒▒▓▓██ more edges   · none   × forbidden",
      "     boundary                                     comps     in    out",
      "  ›  A tests             ━━━━━━━━━━━━━━━━━━━━━━━━ 4,933      0 10,779",
      "     depends on   ■ core 10,779",
      "     used by      nothing outside itself",
      "  │ tests ├───────── 812 edges ────────►│ core │",
      "     ScanTest::scan ──► StableId                700",
      "     …/ScanTest.php:14  src/Store/StableId.php:9",
      "     Fixtures       ──► SqliteConnection::open   97",
      "     ?                  src/Db/SqliteConnection.php:31",
    ],
    140: [
      "     from→to               A      B      C      D",
      "   ■ A tests             ██████ ██████   ·      ·",
      "     fewer ░░▒▒▓▓██ more edges   · none   × forbidden",
      "     boundary                             comps     in    out",
      "  ›  A tests             ━━━━━━━━━━━━━━━━ 4,933      0 10,779",
      "     depends on   ■ core 10,779",
      "     used by      nothing outside itself",
      "  │ tests ├───────── 812 edges ────────►│ core │",
      "     ScanTest::scan ──► StableId                700",
      "     …/ScanTest.php:14  src/Store/StableId.php:9",
      "     Fixtures       ──► SqliteConnection::open   97",
      "     ?                  src/Db/SqliteConnection.php:31",
    ],
  }
    for (const w of WIDTHS) {
      const rows = arrange(boundariesArrangement(boundariesInput(dash)!, tierOf(w), NO_HUES, 0, null, couplingView({ phase: 'done', answer })), w, 1000)
      expect(drawn(rows, ['heat-head', 'heat-0', 'heat-legend', 'bounds-cols', 'bounds-0', 'focus-out', 'focus-in', 'coupling-pair-1', 'coupling-0', 'coupling-place-0', 'coupling-1', 'coupling-place-1']), `${w}`).toEqual(at[w])
    }
  })

  it('labels what the marked boundary depends on, what uses it and what it may not use, its values in one column', () => {
    const at: Record<number, string[]> = {
    60: [
      "    depends on    nothing outside itself",
      "    used by       ■ tests 10,779",
      "    may not use   × tests  × typescript-worker",
    ],
    100: [
      "     depends on    nothing outside itself",
      "     used by       ■ tests 10,779",
      "     may not use   × tests  × typescript-worker",
    ],
    140: [
      "     depends on    nothing outside itself",
      "     used by       ■ tests 10,779",
      "     may not use   × tests  × typescript-worker",
    ],
  }
    for (const w of WIDTHS) expect(drawn(arrange(boundariesArrangement(boundariesInput(dash)!, tierOf(w), NO_HUES, 1), w, 1000), ['focus-out', 'focus-in', 'focus-forbidden']), `${w}`).toEqual(at[w])
  })
})

describe("Cycles on the pane's spacing", () => {
  it("puts the legend at the body over the boxes, and the list's marker in the gutter", () => {
    const at: Record<number, string[]> = {
    60: [
      "    ■ http  ■ core  ╫ crosses a boundary",
      " ›  cycle 1 · 3 members · ■ core +1 boundary",
      "    cycle 2 · 2 members · ■ core",
    ],
    100: [
      "     ■ http  ■ core  ╫ crosses a boundary",
      "  ›  cycle 1 · 3 members · ■ core +1 boundary",
      "     cycle 2 · 2 members · ■ core",
    ],
    140: [
      "     ■ http  ■ core  ╫ crosses a boundary",
      "  ›  cycle 1 · 3 members · ■ core +1 boundary",
      "     cycle 2 · 2 members · ■ core",
    ],
  }
    for (const w of WIDTHS) expect(drawn(arrange(cyclesArrangement(cyclesInput({ ...dash, cycles: cyc } as Dashboard), tierOf(w), NO_HUES, 0), w, 1000), ['cycle-legend', 'cycle-0', 'cycle-1']), `${w}`).toEqual(at[w])
  })
})

describe("Churn on the pane's spacing", () => {
  it('keeps the scatter on the margin, the rank in the gutter after the marker, and names the dependents column as every table does', () => {
    const at: Record<number, string[]> = {
    60: [
      " 300 │                                                    ◉",
      "     ╰─────────────────────────────────────────────────────",
      "      0             commits → · dependents ↑             30",
      "    file                                       commits deps",
      " ›1 …/rather/deep/directory/Service0.php  ━━━━      30  300",
      "  2 …/rather/deep/directory/Service1.php  ━━━━      29  291",
    ],
    100: [
      "  300 │                                                                                          ◉",
      "      ╰───────────────────────────────────────────────────────────────────────────────────────────",
      "       0                                commits → · dependents ↑                                30",
      "     file                                                                      commits dependents",
      "  ›1 src/a/rather/deep/directory/Service0.php ■ core  ━━━━━━━━━━━━━━━━━━━━━━━━      30        300",
      "   2 src/a/rather/deep/directory/Service1.php ■ tests ━━━━━━━━━━━━━━━━━━━━━━╸·      29        291",
    ],
    140: [
      "  300 │                                                                                                                                  ◉",
      "      ╰───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────",
      "       0                                                    commits → · dependents ↑                                                    30",
      "     file                                                                            commits dependents",
      "  ›1 src/a/rather/deep/directory/Service0.php ■ core  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━      30        300",
      "   2 src/a/rather/deep/directory/Service1.php ■ tests ━━━━━━━━━━━━━━━━━━━━━━━━━━━━··      29        291",
    ],
  }
    for (const w of WIDTHS) expect(drawn(arrange(churnArrangement(churnInput(churned as never, ROOT), 0, tierOf(w)), w, 1000), ['churn-plot-0', 'churn-axis', 'churn-ends', 'churn-cols', 'churn-0', 'churn-1']), `${w}`).toEqual(at[w])
  })
})
