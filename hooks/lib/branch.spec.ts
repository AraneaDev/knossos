import { describe, expect, it } from 'vitest'
import type { BranchDiff, Dashboard, KnossosView } from '../../types'
import { branchCount, branchInput, branchList } from './branch'
import { paneInput } from './pane-input'
import { paneLayout } from './layout'
import { plainText } from './__tests__/plain-text'
import { rowsHeight, rowWidth } from './rows'

const ROOT = '/work/app'
const d = { status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: 's9', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false } as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'branch', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })
const item = (name: string, boundary: string | null = 'core', kind = 'class') => ({ name, canonical_name: `App\\${name}`, kind, path: `src/${name}.php`, line: 7, boundary })
/** `n` touched files in core and in http, and three the graph does not hold. */
const touched = (n: number) => {
  const inside = Array.from({ length: n }, (_, i) => ({ path: `src/Touched${i}.php`, added: 12 - i, deleted: i, dependents: 40 - i, boundary: i % 2 === 0 ? 'core' : 'http', in_graph: true }))
  const outside = ['README.md', 'composer.lock', 'docs/x.md'].map(path => ({ path, added: 1, deleted: 0, dependents: 0, boundary: null, in_graph: false }))
  const of = (b: string) => inside.filter(f => f.boundary === b)
  const group = (b: string, reach: number) => ({ boundary: b, files: of(b).length, added: of(b).reduce((s, f) => s + f.added, 0), deleted: of(b).reduce((s, f) => s + f.deleted, 0), reach })
  return {
    count: n + 3,
    truncated: false,
    reach: 61,
    items: [...inside, ...outside],
    added: inside.reduce((s, f) => s + f.added, 0) + 3,
    deleted: inside.reduce((s, f) => s + f.deleted, 0),
    boundaries: [...(n > 0 ? [{ ...group('core', 40), outside: false }] : []), ...(n > 1 ? [{ ...group('http', 33), outside: false }] : []), { boundary: null, files: 3, added: 3, deleted: 0, reach: 0, outside: true }],
  }
}
const none = { crossing: { count: 0, items: [] }, cycles: { count: 0, items: [] }, hubs: { count: 0, items: [] }, dead_code: { count: 0, items: [] }, violations: { count: 0, truncated: false, items: [] } }
const answer = (over: Partial<BranchDiff> = {}, n = 2): BranchDiff => ({
  status: 'ok',
  branch: 'feat/a-rather-long-branch-name-for-the-pane',
  default_branch: 'main',
  merge_base: { rev: 'fd137146c3bbdbe4a2ef4756cd2394a670441a58', at: 1_790_953_266 },
  ahead: 153,
  base: { snapshot_id: 's1', rev: 'fd137146c3bbdbe4a2ef4756cd2394a670441a58', at: '2026-10-02T15:01:06Z', match: 'exact', commits: 0 },
  comparison: {
    crossing: { count: n * 3, items: Array.from({ length: n }, (_, i) => ({ source: item(`GreeterWithAnUnreasonablyLongName${i}`, 'core'), target: item(`Caller${i}`, 'module:cli (+composer:app/cli)') })) },
    cycles: { count: n, items: Array.from({ length: n }, (_, i) => ({ size: 3 + i, members: [item(`A${i}`), item(`B${i}`), item(`C${i}`)] })) },
    hubs: { count: n, items: Array.from({ length: n }, (_, i) => ({ component: item(`Hub${i}`), before: 20 + i, after: 31 + i })) },
    dead_code: { count: n, items: Array.from({ length: n }, (_, i) => item(`Unused${i}`, null, 'method')) },
    violations: { count: n, truncated: false, items: Array.from({ length: n }, (_, i) => ({ policy_id: 'core-stays-out', source: `App\\Core\\Greeter${i}::greet`, source_kind: 'method', target: `App\\Edge\\Caller${i}`, target_kind: 'class' })) },
  },
  files: touched(n),
  ...over,
})
const pane = (a: BranchDiff | null, v = view(), phase: 'loading' | 'done' = 'done') => paneInput({ dashboard: d, brief: null, refresh: { fetchedAt: 0, failed: false }, rescan: { phase: 'idle', reason: null }, view: v, now: 0, terminal: true, detail: null, allow: null, sessionRoot: null, extras: { branch: { snapshot: 's9', phase, answer: a } } })
const rowsOf = (a: BranchDiff | null, v = view(), columns = 100, height = 60) => paneLayout(pane(a, v), columns, height).body
const text = (a: BranchDiff | null, columns = 100, phase: 'loading' | 'done' = 'done') => paneLayout(pane(a, view(), phase), columns, 60).body.map(plainText).join('\n')
const flat = (t: string) => t.replace(/\s+/g, ' ')

