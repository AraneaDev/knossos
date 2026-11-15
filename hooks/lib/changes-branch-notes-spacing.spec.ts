import { describe, expect, it } from 'vitest'
import { findRow, rawText } from './__tests__/plain-text'
import type { BranchDiff, NoteRow, Notes, SessionLedger } from '../../types'
import { arrange } from './cards'
import { NO_HUES } from './palette'
import { tierOf } from './table'
import { changesArrangement, changesInput } from './changes'
import { ledgerChanges, NO_CHANGES } from './session-changes'
import { branchArrangement, branchInput } from './branch'
import { notesArrangement, notesInput } from './notes-view'

const ROOT = '/work/app'
const ledger: SessionLedger = {
  status: 'ok',
  since: 's1',
  complete: true,
  files_truncated: false,
  tests_truncated: false,
  files: {
    'src/Http/Router.php': { status: 'changed', dependents: 41, boundaries: ['Http', 'Core'], boundary: 'Http', scans: ['s2', 's4'], tests: 3 },
    'src/Core/Kernel.php': { status: 'added', dependents: 12, boundaries: ['Core'], boundary: 'Core', scans: ['s3'], tests: 0 },
    'src/Support/Helper.php': { status: 'deleted', dependents: 0, boundaries: [], boundary: null, scans: ['s3'], tests: 0 },
  },
  tests: [
    { path: 'tests/Http/RouterTest.php', distance: 1 },
    { path: 'tests/Core/KernelTest.php', distance: 2 },
  ],
  scans: [
    { snapshot_id: 's2', at: 1_790_950_000, files: 1 },
    { snapshot_id: 's3', at: 1_790_950_600, files: 2 },
    { snapshot_id: 's4', at: 1_790_951_200, files: 2 },
  ],
}
/** Every change since the session began: where each came from, how many tests reach it, the scans as a timeline. */
const changed = () => changesInput(ledgerChanges(ledger, NO_CHANGES, new Set(['src/Http/Router.php']), ['s2', 's4'], null), ROOT)
const changesAt = (columns: number, selected = 0) => arrange(changesArrangement(changed(), selected, tierOf(columns), NO_HUES), columns, 1_000)

const item = (name: string, boundary: string | null = 'core', kind = 'class') => ({ name, canonical_name: `App\\${name}`, kind, path: `src/${name}.php`, line: 7, boundary })
const diff: BranchDiff = {
  status: 'ok',
  branch: 'feat/tidy',
  default_branch: 'main',
  merge_base: { rev: 'fd137146c3bbdbe4a2ef4756cd2394a670441a58', at: 1_790_953_266 },
  ahead: 4,
  base: { snapshot_id: 's1', rev: 'fd137146c3bbdbe4a2ef4756cd2394a670441a58', at: '2026-10-02T15:01:06Z', match: 'exact', commits: 0 },
  comparison: {
    crossing: { count: 2, items: [{ source: item('GreeterWithAnUnreasonablyLongName', 'core'), target: item('Caller', 'cli') }, { source: item('Short', 'core'), target: item('Kernel', 'cli') }] },
    cycles: { count: 2, items: [{ size: 3, members: [item('A'), item('B'), item('C')] }, { size: 12, members: [item('Long'), item('B'), item('C')] }] },
    hubs: { count: 2, items: [{ component: item('Hub'), before: 9, after: 31 }, { component: item('HubWithALongerName'), before: 120, after: 1_204 }] },
    dead_code: { count: 2, items: [item('Unused', null, 'method'), item('UnusedHelperWithALongName', null, 'method')] },
    violations: { count: 2, truncated: false, items: [{ policy_id: 'core-stays-out', source: 'App\\Core\\Greeter::greet', source_kind: 'method', target: 'App\\Edge\\Caller', target_kind: 'class' }, { policy_id: 'no-cli', source: 'App\\Core\\Kernel::boot', source_kind: 'method', target: 'App\\Cli\\Application', target_kind: 'class' }] },
  },
  files: {
    count: 5,
    truncated: false,
    reach: 61,
    added: 1_264,
    deleted: 8,
    items: [
      { path: 'src/Core/Kernel.php', added: 1_200, deleted: 3, dependents: 40, boundary: 'core', in_graph: true },
      { path: 'src/a/rather/deep/directory/Helper.php', added: 12, deleted: 0, dependents: 5, boundary: 'core', in_graph: true },
      { path: 'src/Http/Router.php', added: 50, deleted: 5, dependents: 33, boundary: 'http', in_graph: true },
      { path: 'README.md', added: 2, deleted: 0, dependents: 0, boundary: null, in_graph: false },
    ],
    boundaries: [
      { boundary: 'core', files: 2, added: 1_212, deleted: 3, reach: 45, outside: false },
      { boundary: 'http', files: 1, added: 50, deleted: 5, reach: 33, outside: false },
      { boundary: null, files: 1, added: 2, deleted: 0, reach: 0, outside: true },
    ],
  },
}
const branchAt = (columns: number, selected = 0) => arrange(branchArrangement(branchInput({ snapshot: 's9', phase: 'done', answer: diff }, ROOT, ['branch:core']), selected, NO_HUES), columns, 1_000)

