import { describe, expect, it } from 'vitest'
import type { Dashboard, GraphSearch, KnossosView, PathBetween, RouteState } from '../../types'
import { finderInput } from './finder'
import { listFor, subjectOf } from './pane-list'
import { paneInput } from './pane-input'
import { paneLayout } from './layout'
import { routeDiagram, routeInput } from './route'
import { joinErrors } from './__tests__/joins'
import { plainText, rawText } from './__tests__/plain-text'
import { rowWidth } from './rows'

const ROOT = '/work/app'
const d = { status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: 's1', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false } as Dashboard
const FROM = { name: 'App\\Cli\\BriefCommand::answer', label: 'BriefCommand::answer' }
const TO = { name: 'App\\Store\\StableId', label: 'StableId' }
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: TO, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', route: { from: FROM, to: TO, index: 0, back: TO }, ...over })
const node = (canonical: string, kind = 'method', boundary: string | null = 'core') => ({ name: canonical.slice(canonical.lastIndexOf(':') + 1), canonical_name: canonical, kind, boundary })
const chain = ['App\\Cli\\BriefCommand::answer', 'App\\Query\\TurnBriefService::brief', 'App\\Scan\\ProjectScanServiceWithAnUnreasonablyLongName::scan', 'App\\Store\\StableId::scan']
const answer = (over: Partial<PathBetween> = {}): PathBetween => ({
  status: 'ok',
  from: node(FROM.name),
  to: node(TO.name, 'class'),
  reversed: false,
  truncated: false,
  routes: [
    { nodes: chain.map((c, i) => node(c, 'method', i === 2 ? 'scan' : 'core')), hops: [0, 1, 2].map(i => ({ kind: 'calls', confidence: 'certain', path: `src/Deep/Directory/File${i}.php`, line: 10 + i })) },
    { nodes: chain.slice(0, 2).map(c => node(c)), hops: [{ kind: 'constructs', confidence: 'possible', path: null, line: null }] },
  ],
  ...over,
})
const state = (a: PathBetween | null): RouteState => ({ from: FROM.name, to: TO.name, snapshot: 's1', phase: 'done', answer: a })
const pane = (s: RouteState | null, v = view()) => paneInput({ dashboard: d, brief: null, refresh: { fetchedAt: 0, failed: false }, rescan: { phase: 'idle', reason: null }, view: v, now: 0, terminal: true, detail: null, allow: null, sessionRoot: null, extras: { route: s } })
const text = (s: RouteState | null, columns = 100, v = view()) => paneLayout(pane(s, v), columns, 80).body.map(plainText).join('\n')