describe('the Branch tab: where it stands', () => {
  it('names the branch, how far ahead of main it is, and where it left it', () => {
    const t = flat(text(answer()))
    expect(t).toContain('feat/a-rather-long-branch-name-for-the-pane against main')
    expect(t).toContain('153 commits ahead')
    expect(t).toContain('left main at fd13714')
  })

  it('says how near the snapshot it compares with is when not at the merge base itself, and plainly when there is none', () => {
    expect(flat(text(answer()))).not.toContain('Compared with the snapshot taken at the merge base')
    const before = flat(text(answer({ base: { snapshot_id: 's0', rev: 'aaaaaaa1', at: '2026-10-01T09:00:00Z', match: 'before', commits: 4 } })))
    expect(before).toContain('nearest snapshot before the merge base (aaaaaaa, 2026-10-01 09:00 UTC, 4 commits earlier)')
    const partial = flat(text(answer({ status: 'no-snapshot', base: { snapshot_id: 's5', rev: '4054d3a2fc', at: '2026-10-04T11:05:02Z', match: 'after', commits: 146 } })))
    expect(partial).toContain("▲ No snapshot near the merge base is retained. Compared with the oldest one after it (4054d3a, 2026-10-04 11:05 UTC), which already holds 146 of the branch's 153 commits")
    expect(flat(text(answer({ status: 'no-snapshot', base: null, comparison: null })))).toContain('No snapshot near the merge base is retained, so there is nothing to compare with.')
    expect(text(answer({ status: 'on-default', branch: 'main', comparison: null, files: null }))).toContain('main is main itself: there is no branch to compare.')
    expect(text(answer({ status: 'no-git', comparison: null, files: null }))).toContain('No git repository')
    expect(text(null, 100, 'loading')).toContain('Comparing the branch with its merge base…')
    expect(text(null)).toContain('knossos did not answer')
  })
})

describe('the Branch tab: did it break anything', () => {
  it('says so in one line when nothing broke, no card per check', () => {
    const t = text(answer({ comparison: none }))
    expect(flat(t)).toMatch(/✓ no new cycles +✓ no new crossings +✓ no hubs grew +✓ no dead code +✓ no violations/)
    for (const old of ['New cycles', 'Hubs that grew', 'New dead code', 'New policy violations', 'New cross-boundary dependencies']) expect(t).not.toContain(old)
    // A project with no policies has no violations to rule out.
    expect(text(answer({ comparison: { ...none, violations: null } }))).not.toContain('no violations')
  })

  it('heads what it found with how much, then a row each, the worst first, each walked and opening its component', () => {
    const input = branchInput({ snapshot: 's9', phase: 'done', answer: answer() }, ROOT)
    expect(branchCount(input)).toBe(2 + 2 + 6 + 2 + 2)
    const t = text(answer())
    expect(t).toContain('▲ 14 things to look at')
    // Violations, then cycles, then crossings, then hubs that grew, then dead code.
    expect(branchList(input).slice(0, 10).map(o => o.canonical)).toEqual([
      'App\\Core\\Greeter0::greet', 'App\\Core\\Greeter1::greet', 'App\\A0', 'App\\A1', 'App\\GreeterWithAnUnreasonablyLongName0', 'App\\GreeterWithAnUnreasonablyLongName1', 'App\\Hub0', 'App\\Hub1', 'App\\Unused0', 'App\\Unused1',
    ])
    expect(t.indexOf('Greeter0')).toBeLessThan(t.indexOf('A0 → B0'))
    expect(t).toMatch(/Hub0 .*20 → 31 \+11/)
    // Four of the six crossings were not listed: said, not dropped.
    expect(t).toContain('+4 more cross-boundary dependencies not listed')
    const rows = rowsOf(answer(), view({ selected: 6 }))
    const hub = rows.find(r => r.key.split('|').some(k => k === 'finding-6'))!
    expect(hub.segments.find(s => s.press !== undefined)?.press?.id).toBe('row:6')
    expect(plainText(hub)).toMatch(/^›/)
  })
})