const row = (component: string, over: Partial<NoteRow> = {}): NoteRow => ({ component, kind: 'note', author: 'model', value: `What ${component} does.`, updated_at: '2026-10-16T11:00:00Z', component_status: 'present', stale: false, stale_reason: null, checked: false, check_reason: null, path: null, boundary: null, ...over }) as NoteRow
const notes: Notes = {
  status: 'ok',
  filter: 'all',
  text: '',
  groups: {
    missing: [],
    stale: [row('App\\Chain', { stale: true, stale_reason: 'written before src/Chain.php changed', path: 'src/Chain.php', boundary: 'core', updated_at: '2026-10-16T10:01:00Z' })],
    rest: [
      row('App\\User', { author: 'user', value: 'Mine.', path: 'src/User.php', boundary: 'core', updated_at: '2026-10-16T10:02:00Z' }),
      row('App\\Orphan', { value: 'On no file.', boundary: 'core', updated_at: '2026-10-15T08:00:00Z' }),
      row('hooks/lib/paths.ts', { path: 'hooks/lib/paths.ts', boundary: 'hooks', author: 'user', value: 'The one place form.', updated_at: '2026-10-14T09:00:00Z' }),
    ],
  },
  counts: { total: 4, missing: 0, stale: 1, flagged: 0 },
  truncated: false,
}
const notesAt = (columns: number, selected = 0) => {
  const input = notesInput({ snapshot: 's1', filter: 'all', text: '', version: 0, phase: 'done', answer: notes }, 'all', '', false, null, ['notes:b+:hooks'])
  return arrange(notesArrangement(input, selected, NO_HUES), columns, 1_000)
}

const KEYS = {
  changes: ['changes-timeline', 'changes-reach', 'changes-untested', 'changes-gap', 'changes-cols', 'change-0', 'change-1', 'change-2', 'tests-cols', 'test-0', 'gap-command', 'command-0'],
  branch: ['branch-left', 'branch-verdict', 'finding-0', 'finding-1', 'finding-2', 'finding-3', 'finding-4', 'finding-5', 'finding-6', 'finding-7', 'finding-8', 'finding-9', 'branch-changed-cols', 'group-10', 'file-11', 'file-12', 'group-13', 'group-14'],
  notes: ['notes-0', 'notes-1', 'notes-2', 'notes-3', 'notes-4', 'notes-5', 'notes-6', 'notes-7', 'notes-detail-place', 'notes-detail-why-0', 'notes-detail-text-0'],
}

/** The rows keyed `keys` in `rows`, as drawn, a grid row's half for its own card. */
const drawn = (rows: ReturnType<typeof changesAt>, keys: string[]) => keys.map(k => rawText(findRow(rows, k)!).trimEnd())
const WIDTHS = [60, 100, 140] as const

