import { beforeEach, describe, expect, it } from 'vitest'
import type { BoundaryMatrix, Dashboard, KnossosView, TurnBrief } from '../../types'
import { NO_CHANGES } from '../lib/layout'
import { BUSY, BUSY_SPIN_MS, NO_MOTION, spinGlyph } from '../lib/motion'
import { LIVE_OFF } from '../lib/live'
import type { Port } from './port'
import { currentInput, drawnText, drawPane, TEXT_BUDGET } from './render'
import type { Handlers } from './render'
import { mod, reset } from './state'

const ROOT = '/work/app'
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: true, watchPollMs: 1000, notifications: true }

/** The surface's elements by name: the JSX stub turns each into `{ type: '<Name>', props, key }`, which serializes as the engine would weigh it. */
const elements = { Box: 'Box', Text: 'Text', Button: 'Button', Markdown: 'Markdown', Input: 'Input', Code: 'Code', Raster: 'Raster' }
/** Mobile has no text field. */
const mobileElements = Object.fromEntries(Object.entries(elements).filter(([name]) => name !== 'Input'))

const hubs = (n: number) =>
    Array.from({ length: n }, (_, i) => ({
        name: `Component${i}`,
        canonical_name: `App\\Component${i}`,
        kind: 'class',
        boundary: 'core',
        in_degree: n - i,
        out_degree: 1,
        cross_boundary_degree: 0,
        dependent_files: 5,
        top_dependents: ['src/a.php', 'src/b.php'],
        path: `src/C${i}.php`,
        line: 3,
    }))

const dashboard = (over: Partial<Dashboard> = {}): Dashboard =>
    ({
        status: 'ok',
        path: ROOT,
        project_root: ROOT,
        project_id: 'p1',
        snapshot_id: 's1',
        freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
        hubs: hubs(40),
        hubs_truncated: false,
        hubs_truncation_reasons: [],
        hotspots: [],
        dead_code_candidates: 0,
        dead_code_truncated: false,
        cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
        trend: [],
        fan_in: [],
        fan_in_truncated: false,
        ...over,
    }) as Dashboard

const view = (over: Partial<KnossosView> = {}): KnossosView => ({
    inspect: null,
    isBandHidden: false,
    tab: 'hubs',
    selected: 0,
    showKeys: false,
    filter: '',
    filtering: false,
    sort: 'in',
    ...over,
})

/** A port over plain cells (what drawing reads) and a log of what it wrote to the debug log. */
function fakePort(cells: Record<string, unknown> = {}) {
    const values: Record<string, unknown> = {
        brief: null,
        dashboard: dashboard(),
        view: view(),
        detail: null,
        refresh: { fetchedAt: 0, failed: false },
        motion: NO_MOTION,
        rescan: { phase: 'idle', reason: null },
        allow: null,
        theme: 'dark',
        changes: NO_CHANGES,
        sessionRoot: null,
        live: LIVE_OFF,
        sessionLedger: null,
        sessionStart: null,
        sessionBegan: null,
        sessionEdits: [],
        sessionScans: [],
        sessionRev: null,
        fileDiff: null,
        diffFold: null,
        untestedOnly: false,
        gitHead: null,
        couplings: null,
        feedback: null,
        search: { query: '', for: null, phase: 'idle', answer: null },
        peek: null,
        branch: null,
        flash: null,
        churn: null,
        rings: null,
        route: null,
        note: null,
        job: null,
        ...cells,
    }
    const reads: string[] = []
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => {
                    reads.push(key)
                    return values[key]
                },
                update: async (change: (v: unknown) => unknown) => (values[key] = change(values[key])),
            }),
        },
    )
    const logs: { text: string; options: unknown }[] = []
    const timers: number[] = []
    const io = {
        state,
        clock: {
            now: async () => 0,
            every: (ms: number) => {
                timers.push(ms)
                return { cancel: () => undefined }
            },
        },
        ui: { log: (text: string, opts?: unknown) => void logs.push({ text, options: opts }) },
    } as unknown as Port
    return { io, logs, reads, timers }
}

