import { describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView } from '../../types'
import { cyclesArrangement, cyclesInput, cyclesList } from './cycles'
import { paneInput } from './pane-input'
import { paneLayout } from './layout'
import { plainText } from './__tests__/plain-text'
import { cyclesOf } from '../mod/state'
import { cyclesSinceStart } from './agent'

const ROOT = '/work/app'
const node = (canonical: string) => ({ name: canonical.slice(canonical.lastIndexOf(':') + 1), canonical_name: canonical, kind: 'method', boundary: 'core' })
const between = { size: 2, members: ['A', 'B'], nodes: [node('App\\A::run'), node('App\\B::run')], within_type: false, type: null }
const inside = { size: 3, members: ['walk_item', 'walk_items', 'walk_mod'], nodes: [node('App\\Walk::walk_item'), node('App\\Walk::walk_items'), node('App\\Walk::walk_mod')], within_type: true, type: 'App\\Walk' }
const dash = (cycles: Dashboard['cycles']): Dashboard =>
  ({ status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: 's1', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles, trend: [], fan_in: [], fan_in_truncated: false }) as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'cycles', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })
const text = (d: Dashboard, v = view(), columns = 100) => paneLayout(paneInput({ dashboard: d, brief: null, refresh: { fetchedAt: 0, failed: false }, rescan: { phase: 'idle', reason: null }, view: v, now: 0, terminal: true, detail: null, allow: null }), columns, 60).body.map(plainText).join('\n')

describe('cycles inside one type', () => {
  const both = dash({ count: 1, truncated: false, truncation_reasons: [], within_type: 1, largest: [between, inside] })

  it('lists them after the others, under their own line, each named by its type, their members walked and opened', () => {
    const t = text(both)
    expect(t).toContain('Within one type')
    expect(t.indexOf('cycle 1')).toBeLessThan(t.indexOf('Within one type'))
    expect(t).toMatch(/Walk · 3 members/)
    expect(cyclesList(cyclesInput(both)).map(i => i.canonical)).toContain('App\\Walk::walk_mod')
  })

  it('counts only the cycles between types, the tab saying how many are inside one type beside them', () => {
    expect(text(both)).toContain('1 · 1 within one type')
    const onlyInside = dash({ count: 0, truncated: false, truncation_reasons: [], within_type: 1, largest: [inside] })
    const t = text(onlyInside)
    expect(t).toContain('0 · 1 within one type')
    expect(t).not.toContain('No dependency cycles.')
    expect(text(onlyInside, view({ tab: 'overview' }))).not.toContain('within one type')
  })

  it('keeps them out of what the session counts as new cycles', () => {
    const start = cyclesOf(dash({ count: 1, truncated: false, truncation_reasons: [], within_type: 0, largest: [between] }))
    expect(start.complete).toBe(true)
    expect(cyclesOf(both)).toMatchObject({ count: 1, complete: true, keys: start.keys })
    expect(cyclesSinceStart(start, { count: both.cycles.count, largest: both.cycles.largest.filter(c => c.within_type !== true) }).fresh).toEqual([])
  })

  it('marks a cut count of them as a floor', () => {
    const cut = dash({ count: 1, truncated: false, truncation_reasons: [], within_type: 50, within_type_truncated: true, largest: [between, inside] })
    expect(text(cut)).toContain('1 · 50+ within one type')
  })

  it('keeps the line over them out of the window until they are in it', () => {
    const pair = (n: number) => ({ ...between, members: [`A${n}`, `B${n}`], nodes: [node(`App\\A${n}::run`), node(`App\\B${n}::run`)] })
    const input = cyclesInput(dash({ count: 3, truncated: false, truncation_reasons: [], within_type: 1, largest: [pair(1), pair(2), pair(3), inside] }))
    const list = cyclesArrangement(input, 'wide').left.find(b => b.key === 'cycles')!
    const rows = (limit: number) => list.make(80, limit)!.body.map(plainText).join('\n')
    expect(rows(3)).toMatch(/cycle 1[\s\S]*cycle 2[\s\S]*cycle 3/)
    expect(rows(3)).not.toContain('Within one type')
  })

  it('names a Rust type by its last path segment', () => {
    const rust = { ...inside, type: 'knossos_rust_worker::visit::state::Walk' }
    const t = text(dash({ count: 0, truncated: false, truncation_reasons: [], within_type: 1, largest: [rust] }))
    expect(t).toMatch(/ Walk · 3 members/)
    expect(t).not.toContain('state::Walk')
  })

  it('leaves them off the Overview tile, which shows the count between types alone', () => {
    const d = { ...both, deltas: { against: 's0', components: 0, cycles: 0, max_degree: 0, dead_code: 0, diagnostics: 0 } }
    for (const columns of [100, 50]) {
      const t = text(d, view({ tab: 'overview' }), columns)
      expect(t).toMatch(/1 ±0|1 cycle ±0/)
      expect(t).not.toContain('within one type')
    }
  })

  it('names a self-loop no type declares by its one member', () => {
    const loop = { size: 1, members: ['recurse'], nodes: [node('knossos_rust_worker::visit::recurse')], within_type: true, type: null }
    expect(text(dash({ count: 0, truncated: false, truncation_reasons: [], within_type: 1, largest: [loop] }))).toMatch(/ recurse · 1 member/)
  })
})
