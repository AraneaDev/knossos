import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AllowState, KnossosView } from '../../types'
import { SORTS } from '../lib/pane-list'
import { handlersOf, PRESSES, pressOf } from './actions'
import { request } from './loaders'
import type { Port } from './port'
import { fieldKey, mod, PANE, reset } from './state'
import { requestRescan } from './watcher'

// The loaders and the rescan reach the wrapper through timers of their own: here only that they are asked for, and when, matters.
vi.mock('./loaders', async importOriginal => ({ ...(await importOriginal<typeof import('./loaders')>()), request: vi.fn(async () => undefined) }))
vi.mock('./watcher', async importOriginal => ({ ...(await importOriginal<typeof import('./watcher')>()), requestRescan: vi.fn(() => undefined) }))

const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }
const GREETER = { name: 'App\\Greeter', label: 'Greeter' }
const VIEW: KnossosView = { inspect: null, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' }

/**
 * A port holding the view, the note and the allow state as plain values, its
 * timers run only when the test runs them and its focus calls recorded. Each
 * view change is written to `log`, so the order against the loaders shows.
 */
function viewPort(view: Partial<KnossosView> = {}, extra: { note?: unknown; allow?: AllowState } = {}) {
    const log: string[] = []
    const timers: (() => void)[] = []
    const focused: unknown[] = []
    const values = new Map<string, unknown>(Object.entries({ view: { ...VIEW, ...view }, note: extra.note ?? null, allow: extra.allow ?? { phase: 'idle', root: null, reason: null } }))
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => values.get(key),
                update: async (change: (v: unknown) => unknown) => {
                    values.set(key, change(values.get(key)))
                    log.push(`update:${key}`)
                },
            }),
        },
    )
    const io = {
        clock: { after: (_ms: number, run: () => void) => (timers.push(run), { cancel: () => undefined }) },
        ui: { focus: async (args: unknown) => (focused.push(args), {}), scroll: async () => ({}) },
        state,
    } as unknown as Port
    const press = async (id: string) => {
        const found = pressOf(id)
        if (found !== null) await PRESSES.get(found.key)!(io, found.rest, undefined, id)
    }
    return { io, log, timers, focused, press, view: () => values.get('view') as KnossosView, note: () => values.get('note'), allow: () => values.get('allow') as AllowState }
}

beforeEach(() => {
    reset(options)
    vi.mocked(request).mockReset()
    vi.mocked(request).mockImplementation(async () => undefined)
    vi.mocked(requestRescan).mockClear()
})

describe('a press on a tab', () => {
    it('switches to a known tab and starts it afresh', async () => {
        const port = viewPort({ tab: 'hubs', selected: 7, filtering: true, finding: true, drift: true, target: 'App', degree: { from: 2, to: null } })
        await port.press('tab:cycles')
        expect(port.view()).toMatchObject({ tab: 'cycles', selected: 0, filtering: false, finding: false, drift: false, target: undefined, degree: null })
    })

    it('leaves the view as it was for a tab the pane does not have', async () => {
        const before = { tab: 'hubs' as const, selected: 7, filtering: true, drift: true }
        const port = viewPort(before)
        await port.press('tab:bogus')
        await port.press('tab:')
        expect(port.view()).toEqual({ ...VIEW, ...before })
        expect(port.log).toEqual([])
    })

    it('reads the notes again on entering the Notes tab, notes the model wrote meanwhile included', async () => {
        const port = viewPort({ tab: 'hubs' })
        const before = mod.notes.version
        await port.press('tab:notes')
        expect(mod.notes.version).toBe(before + 1)
        await port.press('tab:hubs')
        expect(mod.notes.version).toBe(before + 1)
    })

    it('switches the same way from the hidden hotkey twin', async () => {
        const port = viewPort({ selected: 3 })
        await port.press('tabkey:churn')
        expect(port.view()).toMatchObject({ tab: 'churn', selected: 0 })
    })
})

describe('the Notes tab', () => {
    it('cycles what it shows on s, the marker back at the top, and back to every note after the flagged', async () => {
        const port = viewPort({ tab: 'notes', selected: 4 })
        await port.press('notes-filter')
        expect(port.view()).toMatchObject({ notesFilter: 'yours', selected: 0 })
        for (const next of ['model', 'stale', 'missing', 'flagged', 'all']) {
            await port.press('notes-filter')
            expect(port.view().notesFilter).toBe(next)
        }
    })

    it('opens its own field on n, never the Hubs filter, and clears both on x', async () => {
        const port = viewPort({ tab: 'notes', filter: 'hub text' })
        await port.press('notes-narrow')
        expect(port.view()).toMatchObject({ tab: 'notes', filtering: true, filter: 'hub text' })
        await port.press('notes-filter')
        await port.press('notes-clear')
        expect(port.view()).toMatchObject({ notesFilter: 'all', notesText: '', filtering: false, filter: 'hub text' })
    })
})