const act: Handlers = { press: () => undefined, type: () => undefined, submit: () => undefined }

type Node = { type: string; props: Record<string, unknown> & { children?: unknown }; key: string | null }

/** Every element in the tree, depth first. */
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

/** All the words the tree shows: its text, its button labels and its links. */
function textOf(tree: unknown): string {
    const parts: string[] = []
    const walk = (n: unknown) => {
        if (typeof n === 'string') return void parts.push(n)
        if (Array.isArray(n)) return n.forEach(walk)
        if (n === null || typeof n !== 'object' || !('props' in n)) return
        const p = (n as Node).props
        if (typeof p.label === 'string') parts.push(p.label)
        if (typeof p.text === 'string') parts.push(p.text)
        walk(p.children)
    }
    walk(tree)
    return parts.join('\n')
}

const children = (n: Node): Node[] =>
    (Array.isArray(n.props.children) ? (n.props.children as unknown[]).flat(Infinity) : [n.props.children]).filter((c): c is Node => c !== null && typeof c === 'object' && 'type' in (c as object))
const isCard = (n: Node) => n.type === 'Box' && n.props.position === 'absolute' && n.props.display === 'none'
const weight = (tree: unknown) => JSON.stringify(tree).length

beforeEach(() => {
    reset(options)
})

describe('drawPane: off and without a graph', () => {
    it('draws an empty keyed Box when the mod is off, and clears what the pane last drew', async () => {
        const { io, reads } = fakePort()
        mod.disabled = true
        mod.paneText = 'stale'
        mod.emptyShown = true
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)) as unknown as Node
        expect(tree).toMatchObject({ type: 'Box', key: 'off' })
        expect(nodesOf(tree)).toHaveLength(1)
        expect(mod.paneText).toBeNull()
        expect(mod.emptyShown).toBe(false)
        expect(reads).toEqual([])
    })

    it('says the graph is loading when no dashboard came yet, and marks the empty state shown', async () => {
        const { io } = fakePort({ dashboard: null, refresh: { fetchedAt: null, failed: false } })
        mod.paneText = 'stale'
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)) as unknown as Node
        expect(tree.key).toBe('empty')
        expect(mod.emptyShown).toBe(true)
        expect(mod.paneText).toBeNull()
        expect(textOf(tree)).toContain('Reading the graph')
        expect(textOf(tree)).not.toContain('allow root')
    })

    it('turns the spinner of a line under way at the motion\'s frame, and keeps the frames going while it shows', async () => {
        const { io, timers } = fakePort({ dashboard: null, refresh: { fetchedAt: null, failed: true, loading: true }, motion: { since: null, frame: 2 } })
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)) as unknown as Node
        expect(textOf(tree)).toContain(`${spinGlyph(2)} Reading the graph…`)
        expect(textOf(tree)).toContain('asking again')
        expect(textOf(tree)).not.toContain(BUSY)
        expect(mod.paneBusy).toBe(true)
        expect(timers).toHaveLength(1)
        // Drawn again while the frames run: no second timer.
        await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)
        expect(timers).toHaveLength(1)
    })

    it('starts no frames for a line that has spun past its minute', async () => {
        const { io, timers } = fakePort({ dashboard: null, refresh: { fetchedAt: null, failed: false, loading: true } })
        mod.paneBusy = true
        mod.busySince = -BUSY_SPIN_MS
        await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)
        expect(mod.paneBusy).toBe(true)
        expect(timers).toEqual([])
    })

    it('says a failed read with nothing on its way as a failure, with no spinner and no frames', async () => {
        const { io, timers } = fakePort({ dashboard: null, refresh: { fetchedAt: null, failed: true } })
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)) as unknown as Node
        expect(textOf(tree)).toContain('Could not read the graph')
        expect(mod.paneBusy).toBe(false)
        expect(timers).toEqual([])
    })

    it('offers to ask for a scan when the project was never scanned', async () => {
        const { io } = fakePort({ dashboard: { status: 'unscanned' } })
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)) as unknown as Node
        expect(tree.key).toBe('empty')
        expect(textOf(tree)).toContain('No architecture graph yet')
        expect(textOf(tree)).toContain('ask Claude to scan it')
    })

    it('adds the allow-root offer when the last brief was refused its root', async () => {
        const brief = { status: 'not-allowed', refused_root: '/elsewhere', path: '/elsewhere/x', roots_file: null } as unknown as TurnBrief
        const { io } = fakePort({ dashboard: null, brief, refresh: { fetchedAt: null, failed: false } })
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)) as unknown as Node
        expect(mod.emptyShown).toBe(true)
        const text = textOf(tree)
        expect(text).toContain('Reading the graph')
        expect(text).toContain('/elsewhere is not an allowed root')
        expect(nodesOf(tree).some(n => n.type === 'Button' && n.props.label === 'allow root')).toBe(true)
    })

    it('treats a dashboard that answered with an error as an unreadable graph', async () => {
        const { io } = fakePort({ dashboard: { status: 'error' } })
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)) as unknown as Node
        expect(tree.key).toBe('empty')
        expect(mod.emptyShown).toBe(true)
        expect(textOf(tree)).toContain('Could not read the graph')
    })
})

