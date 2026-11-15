import { describe, expect, it, vi } from 'vitest'
import type { BlastRadius, ComponentDetail, Dashboard, KnossosView, RingsState } from '../../types'
import { NO_CHANGES } from '../lib/session-changes'
import { NO_MOTION } from '../lib/motion'
import { LIVE_OFF } from '../lib/live'
import { paneInput } from '../lib/pane-input'
import { listFor } from '../lib/pane-list'
import { detailInput } from '../lib/views'
import { PRESSES } from './actions'
import type { Handlers } from './input'
import type { Port } from './port'
import { drawPane } from './render'

const ROOT = '/work/app'
const NAME = 'App\\Store\\StableId'
const elements = { Box: 'Box', Text: 'Text', Button: 'Button', Markdown: 'Markdown', Input: 'Input', Code: 'Code', Raster: 'Raster' }
const dashboard = { status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: 's1', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false } as Dashboard
const member = (name: string, tested: boolean, boundary = 'core') => ({ name, canonical_name: `App\\${name}`, kind: 'class', path: `src/${name}.php`, line: 3, boundary, tested })
/** The near ring with four, the next with thirty of which twelve are listed: more than its cells show. */
const radius: BlastRadius = {
  status: 'ok',
  component: { name: 'StableId', canonical_name: NAME, kind: 'class', boundary: 'core' },
  truncated: false,
  rings: [
    { hop: 1, count: 4, tested: 3, items: Array.from({ length: 4 }, (_, i) => member(`Near${i}`, i > 0, i === 1 ? 'edge' : 'core')), tests: { count: 1, items: [{ path: 'tests/NearTest.php', hop: 2 }] } },
    { hop: 2, count: 30, tested: 30, items: Array.from({ length: 12 }, (_, i) => member(`MiddleComponentWithALongerName${i}`, true)), tests: { count: 1, items: [{ path: 'tests/MiddleTest.php', hop: 3 }] } },
  ],
}
const detail: ComponentDetail = {
  status: 'ok',
  path: ROOT,
  name: NAME,
  project_id: 'p1',
  snapshot_id: 's1',
  component: { name: NAME, display_name: 'StableId', kind: 'class', path: 'src/Store/StableId.php', line: 9, boundary: 'core', boundaries: ['core'], used_by: { count: 1, truncated: false, names: [], items: [{ name: 'Caller', canonical_name: 'App\\Caller', kind: 'class', boundary: 'core', edges: 2 }] }, uses: { count: 0, truncated: false, names: [], items: [] }, annotations: [] },
  candidates: [],
}
const rings: RingsState = { name: NAME, snapshot: 's1', phase: 'done', answer: radius }
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: { name: NAME, label: 'StableId' }, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })

/** A port over plain cells holding the detail and its rings, its view changed by what is pressed. */
function port(v: KnossosView) {
  const values: Record<string, unknown> = {
    brief: null, dashboard, view: v, detail: { snapshot_id: 's1', name: NAME, detail, phase: 'done' }, refresh: { fetchedAt: 0, failed: false }, motion: NO_MOTION, rescan: { phase: 'idle', reason: null }, allow: null, theme: 'dark',
    changes: NO_CHANGES, sessionRoot: null, live: LIVE_OFF, sessionLedger: null, sessionStart: null, sessionBegan: null, sessionEdits: [], sessionScans: [], sessionRev: null, fileDiff: null, diffFold: null, untestedOnly: false,
    gitHead: null, couplings: null, feedback: null, search: { query: '', for: null, phase: 'idle', answer: null }, peek: null, branch: null, flash: null, churn: null, rings, route: null, note: null, job: null,
  }
  const state = new Proxy({}, { get: (_, key: string) => ({ read: async () => values[key], update: async (change: (x: unknown) => unknown) => (values[key] = change(values[key])) }) })
  return { io: { state, clock: { now: async () => 0, every: () => ({ cancel: () => undefined }) }, ui: { log: () => undefined } } as unknown as Port, view: () => values.view as KnossosView }
}
type Node = { type: string; props: Record<string, unknown> & { children?: unknown }; key: string | null }
function nodesOf(tree: unknown): Node[] {
  const out: Node[] = []
  const walk = (n: unknown) => {
    if (Array.isArray(n)) return n.forEach(walk)
    if (n === null || typeof n !== 'object' || !('type' in n)) return
    out.push(n as Node)
    walk((n as Node).props.children)
  }
  walk(tree)
  return out
}
const spies = (): Handlers => ({ press: vi.fn(), type: vi.fn(), submit: vi.fn() })
const draw = async (io: Port, bodyColumns: number) => nodesOf(await drawPane(io, elements as never, 'terminal', { bodyColumns, scroll: { offset: 0, bodyRows: 60 } }, spies()))
const buttons = (nodes: Node[]) => nodes.filter(n => n.type === 'Button').map(n => String(n.key ?? n.props.key))