describe('drift', () => {
    it('turns on and off again, the marker back at the top each time', async () => {
        const port = viewPort({ selected: 5 })
        await port.press('drift')
        expect(port.view()).toMatchObject({ drift: true, selected: 0 })
        await port.io.state.view.update(v => ({ ...v, selected: 4 }))
        await port.press('drift')
        expect(port.view()).toMatchObject({ drift: false, selected: 0 })
    })

    it("does the same from the drift line's second button", async () => {
        const port = viewPort()
        await port.press('drifted')
        expect(port.view().drift).toBe(true)
        await port.press('drifted')
        expect(port.view().drift).toBe(false)
    })
})

describe('picking a route', () => {
    const ROUTE = { from: GREETER, to: { name: 'App\\Mailer', label: 'Mailer' }, index: 0 }

    it('moves to the path pressed when a route is shown', async () => {
        const port = viewPort({ route: ROUTE, selected: 3 } as Partial<KnossosView>)
        await port.press('route-pick:2')
        expect(port.view()).toMatchObject({ route: { ...ROUTE, index: 2 }, selected: 0 })
    })

    it('changes nothing without a route', async () => {
        for (const route of [null, undefined]) {
            const port = viewPort({ route, selected: 3 } as Partial<KnossosView>)
            await port.press('route-pick:2')
            expect(port.view()).toEqual({ ...VIEW, route, selected: 3 })
        }
    })

    it.each([
        ['-3', 0],
        ['x', 0],
        ['', 0],
    ])('reads route-pick:%s as path %i', async (rest, index) => {
        const port = viewPort({ route: { ...ROUTE, index: 5 } } as Partial<KnossosView>)
        await port.press(`route-pick:${rest}`)
        expect((port.view() as KnossosView & { route: { index: number } }).route.index).toBe(index)
    })
})

describe('the Cycles moves', () => {
    it.each([
        ['next:4', 4],
        ['prev:-1', 0],
        ['mark:abc', 0],
        ['mark:2', 2],
    ])('%s puts the marker on row %i', async (id, selected) => {
        const port = viewPort({ selected: 9 })
        await port.press(id)
        expect(port.view().selected).toBe(selected)
    })
})

describe('back', () => {
    it('leaves a route for the detail it was picked from', async () => {
        const port = viewPort({ inspect: GREETER, route: { from: GREETER, to: GREETER, index: 1 }, selected: 4, opened: 6 } as Partial<KnossosView>)
        await port.press('back')
        expect(port.view()).toMatchObject({ route: null, inspect: GREETER, selected: 0 })
    })

    it('closes the detail, the marker back on the row it was opened from', async () => {
        const port = viewPort({ inspect: GREETER, route: null, selected: 0, opened: 6 } as Partial<KnossosView>)
        await port.press('back')
        expect(port.view()).toMatchObject({ inspect: null, selected: 6 })
    })

    it('closes the detail onto the top when nothing says where it was opened from', async () => {
        const port = viewPort({ inspect: GREETER, selected: 3 })
        await port.press('back')
        expect(port.view()).toMatchObject({ inspect: null, selected: 0 })
    })
})

