import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Dashboard, Inspected, KnossosView } from '../../types'
import { NO_CHANGES } from '../lib/layout'
import { LIVE_OFF } from '../lib/live'
import type { Port } from './port'
import { drawPane } from './render'
import type { Handlers } from './render'
import { fieldKey, reset } from './state'

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

/** A port over plain cells: what drawing reads. */
function fakePort(cells: Record<string, unknown> = {}) {
    const values: Record<string, unknown> = {
        brief: null,
        dashboard: dashboard(),
        view: view(),
        detail: null,
        refresh: { fetchedAt: 0, failed: false },
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
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => values[key],
                update: async (change: (v: unknown) => unknown) => (values[key] = change(values[key])),
            }),
        },
    )
    return { state, clock: { now: async () => 0 }, ui: { log: () => undefined } } as unknown as Port
}

/** Handlers that record each call, so a test can press what the pane drew and see what it asked for. */
function spies() {
    return { press: vi.fn(), type: vi.fn(), submit: vi.fn(), link: vi.fn() } satisfies Handlers
}

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

const children = (n: Node): Node[] =>
    (Array.isArray(n.props.children) ? (n.props.children as unknown[]).flat(Infinity) : [n.props.children]).filter((c): c is Node => c !== null && typeof c === 'object' && 'type' in (c as object))

/** An element's key: a key spread among the props (a tinted Box's) arrives with them. */
const keyOf = (n: Node): unknown => n.key ?? n.props.key

/** The words one element shows, nested Texts and bare strings alike. */
function wordsOf(n: unknown): string {
    if (typeof n === 'string') return n
    if (Array.isArray(n)) return n.map(wordsOf).join('')
    if (n === null || typeof n !== 'object' || !('props' in n)) return ''
    return wordsOf((n as Node).props.children)
}

/** The rows the pane drew, each its own keyed Box. */
const rowOf = (tree: unknown, key: string): Node => {
    const row = nodesOf(tree).find(n => n.type === 'Box' && n.key === key)
    expect(row, key).toBeDefined()
    return row!
}

const REV = '0123456789abcdef0123456789abcdef01234567'
const finder = (query: string) => ({ view: view({ finding: true }), search: { query, for: query, phase: 'idle', answer: { status: 'ok', results: [], truncated: false } } })

beforeEach(() => {
    reset(options)
})