describe('drawPane: a graph', () => {
    it('keys the pane, keeps the header status text, and pins no bar when everything fits', async () => {
        const { io } = fakePort()
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 100 }, act)) as unknown as Node
        expect(tree).toMatchObject({ type: 'Box', key: 'pane' })
        const status = (await currentInput(io, true))!.status.text
        expect(status.length).toBeGreaterThan(0)
        expect(mod.paneText).toBe(status)
        expect(mod.emptyShown).toBe(false)
        expect(nodesOf(tree).some(n => n.key === 'bar')).toBe(false)
        expect(textOf(tree)).toContain('Component0')
    })

    it('keys the detail when a component is inspected', async () => {
        const { io } = fakePort({ view: view({ inspect: { name: 'App\\Component0', label: 'Component0' } }) })
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 100 }, act)) as unknown as Node
        expect(tree.key).toBe('detail')
    })

    it('draws a pane with no columns as one column wide, without throwing', async () => {
        for (const bodyColumns of [0, -12]) {
            const { io } = fakePort()
            const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns }, act)) as unknown as Node
            const rows = nodesOf(tree).filter(n => n.type === 'Box' && n.props.flexDirection === 'row')
            expect(rows.length, `${bodyColumns}`).toBeGreaterThan(0)
            expect(new Set(rows.map(r => r.props.width)), `${bodyColumns}`).toEqual(new Set([1]))
        }
    })

    it('records whether the pane is wide enough for the detail beside the tab', async () => {
        const { io } = fakePort()
        await drawPane(io, elements as never, 'terminal', { bodyColumns: 160 }, act)
        expect(mod.paneWide).toBe(true)
        await drawPane(io, elements as never, 'terminal', { bodyColumns: 60 }, act)
        expect(mod.paneWide).toBe(false)
        // Set before the empty state too: the lookup it needs runs from the next press.
        await drawPane(fakePort({ dashboard: null }).io, elements as never, 'terminal', { bodyColumns: 200 }, act)
        expect(mod.paneWide).toBe(true)
    })

    it('pins the footer bar over a window shorter than the body, never above the top, however far back it is scrolled', async () => {
        const { io } = fakePort({ dashboard: dashboard({ hubs: hubs(120) }) })
        for (const offset of [-5, 0, 7]) {
            const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 100, scroll: { offset, bodyRows: 10 } }, act)) as unknown as Node
            expect(tree.key).toBe('pane')
            const bar = children(tree).find(n => n.key === 'bar')
            expect(bar, `${offset}`).toBeDefined()
            expect(bar!.props).toMatchObject({ position: 'absolute', left: 0, width: 100 })
            expect(bar!.props.top as number, `${offset}`).toBeGreaterThanOrEqual(0)
            expect(children(bar!).length).toBeGreaterThan(0)
        }
        // A negative offset is drawn as the top: the same tree as offset 0.
        const negative = await drawPane(io, elements as never, 'terminal', { bodyColumns: 100, scroll: { offset: -5, bodyRows: 10 } }, act)
        const top = await drawPane(io, elements as never, 'terminal', { bodyColumns: 100, scroll: { offset: 0, bodyRows: 10 } }, act)
        expect(JSON.stringify(negative)).toBe(JSON.stringify(top))
    })
})