describe('the plain view switches', () => {
    it('keys shows the keys and hides them again', async () => {
        const port = viewPort({ showKeys: false })
        await port.press('keys')
        expect(port.view().showKeys).toBe(true)
        await port.press('keys')
        expect(port.view().showKeys).toBe(false)
    })

    it('any other press closes the keys first, then does what it does', async () => {
        // Through the pane's own press handler, as a click or a hotkey arrives.
        const settle = () => new Promise(resolve => setTimeout(resolve, 0))
        const port = viewPort({ showKeys: true, tab: 'overview' })
        handlersOf(port.io).press('tab:hubs')
        await settle()
        expect(port.view()).toMatchObject({ showKeys: false, tab: 'hubs' })
        // The keys' own presses leave it to them, and an id no press answers changes nothing.
        const kept = viewPort({ showKeys: false })
        handlersOf(kept.io).press('keys')
        await settle()
        expect(kept.view().showKeys).toBe(true)
        handlersOf(kept.io).press('no-such-press')
        await settle()
        expect(kept.view().showKeys).toBe(true)
    })

    it('keys-close hides the keys, and leaves them hidden when they were', async () => {
        const port = viewPort({ showKeys: true })
        await port.press('keys-close')
        expect(port.view().showKeys).toBe(false)
        await port.press('keys-close')
        expect(port.view().showKeys).toBe(false)
    })

    it('clear empties the filter and the degree, closing the field', async () => {
        const port = viewPort({ filter: 'Greet', filtering: true, degree: { from: 1, to: 4 }, selected: 3 })
        await port.press('clear')
        expect(port.view()).toMatchObject({ filter: '', filtering: false, degree: null, selected: 0 })
    })

    it('sort walks every order and wraps from the last to the first', async () => {
        const port = viewPort({ sort: SORTS[0]!, selected: 2 })
        const seen: string[] = []
        for (let i = 0; i < SORTS.length; i++) {
            await port.press('sort')
            seen.push(port.view().sort)
            expect(port.view().selected).toBe(0)
        }
        expect(seen).toEqual([...SORTS.slice(1), SORTS[0]])
    })

    it('filter opens the hubs filter and moves the focus into it once drawn', async () => {
        const port = viewPort({ tab: 'cycles', filtering: false })
        await port.press('filter')
        expect(port.view()).toMatchObject({ tab: 'hubs', filtering: true })
        expect(port.focused).toEqual([])
        expect(port.timers).toHaveLength(1)
        port.timers[0]!()
        // The field is scrolled into view first, then focused.
        for (let i = 0; i < 5; i++) await Promise.resolve()
        expect(port.focused).toEqual([{ requestId: PANE, key: fieldKey('filter') }])
    })

    it('rescan asks the watcher for a rescan through the same port', async () => {
        const port = viewPort()
        await port.press('rescan')
        expect(vi.mocked(requestRescan)).toHaveBeenCalledTimes(1)
        expect(vi.mocked(requestRescan)).toHaveBeenCalledWith(port.io)
    })

    it('scan, the empty pane\'s offer outside Claude Code, runs that same rescan and submits no prompt', async () => {
        const port = viewPort()
        // The port has no prompt: a press that tried to send one would throw here.
        await port.press('scan')
        expect(vi.mocked(requestRescan)).toHaveBeenCalledTimes(1)
        expect(vi.mocked(requestRescan)).toHaveBeenCalledWith(port.io)
    })
})

describe('declining', () => {
    it('note-no drops the note on show', async () => {
        const port = viewPort({}, { note: { component: GREETER.name, phase: 'typing', value: 'x' } })
        await port.press('note-no')
        expect(port.note()).toBeNull()
    })

    it('allow-no leaves a run under way alone', async () => {
        const running: AllowState = { phase: 'running', root: '/work/refused', reason: null }
        const port = viewPort({}, { allow: running })
        await port.press('allow-no')
        expect(port.allow()).toEqual(running)
    })

    it('allow-no answers the question by going idle', async () => {
        const port = viewPort({}, { allow: { phase: 'confirming', root: '/work/refused', reason: null } })
        await port.press('allow-no')
        expect(port.allow()).toEqual({ phase: 'idle', root: null, reason: null })
    })
})

describe("a press through the pane's handlers", () => {
    const settle = async () => {
        for (let i = 0; i < 10; i++) await new Promise(resolve => setTimeout(resolve, 0))
    }

    it('asks for the couplings, branch, peek and churn once the press has changed the view', async () => {
        const port = viewPort({ selected: 9 })
        vi.mocked(request).mockImplementation(async (_io, key) => void port.log.push(`request:${key}`))
        handlersOf(port.io).press('next:2')
        await settle()
        expect(port.view().selected).toBe(2)
        expect(port.log).toEqual(['update:view', 'request:couplings', 'request:branch', 'request:peek', 'request:churn', 'request:notes'])
        for (const call of vi.mocked(request).mock.calls) expect(call[0]).toBe(port.io)
    })

    it('still asks for them after a press that does nothing', async () => {
        const port = viewPort()
        vi.mocked(request).mockImplementation(async (_io, key) => void port.log.push(`request:${key}`))
        handlersOf(port.io).press('nope')
        await settle()
        expect(port.view()).toEqual(VIEW)
        expect(port.log).toEqual(['request:couplings', 'request:branch', 'request:peek', 'request:churn', 'request:notes'])
    })
})