describe('the path explorer', () => {
  it('names the two ends in the header, and says how the search went', () => {
    expect(text(null)).toContain('Looking for a route from BriefCommand::answer to StableId…')
    expect(plainText(paneLayout(pane(state(answer())), 100, 40).body.find(r => r.key === 'title')!)).toContain('Route › BriefCommand::answer → StableId')
    expect(text(state(answer()))).toContain('2 routes, the strongest first.')
    expect(text(state(answer({ reversed: true })))).toContain('▲  No route from BriefCommand::answer to StableId within 6 hops; StableId reaches')
    expect(text(state(answer({ routes: [] })))).toContain('No route from BriefCommand::answer to StableId, either way, within 6 hops.')
    expect(text(state({ ...answer(), status: 'not-found', unresolved: 'to', routes: [] }))).toContain('knossos knows no component called StableId.')
    expect(text(state(null))).toContain('knossos did not answer')
  })

  it('draws the route as boxes down the pane, each hop labelled with its kind and place, and lists every route', () => {
    const t = text(state(answer()), 120)
    expect(t).toMatch(/Every route found/)
    // Told from the last component every route shares: the list reads by where they part.
    expect(t).toMatch(/›1 … → TurnBriefService::brief → ProjectScanService.* · 3 hops/)
    expect(t).toMatch(/ 2 … → TurnBriefService::brief · 1 hop/)
    expect(t).toMatch(/calls · File0\.php:10/)
    expect(t).toMatch(/│ StableId::scan +│ +■ core/)
    // The second route drawn instead, by its press.
    const second = paneLayout(pane(state(answer()), view({ route: { from: FROM, to: TO, index: 1, back: TO } })), 120, 80).body
    expect(second.map(plainText).join('\n')).toMatch(/Route 2 · 1 hop/)
    expect(second.flatMap(r => r.segments).some(s => s.press?.id === 'route-pick:0')).toBe(true)
  })

  it('walks the boxes of the route drawn: each opens its component, and is what c and q are about', () => {
    const input = pane(state(answer()), view({ selected: 2 }))
    expect(listFor(input).map(o => o.canonical)).toEqual(chain)
    expect(subjectOf(input)?.canonical).toBe(chain[2])
    const presses = paneLayout(input, 120, 80).body.flatMap(r => r.segments.flatMap(s => (s.press === undefined ? [] : [s.press.id])))
    expect(presses).toEqual(expect.arrayContaining(['row:0', 'row:1', 'row:2', 'row:3']))
  })

  it('keeps every diagram clean: nothing wider than the width, every line joined, nothing drawn over anything', () => {
    const shown = routeInput(state(answer()), FROM, TO, 0, ROOT)
    for (const columns of [40, 50, 60, 80, 100, 130, 140, 200]) {
      for (const selected of [-1, 0, 3]) {
        const drawn = routeDiagram(shown.routes[0]!, columns, selected)
        for (const r of drawn.rows) expect(rowWidth(r), `${columns}: ${rawText(r)}`).toBeLessThanOrEqual(columns)
        if (drawn.canvas !== null) {
          expect(joinErrors(drawn.canvas), `${columns}`).toEqual([])
          expect(drawn.canvas.overlaps, `${columns}`).toBe(0)
        }
      }
      for (const height of [24, 40, 60]) {
        for (const s of [null, state(answer()), state(answer({ reversed: true })), state(answer({ routes: [] }))]) {
          const { body, footer } = paneLayout(pane(s), columns, height)
          for (const r of [...body, ...footer]) expect(rowWidth(r), `${columns}x${height} ${r.key}`).toBeLessThanOrEqual(columns)
        }
      }
    }
  })

  it('offers the route key on a component detail only, and back out of the route', () => {
    const detailOnly = paneInput({ dashboard: d, brief: null, refresh: { fetchedAt: 0, failed: false }, rescan: { phase: 'idle', reason: null }, view: view({ route: null }), now: 0, terminal: true, detail: { label: 'StableId', loading: false, messages: null, component: { name: 'StableId', canonical: TO.name, kind: 'class', boundary: null, place: null, loc: null, usedBy: { title: 'Used by', count: '0', items: [] }, uses: { title: 'Uses', count: '0', items: [] }, annotations: [] } } })
    const keys = (input: typeof detailOnly) => paneLayout(input, 120, 40).footer.flatMap(r => r.segments.flatMap(s => (s.press === undefined ? [] : [s.press.id])))
    expect(keys(detailOnly)).toContain('route')
    expect(keys(pane(state(answer())))).not.toContain('route')
    expect(keys(pane(state(answer())))).toContain('back')
  })
})

describe('the finder picking where a route ends', () => {
  const found: GraphSearch = {
    status: 'ok',
    query: 'st',
    truncated: false,
    results: [
      { type: 'component', name: 'StableId', canonical_name: 'App\\Store\\StableId', kind: 'class', path: 'src/Store/StableId.php', line: 9, boundary: 'core' },
      { type: 'file', name: 'src/Store/StableId.php', canonical_name: 'src/Store/StableId.php', kind: 'file', path: 'src/Store/StableId.php', line: null, boundary: null },
    ],
  }
  it('offers components only, and says what it is picking', () => {
    const picking = finderInput({ query: 'st', for: 'st', phase: 'idle', answer: found }, ROOT, 'BriefCommand::answer')
    expect(picking.results.map(r => r.canonical)).toEqual(['App\\Store\\StableId'])
    const input = paneInput({ dashboard: d, brief: null, refresh: { fetchedAt: 0, failed: false }, rescan: { phase: 'idle', reason: null }, view: view({ route: null, finding: true, picking: FROM }), now: 0, terminal: true, detail: null, allow: null, sessionRoot: null, extras: { search: { query: '', for: null, phase: 'idle', answer: null } } })
    const t = paneLayout(input, 100, 40).body.map(plainText).join('\n')
    expect(t).toContain('Route from BriefCommand::answer to…')
    expect(t).toContain('A route from BriefCommand::answer: type the letters of the component it should reach.')
  })
})