describe('the Branch tab: what changed', () => {
  it('totals the touched files, then a row per boundary, the most reaching first, the files outside the graph last', () => {
    const t = text(answer({}, 4))
    expect(t).toMatch(/What changed +7 files +\+45 −6 · reach 61/)
    const lines = t.split('\n').filter(l => /[▸▾] [■·] /.test(l))
    expect(lines.map(l => l.match(/(http|core|outside the graph)/)?.[1])).toEqual(['core', 'http', 'outside the graph'])
    expect(t).toMatch(/core .*2 files +\+22 −2 +40/)
    expect(t).toMatch(/outside the graph .*3 files +\+3 −0/)
  })

  it('folds each boundary until it is opened: o on its row opens it, its files then listed and walked under it', () => {
    const closed = branchInput({ snapshot: 's9', phase: 'done', answer: answer({}, 4) }, ROOT, [])
    const rows = branchList(closed).filter(o => o.fold !== undefined)
    expect(rows.map(o => o.fold)).toEqual(['branch:core', 'branch:http', 'branch:\u0000outside'])
    expect(branchList(closed).some(o => o.file === true)).toBe(false)
    const open = branchInput({ snapshot: 's9', phase: 'done', answer: answer({}, 4) }, ROOT, ['branch:core'])
    const walk = branchList(open)
    const at = walk.findIndex(o => o.fold === 'branch:core')
    expect(walk.slice(at + 1, at + 3)).toEqual([
      { name: 'src/Touched0.php', canonical: 'src/Touched0.php', loc: { path: `${ROOT}/src/Touched0.php`, line: null }, file: true },
      { name: 'src/Touched2.php', canonical: 'src/Touched2.php', loc: { path: `${ROOT}/src/Touched2.php`, line: null }, file: true },
    ])
    expect(walk[at + 3]?.fold).toBe('branch:http')
    const t = paneLayout(pane(answer({}, 4), view({ folds: ['branch:core'] })), 100, 60).body.map(plainText).join('\n')
    expect(t).toMatch(/src\/Touched0\.php .*\+12 −0 +40 deps/)
    expect(t).not.toContain('src/Touched1.php')
  })

  it('lists and walks the touched files with nothing to compare, and from a knossos that groups none', () => {
    const bare = branchInput({ snapshot: 's9', phase: 'done', answer: answer({ status: 'no-snapshot', base: null, comparison: null }) }, ROOT)
    expect(branchList(bare).map(o => o.fold)).toEqual(['branch:core', 'branch:http', 'branch:\u0000outside'])
    // As an older knossos sends it: the files alone, no groups, no reach, no cut.
    const older = { count: touched(4).count, items: touched(4).items.map(i => ({ path: i.path, added: i.added, deleted: i.deleted, dependents: i.dependents, boundary: i.boundary })) }
    const old = branchInput({ snapshot: 's9', phase: 'done', answer: answer({ files: older } as never) }, ROOT)
    expect(old.changed?.groups.map(g => g.name).sort()).toEqual(['core', 'http', null].sort())
    expect(text(answer({ comparison: none, files: { count: 0, truncated: false, reach: 0, items: [], boundaries: [] } }))).toMatch(/What changed +none/)
    expect(text(answer({ status: 'on-default', branch: 'main', comparison: null, files: null }))).not.toContain('What changed')
  })
})