describe('drawPane: surfaces', () => {
    it('hangs no hover cards on mobile, and draws no text field there', async () => {
        const { io } = fakePort()
        const tree = await drawPane(io, mobileElements as never, 'mobile', { bodyColumns: 100 }, act)
        const nodes = nodesOf(tree)
        expect(nodes.filter(isCard)).toEqual([])
        expect(nodes.filter(n => n.type === 'Markdown')).toEqual([])
        expect(nodes.filter(n => n.type === 'Input')).toEqual([])
        expect(textOf(tree)).toContain('Component0')
    })

    it("hangs at most eight hover cards after the rows where the surface has a pointer, and makes the places open their files", async () => {
        const { io } = fakePort()
        const tree = (await drawPane(io, elements as never, 'desktop', { bodyColumns: 100 }, act)) as unknown as Node
        const top = children(tree)
        const cards = top.filter(isCard)
        expect(cards.length).toBeGreaterThan(0)
        expect(cards.length).toBeLessThanOrEqual(8)
        // The cards come after every row.
        const firstCard = top.findIndex(isCard)
        expect(top.slice(firstCard).every(isCard)).toBe(true)
        for (const card of cards) expect((card.props.hover as { scope: string }).scope).toMatch(/^knossos:/)
        expect(nodesOf(tree).some(n => n.type === 'Button' && JSON.stringify(n.props).includes('.php'))).toBe(true)
        expect(nodesOf(tree).some(n => n.type === 'Markdown')).toBe(false)
    })

    it('draws the Boundaries heat map on the terminal as one Raster instead of a Box per row', async () => {
        const boundary_matrix: BoundaryMatrix = {
            boundaries: ['tests', 'core', 'worker'],
            members: [10, 20, 5],
            boundaries_truncated: false,
            cells: [
                [3, 9, 0],
                [0, 4, 1],
                [0, 0, 2],
            ],
            forbidden: [[1, 0]],
            edges: 19,
            truncated: false,
            truncation_reasons: [],
        }
        const { io } = fakePort({ dashboard: dashboard({ boundary_matrix }), view: view({ tab: 'boundaries' }) })
        const terminal = nodesOf(await drawPane(io, elements as never, 'terminal', { bodyColumns: 100 }, act))
        const rasters = terminal.filter(n => n.type === 'Raster')
        expect(rasters.map(r => r.key)).toEqual(['raster-heat'])
        expect(terminal.some(n => n.key !== null && n.key.startsWith('heat-'))).toBe(false)
        // Elsewhere the same rows are text.
        const desktop = nodesOf(await drawPane(io, elements as never, 'desktop', { bodyColumns: 100 }, act))
        expect(desktop.filter(n => n.type === 'Raster')).toEqual([])
        expect(desktop.some(n => n.key === 'heat-head')).toBe(true)
    })
})

/**
 * The widest layouts the person keeps the pane at: a long list at 200 and
 * 240 columns, 40 and 60 rows tall, draws whole (its hover cards kept, so
 * no budget step ran) and leaves the room the livelier look will spend.
 */
/** How much of the budget a wide tab may use as drawn whole: the rest is room for the livelier look. */
const WIDE_HEADROOM = Math.floor(TEXT_BUDGET * 0.85)

describe('drawPane: wide tabs fit', () => {
    for (const tab of ['hubs', 'overview'] as const) {
        for (const bodyColumns of [200, 240]) {
            for (const bodyRows of [40, 60]) {
                it(`draws ${tab} at ${bodyColumns}x${bodyRows} whole, within the headroom`, async () => {
                    const { io, logs } = fakePort({ dashboard: dashboard({ hubs: hubs(300) }), view: view({ tab }) })
                    mod.paneWide = true
                    const tree = await drawPane(io, elements as never, 'terminal', { bodyColumns, scroll: { offset: 0, bodyRows } }, act)
                    expect(drawnText(tree)).toBeLessThanOrEqual(WIDE_HEADROOM)
                    // Hubs hangs hover cards: they go first when a tree is over budget, so keeping them shows no step ran.
                    if (tab === 'hubs') expect(nodesOf(tree).filter(isCard).length).toBeGreaterThan(0)
                    expect(textOf(tree)).not.toContain('Too large to draw here')
                    expect(logs).toEqual([])
                })
            }
        }
    }
})