describe("a route's hops", () => {
  const derived = {
    nodes: [
      { name: 'go', canonical: 'App\\User::go', kind: 'method', boundary: 'core' },
      { name: 'run', canonical: 'App\\Port::run', kind: 'method', boundary: 'core' },
      { name: 'run', canonical: 'App\\Adapter::run', kind: 'method', boundary: 'core' },
    ],
    hops: [
      { kind: 'calls', place: 'src/User.php:9', loc: null, confidence: 'certain', derived: false },
      { kind: 'implemented_by', place: '', loc: null, confidence: 'probable', derived: true },
    ],
  }

  it('draws a derived hop dotted and says how sure a hop is when it is not certain', () => {
    const wide = routeDiagram(derived, 80, -1).rows.map(plainText).join('\n')
    expect(wide).toContain('┆')
    expect(wide).toContain('implemented_by · probable · derived')
    expect(wide).not.toContain('calls · certain')
    const narrow = routeDiagram(derived, 48, -1).rows.map(plainText).join('\n')
    expect(narrow).toContain('⇣ implemented_by · probable · derived')
    expect(narrow).toContain('↓ calls · src/User.php:9')
  })

  it('reads the confidence and the derived mark from the answer', () => {
    const answer: PathBetween = {
      status: 'ok', from: node('App\\User::go'), to: node('App\\Adapter::run'), reversed: false, truncated: false,
      routes: [{ nodes: [node('App\\User::go'), node('App\\Port::run'), node('App\\Adapter::run')], hops: [{ kind: 'calls', confidence: 'certain', derived: false, path: 'src/User.php', line: 9 }, { kind: 'implemented_by', confidence: 'probable', derived: true, path: null, line: null }] }],
    }
    const input = routeInput({ from: FROM.name, to: TO.name, snapshot: 's1', phase: 'done', answer }, FROM, TO, 0, ROOT)
    expect(input.routes[0]?.hops.map(h => [h.confidence, h.derived])).toEqual([['certain', false], ['probable', true]])
  })

  const enriched = {
    nodes: [
      { name: 'OrderList.vue', canonical: 'web/OrderList.vue', kind: 'file', boundary: null },
      { name: '/task/search/', canonical: 'endpoint:/task/search/', kind: 'endpoint', boundary: null },
      { name: 'urls.search', canonical: 'urls.search', kind: 'route', boundary: null },
      { name: 'search_orders', canonical: 'views.search_orders', kind: 'function', boundary: null },
    ],
    hops: [
      { kind: 'calls_endpoint', place: '', loc: null, confidence: 'probable', derived: false, step: 'relation', origin: 'enricher' },
      { kind: 'represents', place: '', loc: null, confidence: 'probable', derived: false, step: 'identity', origin: 'enricher' },
      { kind: 'calls', place: '', loc: null, confidence: 'certain', derived: false, step: 'relation', origin: 'native' },
    ],
  }

  it('marks an enriched hop and draws an identity hop as same as, with its own arrow, in a narrow pane', () => {
    const narrow = routeDiagram(enriched, 48, -1).rows.map(plainText)
    expect(narrow.map(r => r.trim())).toEqual([
      'OrderList.vue',
      '↓ calls_endpoint · probable · enricher',
      '/task/search/',
      '⇓ same as · probable · enricher',
      'urls.search',
      '↓ calls',
      'search_orders',
    ])
  })

  it('draws an identity hop as a double line labelled same as in a wide pane', () => {
    const wide = routeDiagram(enriched, 80, -1).rows.map(plainText)
    const text = wide.join('\n')
    expect(text).toContain('calls_endpoint · probable · enricher')
    expect(text).toContain('same as · probable · enricher')
    expect(text).not.toContain('represents')
    // Only the identity hop's connector is doubled, and only along its straight stretch.
    const doubled = wide.flatMap((r, y) => (r.includes('║') ? [y] : []))
    const label = wide.findIndex(r => r.includes('same as'))
    expect(doubled.length).toBeGreaterThan(0)
    expect(doubled.every(y => Math.abs(y - label) <= 1)).toBe(true)
    expect(text).not.toContain('┆')
  })

  it('reads the step and the origin from the answer', () => {
    const answer: PathBetween = {
      status: 'ok', from: node('App\\User::go'), to: node('App\\Adapter::run'), reversed: false, truncated: false,
      routes: [{ nodes: [node('App\\User::go'), node('App\\Port::run'), node('App\\Adapter::run')], hops: [
        { kind: 'calls_endpoint', step: 'relation', origin: 'enricher', confidence: 'probable', derived: false, path: null, line: null },
        { kind: 'represents', step: 'identity', origin: 'enricher', confidence: 'probable', derived: false, path: null, line: null },
      ] }],
    }
    const input = routeInput({ from: FROM.name, to: TO.name, snapshot: 's1', phase: 'done', answer }, FROM, TO, 0, ROOT)
    expect(input.routes[0]?.hops.map(h => [h.step, h.origin])).toEqual([['relation', 'enricher'], ['identity', 'enricher']])
  })

  it('treats a hop without a step or an origin, from an older core, as a plain relation', () => {
    const answer: PathBetween = {
      status: 'ok', from: node('App\\User::go'), to: node('App\\Port::run'), reversed: false, truncated: false,
      routes: [{ nodes: [node('App\\User::go'), node('App\\Port::run')], hops: [{ kind: 'calls', confidence: 'certain', path: null, line: null }] }],
    }
    const input = routeInput({ from: FROM.name, to: TO.name, snapshot: 's1', phase: 'done', answer }, FROM, TO, 0, ROOT)
    expect(routeDiagram(input.routes[0]!, 48, -1).rows.map(plainText).join('\n')).toContain('↓ calls')
  })
})