describe("Changes on the pane's spacing", () => {
  it('stands its verdict at the body, its marks in the gutter, then a blank row, the table with `dependents`, and the command by its `run` label', () => {
    const at: Record<number, string[]> = {
    60: [
      "    scans •••  2 this session · 1 outside",
      "    3 files → 53 dependents reaching ■ Core ■ Http",
      "  ▲ 1 file no test reaches",
      "",
      "    file                           deps  tests from",
      "  ~ …/Http/Router.php  ■ Http ━━━━   41      3 this session",
      " ›+ …/Core/Kernel.php  ■ Core ━···   12 ▲ none outside",
      "  − …/Helper.php              ····    0        outside",
      "    test                      hops",
      "    tests/Http/RouterTest.php    1",
      "",
      "    run   vendor/bin/phpunit --filter",
    ],
    100: [
      "     scans •••  2 this session · 1 outside",
      "     3 files → 53 dependents reaching ■ Core ■ Http",
      "   ▲ 1 file no test reaches",
      "",
      "     file                                              dependents  tests from",
      "   ~ src/Http/Router.php    ■ Http ━━━━━━━━━━━━━━━━━━━         41      3 this session",
      "  ›+ src/Core/Kernel.php    ■ Core ━━━━━╸·············         12 ▲ none outside",
      "   − src/Support/Helper.php        ···················          0        outside",
      "     test                      hops",
      "     tests/Http/RouterTest.php    1",
      "",
      "     run   vendor/bin/phpunit --filter '(RouterTest|KernelTest)'",
    ],
    140: [
      "     scans •••  2 this session · 1 outside",
      "     3 files → 53 dependents reaching ■ Core ■ Http",
      "   ▲ 1 file no test reaches",
      "",
      "     file                             dependents  tests from",
      "   ~ src/Http/Router.php  ■ Http ━━━━         41      3 this session",
      "  ›+ src/Core/Kernel.php  ■ Core ━···         12 ▲ none outside",
      "   − …/Support/Helper.php        ····          0        outside",
      "     test                      hops",
      "     tests/Http/RouterTest.php    1",
      "",
      "     run   vendor/bin/phpunit --filter '(RouterTest|KernelTest)'",
    ],
  }
    for (const w of WIDTHS) expect(drawn(changesAt(w, 1), KEYS.changes), `${w}`).toEqual(at[w])
  })
})

describe("Branch on the pane's spacing", () => {
  it('puts every finding\'s mark in the gutter, lines each kind up in columns, and right-aligns what changed under its heads', () => {
    const at: Record<number, string[]> = {
    60: [
      "    left main at fd13714 (2026-10-02 17:01)",
      "  ▲ 10 things to look at",
      "  ✗ Greeter::greet → Caller       core-stays-out",
      "  ✗ Kernel::boot   → Application  no-cli",
      "  ↻  3 A → B → C",
      "  ↻ 12 Long → B → C → …",
      "  ▲ GreeterWithAnUnreasonablyLongName ■ core → ■ cli Caller",
      "  ▲ Short                             ■ core → ■ cli Kernel",
      "  ▲ Hub                ■ core    9 →    31 +22",
      "  ▲ HubWithALongerName ■ core  120 → 1,204 +1,084",
      "  ▲ Unused              src/Unused.php:7",
      "  ▲ UnusedHelperWithA…  src/UnusedHelperWithALongName.php:7",
      "                                                 dependents",
      "  ▾ ■ core           ━━━━━━━ 2 files  +1,212 −3          45",
      "      src/Core/Kernel.php             +1,200 −3          40",
      "      …/deep/directory/Helper.php        +12 −0           5",
      " ›▸ ■ http           ╸        1 file     +50 −5          33",
      "  ▸ · outside the g… ╸        1 file      +2 −0",
    ],
    100: [
      "     left main at fd13714 (2026-10-02 17:01)",
      "   ▲ 10 things to look at",
      "   ✗ Greeter::greet → Caller       core-stays-out",
      "   ✗ Kernel::boot   → Application  no-cli",
      "   ↻  3 A → B → C",
      "   ↻ 12 Long → B → C → …",
      "   ▲ GreeterWithAnUnreasonablyLongName ■ core → ■ cli Caller",
      "   ▲ Short                             ■ core → ■ cli Kernel",
      "   ▲ Hub                ■ core    9 →    31 +22",
      "   ▲ HubWithALongerName ■ core  120 → 1,204 +1,084",
      "   ▲ Unused                     src/Unused.php:7",
      "   ▲ UnusedHelperWithALongName  src/UnusedHelperWithALongName.php:7",
      "                                                                      dependents",
      "   ▾ ■ core              ━━━━━━━━━━━━━━━━━━━━━━━━ 2 files  +1,212 −3          45",
      "       src/Core/Kernel.php                                 +1,200 −3          40",
      "       src/a/rather/deep/directory/Helper.php                 +12 −0           5",
      "  ›▸ ■ http              ━                         1 file     +50 −5          33",
      "   ▸ · outside the graph ╸                         1 file      +2 −0",
    ],
    140: [
      "     left main at fd13714 (2026-10-02 17:01)",
      "   ▲ 10 things to look at",
      "   ✗ Greeter::greet → Caller       core-stays-out",
      "   ✗ Kernel::boot   → Application  no-cli",
      "   ↻  3 A → B → C",
      "   ↻ 12 Long → B → C → …",
      "   ▲ GreeterWithAnUnreasonablyLongName ■ core → ■ cli Caller",
      "   ▲ Short                             ■ core → ■ cli Kernel",
      "   ▲ Hub                ■ core    9 →    31 +22",
      "   ▲ HubWithALongerName ■ core  120 → 1,204 +1,084",
      "   ▲ Unused                     src/Unused.php:7",
      "   ▲ UnusedHelperWithALongName  src/UnusedHelperWithALongName.php:7",
      "                                                                            dependents",
      "   ▾ ■ core              ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ 2 files  +1,212 −3          45",
      "       src/Core/Kernel.php                                       +1,200 −3          40",
      "       src/a/rather/deep/directory/Helper.php                       +12 −0           5",
      "  ›▸ ■ http              ━╸                              1 file     +50 −5          33",
      "   ▸ · outside the graph ╸                               1 file      +2 −0",
    ],
  }
    for (const w of WIDTHS) expect(drawn(branchAt(w, 13), KEYS.branch), `${w}`).toEqual(at[w])
  })
})