describe('the Branch tab on the pane', () => {
  it('fits every width and height, empty or full, and fills the height it has', () => {
    for (const a of [answer({}, 30), answer({}, 0), answer({ status: 'no-snapshot', base: null, comparison: null }), null]) {
      for (const columns of [40, 60, 80, 100, 130, 140, 200]) {
        for (const height of [24, 40, 60]) {
          const { body, footer } = paneLayout(pane(a, view({ folds: ['branch:core'] })), columns, height)
          for (const r of [...body, ...footer]) expect(rowWidth(r), `${columns}x${height} ${r.key}`).toBeLessThanOrEqual(columns)
          if (height >= 40) expect(rowsHeight(body) + footer.length, `${columns}x${height}`).toBe(height)
        }
      }
    }
  })

  it('leaves the churn hotspots to the Churn tab', () => {
    const churn = { head: 'h', phase: 'done' as const, answer: { status: 'ok' as const, days: 30, commits: 9, files: Array.from({ length: 8 }, (_, i) => ({ path: `src/Hot${i}.php`, commits: 9 - i, dependents: 40 - i, score: (9 - i) * (40 - i), boundary: 'core' })) } }
    const rows = paneLayout(paneInput({ dashboard: d, brief: null, refresh: { fetchedAt: 0, failed: false }, rescan: { phase: 'idle', reason: null }, view: view(), now: 0, terminal: true, detail: null, allow: null, sessionRoot: null, extras: { branch: { snapshot: 's9', phase: 'done', answer: answer({ comparison: none }) }, churn } }), 200, 60).body
    expect(rows.some(r => r.key.includes('churn'))).toBe(false)
  })

  it('keeps the files no boundary claims apart from those outside the graph, each its own row and fold', () => {
    const loose = { path: 'src/Loose.php', added: 5, deleted: 1, dependents: 3, boundary: null, in_graph: true }
    const doc = { path: 'README.md', added: 1, deleted: 0, dependents: 0, boundary: null, in_graph: false }
    const files = { count: 2, truncated: false, reach: 3, added: 6, deleted: 1, items: [loose, doc], boundaries: [{ boundary: null, files: 1, added: 5, deleted: 1, reach: 3, outside: false }, { boundary: null, files: 1, added: 1, deleted: 0, reach: 0, outside: true }] }
    const input = branchInput({ snapshot: 's9', phase: 'done', answer: answer({ files }) }, ROOT, ['branch:', 'branch:\u0000outside'])
    const walk = branchList(input).slice(input.findings.length)
    expect(walk.map(o => o.fold ?? o.canonical)).toEqual(['branch:', 'src/Loose.php', 'branch:\u0000outside', 'README.md'])
    const t = paneLayout(pane(answer({ files }), view({ folds: ['branch:'] })), 100, 60).body.map(plainText).join('\n')
    expect(t).toMatch(/no boundary .*1 file .*\+5 −1 +3/)
    expect(t).toMatch(/outside the graph .*1 file/)
    expect(t.match(/src\/Loose\.php/g)).toHaveLength(1)
  })

  it('keeps what a boundary reaches, and every all-clear check, on a narrow pane', () => {
    const t = paneLayout(pane(answer({ comparison: none }, 4)), 40, 60).body.map(plainText).join('\n')
    expect(t).toMatch(/core .* 40$/m)
    for (const check of ['no new cycles', 'no new crossings', 'no hubs grew', 'no dead code', 'no violations']) expect(flat(t)).toContain(check)
  })

  it('totals the lines of every touched file, those past the listed ones too', () => {
    const files = { ...touched(2), added: 900, deleted: 40, truncated: true, count: 400 }
    expect(text(answer({ files }))).toMatch(/What changed +400 files +\+900 −40 · reach 61/)
  })
})

