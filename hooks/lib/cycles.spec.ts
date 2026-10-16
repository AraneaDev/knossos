import { describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView } from '../../types'
import { cyclesInput, cyclesList } from './cycles'
import { paneInput, paneLayout } from './layout'
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
const text = (d: Dashboard, v = view(), columns = 100) => paneLayout(paneInput(d, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, v, 0, true, null, null), columns, 60).body.map(plainText).join('\n')

describe('cycles inside one type', () => {
  const both = dash({ count: 1, truncated: false, truncation_reasons: [], within_type: 1, largest: [between, inside] })

  it('lists them after the others, under their own line, each named by its type, their members walked and opened', () => {
    const t = text(both)
    expect(t).toContain('Within one type')
    expect(t.indexOf('cycle 1')).toBeLessThan(t.indexOf('Within one type'))
    expect(t).toMatch(/Walk · 3 members/)
    expect(cyclesList(cyclesInput(both)).map(i => i.canonical)).toContain('App\\Walk::walk_mod')
  })

  it('counts only the cycles between types, saying how many are inside one type beside them', () => {
    expect(text(both)).toContain('1 · 1 within one type')
    const onlyInside = dash({ count: 0, truncated: false, truncation_reasons: [], within_type: 1, largest: [inside] })
    const t = text(onlyInside)
    expect(t).toContain('0 · 1 within one type')
    expect(t).not.toContain('No dependency cycles.')
    expect(text(onlyInside, view({ tab: 'overview' }))).toMatch(/0 · 1 within one type/)
  })

  it('keeps them out of what the session counts as new cycles', () => {
    const start = cyclesOf(dash({ count: 1, truncated: false, truncation_reasons: [], within_type: 0, largest: [between] }))
    expect(start.complete).toBe(true)
    expect(cyclesOf(both)).toMatchObject({ count: 1, complete: true, keys: start.keys })
    expect(cyclesSinceStart(start, { count: both.cycles.count, largest: both.cycles.largest.filter(c => c.within_type !== true) }).fresh).toEqual([])
  })
})