describe("Notes on the pane's spacing", () => {
  it('folds a boundary in the gutter, counts each group in one column, and spells the marked note out as labelled facts', () => {
    const at: Record<number, string[]> = {
    60: [
      "  ▾ ■ core          3",
      "      Orphan      model  On no file.          2026-10-15",
      "      ▾ src/        2",
      "        User      you    Mine.                2026-10-16",
      " ›      Chain     model  What App\\Chain does. 2026-10-16 ≈",
      "  ▾ ■ hooks         1",
      "      ▾ hooks/lib/  1",
      "        paths.ts  you    The one place form.  2026-10-14",
      "    where    src/Chain.php  ■ core",
      "    status   Stale: written before src/Chain.php changed. v",
      "    note     What App\\Chain does.",
    ],
    100: [
      "   ▾ ■ core          3",
      "       Orphan      model  On no file.          2026-10-15",
      "       ▾ src/        2",
      "         User      you    Mine.                2026-10-16",
      "  ›      Chain     model  What App\\Chain does. 2026-10-16 ≈",
      "   ▾ ■ hooks         1",
      "       ▾ hooks/lib/  1",
      "         paths.ts  you    The one place form.  2026-10-14",
      "     where    src/Chain.php  ■ core",
      "     status   Stale: written before src/Chain.php changed. v says it is still true.",
      "     note     What App\\Chain does.",
    ],
    140: [
      "   ▾ ■ core          3",
      "       Orphan      model  On no file.          2026-10-15",
      "       ▾ src/        2",
      "         User      you    Mine.                2026-10-16",
      "  ›      Chain     model  What App\\Chain does. 2026-10-16 ≈",
      "   ▾ ■ hooks         1",
      "       ▾ hooks/lib/  1",
      "         paths.ts  you    The one place form.  2026-10-14",
      "     where    src/Chain.php  ■ core",
      "     status   Stale: written before src/Chain.php changed. v says it is still true.",
      "     note     What App\\Chain does.",
    ],
  }
    for (const w of WIDTHS) expect(drawn(notesAt(w, 4), KEYS.notes), `${w}`).toEqual(at[w])
  })
})