describe('the rings on a component page, drawn and pressed', () => {
  it('draws each ring member as a Button that opens it, and hangs no hover card over the rings', async () => {
    const nodes = await draw(port(view()).io, 100)
    // The detail's own dependent, then the rings' near four and the further ones their cells show.
    expect(buttons(nodes).filter(k => /^rel:/.test(k))).toEqual(['rel:0', 'rel:5', 'rel:6', 'rel:7', 'rel:8', 'rel:9', 'rel:1', 'rel:2', 'rel:3', 'rel:4'])
    const near = nodes.find(n => n.type === 'Button' && String(n.key ?? n.props.key) === 'rel:1')!
    expect(near.props.label).toBe('Near0')
    // Resting the pointer on a ring member shows nothing over the rows below: no card hangs off it.
    expect(nodes.filter(n => n.props.hover !== undefined)).toEqual([])
  })

  it("moves the marker onto the first member a ring hides when its +N more is pressed, which the ring then shows marked", async () => {
    const { io, view: now } = port(view())
    const before = await draw(io, 100)
    const more = buttons(before).find(k => k.startsWith('more:'))!
    expect(more).toBe('more:10')
    expect(buttons(before)).not.toContain('rel:10')
    await PRESSES.get('more:')!(io, more.slice(5), undefined, more)
    expect(now().selected).toBe(10)
    const after = await draw(io, 100)
    expect(buttons(after)).toContain('rel:10')
    // The marked member is drawn on the selection's ground, where it stands.
    expect(after.some(n => n.type === 'Box' && String(n.key ?? n.props.key) === 'rel:10-bg')).toBe(true)
  })

  it('walks the members nearest ring first at the narrow tier, each press opening the item the walk names', () => {
    const input = paneInput({ dashboard, brief: null, refresh: { fetchedAt: 0, failed: false }, rescan: { phase: 'idle', reason: null }, view: view(), now: 0, terminal: true, detail: detailInput({ name: NAME, label: 'StableId' }, { snapshot_id: 's1', name: NAME, detail, phase: 'done' }, ROOT), allow: null, sessionRoot: null, extras: { rings, note: null } })
    const list = listFor(input)
    expect(list.map(o => o.name)).toEqual(['Caller', 'Near0', 'Near1', 'Near2', 'Near3', ...Array.from({ length: 12 }, (_, i) => `MiddleComponentWithALongerName${i}`)])
    return (async () => {
      // 40 columns: the rings as lists, drawn in the walk's own order.
      const narrow = await draw(port(view()).io, 40)
      const pressed = narrow.filter(n => n.type === 'Button' && /^rel:\d+$/.test(String(n.key ?? n.props.key)))
      expect(pressed.map(n => Number(String(n.key ?? n.props.key).slice(4)))).toEqual([0, 1, 2, 5, 6])
      for (const n of pressed) expect(list[Number(String(n.key ?? n.props.key).slice(4))]!.name.startsWith(String(n.props.label).replace(/…$/, ''))).toBe(true)
    })()
  })
})