describe('drawPane: a field', () => {
    it("draws the open finder's field as an Input keyed by its id, whose keystrokes and Enter reach the handlers with that id", async () => {
        const act = spies()
        const tree = await drawPane(fakePort(finder('ab')), elements as never, 'terminal', { bodyColumns: 80 }, act)
        const inputs = nodesOf(tree).filter(n => n.type === 'Input')
        expect(inputs.map(n => n.key)).toEqual([fieldKey('find')])
        const input = inputs[0]!
        expect(input.props).toMatchObject({ value: 'ab', autoFocus: true })
        ;(input.props.onInput as (v: string) => void)('x')
        expect(act.type).toHaveBeenCalledExactlyOnceWith('find', 'x')
        expect(act.submit).not.toHaveBeenCalled()
        ;(input.props.onSubmit as (v: string) => void)('abc')
        expect(act.submit).toHaveBeenCalledExactlyOnceWith('find', 'abc')
        expect(act.type).toHaveBeenCalledTimes(1)
        expect(act.press).not.toHaveBeenCalled()
    })

    it('draws the filter as plain text on mobile, which has no text field', async () => {
        const io = fakePort({ view: view({ filtering: true, filter: 'Comp' }) })
        const tree = await drawPane(io, mobileElements as never, 'mobile', { bodyColumns: 100 }, spies())
        expect(nodesOf(tree).filter(n => n.type === 'Input')).toEqual([])
        const row = rowOf(tree, 'filter-row')
        const texts = children(row).filter(n => n.type === 'Text')
        expect(texts.map(wordsOf)).toContain('Comp')
        expect(children(row).every(n => n.type === 'Text')).toBe(true)
        // The same view where there is a field draws one.
        const desktop = await drawPane(fakePort({ view: view({ filtering: true, filter: 'Comp' }) }), elements as never, 'desktop', { bodyColumns: 100 }, spies())
        expect(
            nodesOf(rowOf(desktop, 'filter-row'))
                .filter(n => n.type === 'Input')
                .map(n => n.key),
        ).toEqual([fieldKey('filter')])
    })

    for (const columns of [80, 100, 140]) {
        it(`gives the field the blank cells after it and stops at the card's frame (${columns} columns)`, async () => {
            const tree = await drawPane(fakePort(finder('ab')), elements as never, 'terminal', { bodyColumns: columns }, spies())
            const row = rowOf(tree, 'find-row')
            const drawn = children(row)
            const at = drawn.findIndex(n => n.type === 'Box' && children(n).some(c => c.type === 'Input'))
            expect(at).toBeGreaterThan(0)
            const cellsOf = (ns: Node[]) => ns.reduce((n, t) => n + [...wordsOf(t)].length, 0)
            const before = cellsOf(drawn.slice(0, at))
            const after = cellsOf(drawn.slice(at + 1))
            // The frame's closing edge follows the field: the blanks laid out between them are the field's.
            expect(wordsOf(drawn[drawn.length - 1]!).trim()).toBe('│')
            // The blanks are absorbed, not drawn: the frame is the very next element after the field.
            expect(at).toBe(drawn.length - 2)
            expect(after).toBe(1)
            expect(drawn[at]!.props.width).toBe(Math.max(1, columns - before - after))
            // The field is wider than its own value ('ab'): it took the blank cells.
            expect(drawn[at]!.props.width as number).toBeGreaterThan(2)
            expect(before + (drawn[at]!.props.width as number) + after).toBe(columns)
            expect(drawn[at]!.props.flexShrink).toBe(0)
        })
    }

    it('never gives a field fewer than one cell, even when its label fills the row', async () => {
        // An empty query at nine columns: the label `   find: ` takes all nine, and the field still gets one cell.
        const tree = await drawPane(fakePort(finder('')), elements as never, 'terminal', { bodyColumns: 9 }, spies())
        const drawn = children(rowOf(tree, 'find-row'))
        expect(wordsOf(drawn[0]!)).toBe('   find: ')
        const boxes = drawn.filter(n => n.type === 'Box' && children(n).some(c => c.type === 'Input'))
        expect(boxes).toHaveLength(1)
        expect(boxes[0]!.props.width).toBe(1)
        // A cell wider, the field takes that cell and no more.
        const wider = children(rowOf(await drawPane(fakePort(finder('')), elements as never, 'terminal', { bodyColumns: 11 }, spies()), 'find-row'))
        expect(wider.find(n => n.type === 'Box')!.props.width).toBe(2)
    })

    it('leaves the width of a field off the terminal to the surface', async () => {
        const tree = await drawPane(fakePort(finder('ab')), elements as never, 'desktop', { bodyColumns: 80 }, spies())
        const box = nodesOf(tree).find(n => n.type === 'Box' && children(n).some(c => c.type === 'Input'))!
        expect(box.props).not.toHaveProperty('width')
    })
})

describe('drawPane: presses', () => {
    it("draws each tab's hotkey twin out of sight, a Button whose press names the tab and the surface it came from", async () => {
        const act = spies()
        const tree = await drawPane(fakePort(), elements as never, 'terminal', { bodyColumns: 40 }, act)
        const hidden = nodesOf(tree).filter(n => n.type === 'Box' && n.props.display === 'none' && typeof n.key === 'string' && n.key.startsWith('tabkey:'))
        expect(hidden.length).toBeGreaterThan(0)
        const twin = hidden.find(n => n.key === 'tabkey:hubs')!
        const [button] = children(twin)
        expect(button).toMatchObject({ type: 'Button', key: 'tabkey:hubs' })
        expect(typeof button!.props.hotkey).toBe('string')
        ;(button!.props.onPress as (p: { surface: string }) => void)({ surface: 'terminal' })
        expect(act.press).toHaveBeenCalledExactlyOnceWith('tabkey:hubs', 'terminal')
    })

    it("forwards the surface a row's Button was pressed on", async () => {
        const act = spies()
        const tree = await drawPane(fakePort(), elements as never, 'desktop', { bodyColumns: 100 }, act)
        const button = nodesOf(tree).find(n => n.type === 'Button' && n.key === 'row:1')!
        expect(button).toBeDefined()
        ;(button.props.onPress as (p: { surface: string }) => void)({ surface: 'desktop' })
        expect(act.press).toHaveBeenCalledExactlyOnceWith('row:1', 'desktop')
        ;(button.props.onPress as (p: { surface: string }) => void)({ surface: 'mobile' })
        expect(act.press).toHaveBeenLastCalledWith('row:1', 'mobile')
    })

    it("stands the marked row's Button in a Box of the row's tint", async () => {
        const tree = await drawPane(fakePort(), elements as never, 'terminal', { bodyColumns: 100 }, spies())
        const ground = nodesOf(tree).find(n => n.type === 'Box' && keyOf(n) === 'row:0-bg')
        expect(ground).toBeDefined()
        expect(typeof ground!.props.backgroundColor).toBe('string')
        expect(children(ground!).map(c => [c.type, c.key])).toEqual([['Button', 'row:0']])
        // An unmarked row's Button stands alone.
        expect(nodesOf(tree).some(n => keyOf(n) === 'row:1-bg')).toBe(false)
        expect(nodesOf(tree).some(n => n.type === 'Button' && n.key === 'row:1')).toBe(true)
    })

    it("sizes a tinted Button's Box on the terminal only: elsewhere the Button is the surface's own", async () => {
        const ground = (tree: unknown) => nodesOf(tree).find(n => n.type === 'Box' && keyOf(n) === 'row:0-bg')!
        const terminal = ground(await drawPane(fakePort(), elements as never, 'terminal', { bodyColumns: 100 }, spies()))
        expect(terminal.props.width).toBe([...'Component0'].length)
        const desktop = ground(await drawPane(fakePort(), elements as never, 'desktop', { bodyColumns: 100 }, spies()))
        expect(desktop.props).not.toHaveProperty('width')
        expect(desktop.props.backgroundColor).toBe(terminal.props.backgroundColor)
    })
})