/**
 * The engine draws a pane's first 100,000 characters of text and cuts the
 * rest; it refuses no tree for its size. So the pane spends that, not the
 * length of its tree serialized: a tall list whose props are heavy but whose
 * text fits is drawn whole.
 */
describe('drawPane: the budget is the text the engine draws', () => {
    it('counts the text of a tree in the engine shape, its children beside its props, as in the stub shape', () => {
        const engine = { type: 'Box', props: { width: 10 }, children: [{ type: 'Text', props: { color: 'text' }, children: ['abc', { type: 'Text', children: ['de'] }] }, { type: 'Button', props: { key: 'k', label: 'fgh' } }] }
        const stub = { type: 'Box', props: { width: 10, children: [{ type: 'Text', props: { children: ['abc', { type: 'Text', props: { children: 'de' } }] } }, { type: 'Button', props: { label: 'fgh' } }] } }
        expect(drawnText(engine)).toBe(8)
        expect(drawnText(stub)).toBe(8)
        expect(drawnText({ type: 'Code', props: { source: '+x\n', path: 'a.ts' } })).toBe(3)
    })

    it('draws a tall list whole when its text fits, however heavy its tree', async () => {
        const { io, logs } = fakePort({ dashboard: dashboard({ hubs: hubs(300) }), view: view({ tab: 'hubs' }) })
        const tree = await drawPane(io, elements as never, 'terminal', { bodyColumns: 120, scroll: { offset: 0, bodyRows: 340 } }, act)
        expect(weight(tree)).toBeGreaterThan(100_000)
        expect(drawnText(tree)).toBeLessThanOrEqual(TEXT_BUDGET)
        // Drawn whole: no budget step ran, so the hover cards, the first thing a step drops, are all there.
        expect(nodesOf(tree).filter(isCard).length).toBeGreaterThan(0)
        expect(logs).toEqual([])
    })
})

/** Hubs whose names run `extra` cells past their own: on a pane wide enough to show them, each row draws that much text more. */
const longHubs = (n: number, extra: number) => hubs(n).map(h => ({ ...h, name: `${h.name}${'x'.repeat(extra)}`, canonical_name: `${h.canonical_name}${'x'.repeat(extra)}` }))