describe('drawPane: links and code', () => {
    it("draws a row's file:line as a Markdown link whose plain click opens its href, from the surface it came from", async () => {
        const act = spies()
        const tree = await drawPane(fakePort(), elements as never, 'desktop', { bodyColumns: 100 }, act)
        const links = nodesOf(tree).filter(n => n.type === 'Markdown')
        expect(links.length).toBeGreaterThan(0)
        const link = links[0]!
        expect(link.props.text).toMatch(/\]\(file:/)
        ;(link.props.onLinkPress as (l: { href: string }, p: { surface: string }) => void)({ href: 'file:///work/app/src/C0.php#L3' }, { surface: 'desktop' })
        expect(act.link).toHaveBeenCalledExactlyOnceWith('file:///work/app/src/C0.php#L3', 'desktop')
        expect(act.press).not.toHaveBeenCalled()
    })

    it("draws a landed diff's hunk as a Code element in a column Box as wide as the pane", async () => {
        const shown: Inspected = { name: 'src/Router.php', label: 'src/Router.php', file: true, changed: true }
        const diff = '@@ -3,3 +3,4 @@ x\n {\n-a\n+b\n+c\n }\n'
        const fileDiff = {
            name: shown.name,
            rev: REV,
            snapshot: 's1',
            phase: 'done',
            diff: { status: 'ok', file: shown.name, kind: 'changed', from: null, to: null, binary: false, diff, lines: 6, truncated: false },
        }
        const io = fakePort({ view: view({ inspect: shown }), fileDiff, sessionRev: { status: 'ok', rev: REV } })
        const tree = await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, spies())
        const codes = nodesOf(tree).filter(n => n.type === 'Code')
        expect(codes).toHaveLength(1)
        expect(codes[0]!.props).toMatchObject({ format: 'diff', path: 'src/Router.php' })
        expect(codes[0]!.props.source).toMatch(/^@@ -3,3 \+3,4 @@/)
        const box = nodesOf(tree).find(n => n.type === 'Box' && children(n).includes(codes[0]!))!
        expect(box.props).toMatchObject({ flexDirection: 'column', width: 80, overflow: 'hidden' })
        expect(codes[0]!.key).toBe(`${box.key}-code`)
    })
})

describe('drawPane: plain text', () => {
    it('joins a run of plain segments into one Text, its blank cells bare and its styled pieces nested', async () => {
        const tree = await drawPane(fakePort(finder('ab')), elements as never, 'terminal', { bodyColumns: 80 }, spies())
        const [lead] = children(rowOf(tree, 'find-row'))
        // The frame, the space after it and the label: three segments, one Text.
        expect(lead!.type).toBe('Text')
        expect(wordsOf(lead)).toBe('│    find: ')
        const parts = lead!.props.children as unknown[]
        expect(parts.some(p => typeof p === 'string' && /^ +$/.test(p))).toBe(true)
    })
})