describe('drawPane: the tree budget', () => {
    it("drops the hover cards and most places' presses before it gives up rows", async () => {
        // The rows a 40-row window lists when nothing weighs on it.
        const listed = new Set(textOf(await drawPane(fakePort({ dashboard: dashboard({ hubs: hubs(300) }) }).io, elements as never, 'desktop', { bodyColumns: 120, scroll: { offset: 0, bodyRows: 40 } }, act)).match(/Component\d+/g)).size
        const at = async (bodyColumns: number) => drawPane(fakePort({ dashboard: dashboard({ hubs: hubs(300) }) }).io, elements as never, 'desktop', { bodyColumns, scroll: { offset: 0, bodyRows: 40 } }, act)
        // The narrowest pane whose text first passes the budget, found by halving: whatever its rows weigh, the first step
        // the budget takes there is the one this test is about.
        let fits = 120
        let over = 8000
        expect(nodesOf(await at(fits)).filter(isCard).length).toBeGreaterThan(0)
        expect(nodesOf(await at(over)).filter(isCard)).toEqual([])
        while (over - fits > 25) {
            const mid = Math.floor((fits + over) / 2)
            if (nodesOf(await at(mid)).filter(isCard).length > 0) fits = mid
            else over = mid
        }
        const tree = await at(over)
        expect(drawnText(tree)).toBeLessThanOrEqual(TEXT_BUDGET)
        expect(nodesOf(tree).filter(isCard)).toEqual([])
        // Only the marked row's place still opens its file, and every row is still listed.
        const places = nodesOf(tree).filter(n => n.type === 'Button' && /\.php(:\d+)?$/.test(String(n.props.label ?? '')))
        expect(places.length).toBeLessThanOrEqual(1)
        expect(new Set(textOf(tree).match(/Component\d+/g)).size).toBe(listed)
        expect(textOf(tree)).not.toContain('Too large to draw here')
    })

    it('gives the lists fewer rows when even the lean tree is past the budget', async () => {
        const light = await drawPane(fakePort({ dashboard: dashboard({ hubs: hubs(300) }) }).io, elements as never, 'desktop', { bodyColumns: 120, scroll: { offset: 0, bodyRows: 40 } }, act)
        const listed = new Set(textOf(light).match(/Component\d+/g)).size
        const { io, logs } = fakePort({ dashboard: dashboard({ hubs: longHubs(300, 2200) }) })
        const tree = await drawPane(io, elements as never, 'desktop', { bodyColumns: 2800, scroll: { offset: 0, bodyRows: 40 } }, act)
        expect(drawnText(tree)).toBeLessThanOrEqual(TEXT_BUDGET)
        const shown = new Set(textOf(tree).match(/Component\d+/g))
        expect(shown.size).toBeGreaterThan(0)
        expect(shown.size).toBeLessThan(listed)
        expect(textOf(tree)).not.toContain('Too large to draw here')
        expect(logs).toEqual([])
    })

    it('draws one line and logs once to debug when even the fewest rows are too heavy', async () => {
        // Names eight thousand cells long on a pane 9,000 wide (the engine's Box tops out at 10,000): past the budget at any row count.
        const { io, logs } = fakePort({ dashboard: dashboard({ hubs: longHubs(300, 8000) }) })
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 9_000, scroll: { offset: 0, bodyRows: 40 } }, act)) as unknown as Node
        expect(tree.key).toBe('pane')
        expect(drawnText(tree)).toBeLessThanOrEqual(TEXT_BUDGET)
        expect(children(tree).map(r => r.key)).toEqual(['too-large'])
        expect(textOf(tree)).toContain('Too large to draw here')
        expect(logs).toHaveLength(1)
        expect(logs[0]!.options).toEqual({ to: 'debug' })
        expect(logs[0]!.text).toContain('too large to draw')
        expect(mod.tooLargeLogged).toBe(true)
        // The header status is still what the age tick compares against.
        expect(mod.paneText).toBe((await currentInput(io, true))!.status.text)

        await drawPane(io, elements as never, 'terminal', { bodyColumns: 9_000, scroll: { offset: 0, bodyRows: 40 } }, act)
        expect(logs).toHaveLength(1)
    })

    // A sweep over every width and height: seconds under coverage, so it gets its own bound rather than the default 5 s.
    it('never hands the engine more text than the 100,000 characters it draws', { timeout: 30_000 }, async () => {
        const many = dashboard({ hubs: hubs(400) })
        const cases: [string, Record<string, unknown>, number, number][] = [
            ['desktop', { dashboard: many }, 120, 200],
            ['terminal', { dashboard: many }, 1000, 40],
            ['mobile', { dashboard: many }, 3000, 60],
            ['desktop', { dashboard: many, view: view({ filtering: true, filter: 'x'.repeat(5000) }) }, 120, 40],
            ['terminal', { dashboard: many, view: view({ inspect: { name: 'App\\Component1', label: 'Component1' } }) }, 4000, 300],
            ['terminal', { dashboard: null }, 100_000, 40],
        ]
        for (const [surface, cells, bodyColumns, bodyRows] of cases) {
            const { io } = fakePort(cells)
            const ui = surface === 'mobile' ? mobileElements : elements
            const tree = await drawPane(io, ui as never, surface, { bodyColumns, scroll: { offset: 0, bodyRows } }, act)
            expect(drawnText(tree), `${surface} ${bodyColumns}x${bodyRows}`).toBeLessThanOrEqual(100_000)
        }
    })
})
