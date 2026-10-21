import { beforeEach, describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView } from '../../types'
import { BASELINES_KEY } from '../lib/baseline'
import { FLASH_MS } from '../lib/flash'
import { NO_CHANGES } from '../lib/session-changes'
import { LIVE_OFF } from '../lib/live'
import { refreshDashboard } from './loaders'
import type { Port } from './port'
import { cyclesOf, mod, reset } from './state'

const ROOT = '/work/app'
const SESSION = 'session-1'
type Cycle = { size: number; members: string[] }
type Hub = { name: string; canonical_name: string; kind: string; in_degree: number; out_degree: number; cross_boundary_degree: number }

const dashboard = (snapshot: string | null, over: { cycles?: Cycle[]; hubs?: Hub[] } = {}): Dashboard =>
    ({
        status: 'ok',
        path: ROOT,
        project_root: ROOT,
        project_id: 'p1',
        snapshot_id: snapshot,
        freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
        hubs: over.hubs ?? [],
        hubs_truncated: false,
        hubs_truncation_reasons: [],
        hotspots: [],
        dead_code_candidates: 0,
        dead_code_truncated: false,
        cycles: { count: (over.cycles ?? []).length, truncated: false, truncation_reasons: [], largest: over.cycles ?? [] },
        trend: [],
        fan_in: [],
        fan_in_truncated: false,
    }) as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })
const hub = (inDegree: number): Hub => ({ name: 'Kernel', canonical_name: 'App\\Kernel', kind: 'class', in_degree: inDegree, out_degree: 1, cross_boundary_degree: 0 })
const cycle: Cycle = { size: 2, members: ['App\\A', 'App\\B'] }
const json = (value: unknown): string => JSON.stringify(value)

/**
 * A port over plain values: the cells the dashboard load reads and writes, a
 * clock that stands still until the test moves it and runs timers only when
 * told, a store and toasts that are recorded, and a wrapper that answers each
 * subcommand from `answers` (changeable between loads) and records every run.
 */
function fakePort(initial: Record<string, unknown>, answers: Record<string, string> = {}) {
    const blank = {
        brief: null,
        dashboard: null,
        view: view(),
        detail: null,
        refresh: { fetchedAt: null, failed: false },
        rescan: { phase: 'idle', reason: null },
        allow: { phase: 'idle', root: null, reason: null },
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
        startCycles: null,
    }
    const values = new Map<string, unknown>(Object.entries({ ...blank, ...initial }))
    const timers: { at: number; run: () => void; cancelled: boolean }[] = []
    const runs: string[][] = []
    const toasts: string[] = []
    const stored = new Map<string, unknown>()
    const clock = { now: 1_000 }
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => values.get(key),
                update: async (change: (v: unknown) => unknown) => {
                    values.set(key, change(values.get(key)))
                    return values.get(key)
                },
            }),
        },
    )
    const io = {
        clock: {
            now: async () => clock.now,
            after: (ms: number, run: () => void) => {
                const timer = { at: clock.now + ms, run, cancelled: false }
                timers.push(timer)
                return { cancel: () => void (timer.cancelled = true) }
            },
            every: () => ({ cancel: () => undefined }),
        },
        ui: { log: () => undefined, invalidate: () => undefined, toast: (text: string) => void toasts.push(text) },
        session: { root: async () => ROOT, id: async () => SESSION },
        store: {
            get: async (key: string) => stored.get(key),
            set: async (key: string, value: unknown) => void stored.set(key, value),
        },
        plugin: { root: '/plugin' },
        process: {
            run: async (argv: string[]) => {
                runs.push(argv)
                return { exitCode: 0, stdout: answers[argv[2]!] ?? '', stderr: '' }
            },
        },
        state,
    } as unknown as Port
    /** Runs every timer due now, and what they schedule in turn, then lets the reads they started settle. */
    const advance = async () => {
        for (let due = timers.find(t => !t.cancelled && t.at <= clock.now); due !== undefined; due = timers.find(t => !t.cancelled && t.at <= clock.now)) {
            due.cancelled = true
            due.run()
            await new Promise(resolve => setTimeout(resolve, 0))
        }
        await new Promise(resolve => setTimeout(resolve, 0))
    }
    const subs = () => runs.map(r => r[2])
    return { io, values, runs, subs, toasts, stored, clock, answers, advance }
}

const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }

beforeEach(() => reset(options))

describe('refreshing the dashboard', () => {
    it('does nothing while the mod is off', async () => {
        const { io, runs } = fakePort({}, { dashboard: json(dashboard('s1')) })
        mod.session.disabled = true
        await expect(refreshDashboard(io)).resolves.toBe(false)
        expect(runs).toEqual([])
    })

    it('says nothing was stored once the mod is off, even after a load that stored one', async () => {
        const { io, runs } = fakePort({}, { dashboard: json(dashboard('s1')) })
        await expect(refreshDashboard(io)).resolves.toBe(true)
        mod.session.disabled = true
        await expect(refreshDashboard(io)).resolves.toBe(false)
        expect(runs).toHaveLength(1)
    })

    it('stores an ok dashboard, marks the refresh fresh at the clock and follows its snapshot', async () => {
        const { io, values, runs, clock } = fakePort({ refresh: { fetchedAt: 5, failed: true } }, { dashboard: json(dashboard('s1')) })
        clock.now = 4_200
        await expect(refreshDashboard(io)).resolves.toBe(true)
        expect(values.get('dashboard')).toEqual(dashboard('s1'))
        expect(values.get('refresh')).toEqual({ fetchedAt: 4_200, failed: false })
        expect(mod.session.snapshot).toBe('s1')
        expect(mod.loads.dashboardTriedAt).toBe(4_200)
        expect(runs.filter(r => r[2] === 'dashboard')).toHaveLength(1)
    })

    it('says a read is on its way while it runs, and leaves no trace of that once it lands or fails', async () => {
        for (const answer of [json(dashboard('s1')), '']) {
            reset(options)
            const { io, values } = fakePort({ refresh: { fetchedAt: null, failed: true } }, { dashboard: answer })
            const seen: unknown[] = []
            const run = io.process.run
            io.process.run = (async (argv: string[], opts: never) => {
                if (argv[2] === 'dashboard') seen.push(values.get('refresh'))
                return run(argv, opts)
            }) as typeof run
            await refreshDashboard(io)
            expect(seen).toEqual([{ fetchedAt: null, failed: true, loading: true }])
            expect(values.get('refresh')).not.toHaveProperty('loading')
        }
    })

    it('counts the loads in a row that failed, from when each answered, and starts again at the first that did not', async () => {
        const { io, clock, answers } = fakePort({}, { dashboard: '' })
        const run = io.process.run
        // The read takes 3 s: the next try is timed from its answer.
        io.process.run = (async (argv: string[], opts: never) => {
            clock.now += 3_000
            return run(argv, opts)
        }) as typeof run
        await refreshDashboard(io)
        expect(mod.loads.dashboardMisses).toBe(1)
        expect(mod.loads.dashboardTriedAt).toBe(4_000)
        answers.dashboard = json({ status: 'error', message: 'boom' })
        await refreshDashboard(io)
        expect(mod.loads.dashboardMisses).toBe(2)
        answers.dashboard = json(dashboard('s1'))
        await refreshDashboard(io)
        expect(mod.loads.dashboardMisses).toBe(0)
    })

    it('asks the wrapper with the fan-in threshold the options set', async () => {
        reset({ ...options, fanInThreshold: 37 })
        const { io, runs } = fakePort({}, { dashboard: json(dashboard('s1')) })
        await refreshDashboard(io)
        expect(runs[0]).toEqual(['sh', '/plugin/hooks/scripts/knossos-run.sh', 'dashboard', ROOT, '--fan-in-threshold=37', '--boundaries=100'])
    })

    it('keeps the old figures and marks the refresh failed when the wrapper is silent', async () => {
        const old = dashboard('s0')
        const { io, values } = fakePort({ dashboard: old, refresh: { fetchedAt: 7, failed: false } }, { dashboard: '' })
        await expect(refreshDashboard(io)).resolves.toBe(false)
        expect(values.get('dashboard')).toBe(old)
        expect(values.get('refresh')).toEqual({ fetchedAt: 7, failed: true })
    })

    it('keeps good figures over an error envelope, but stores the error when there were none', async () => {
        const old = dashboard('s0')
        const over = fakePort({ dashboard: old, refresh: { fetchedAt: 7, failed: false } }, { dashboard: json({ status: 'error', message: 'boom' }) })
        await expect(refreshDashboard(over.io)).resolves.toBe(false)
        expect(over.values.get('dashboard')).toBe(old)
        expect(over.values.get('refresh')).toEqual({ fetchedAt: 7, failed: true })

        reset(options)
        mod.session.snapshot = 'kept'
        const fresh = fakePort({}, { dashboard: json({ status: 'error', message: 'boom' }) })
        await expect(refreshDashboard(fresh.io)).resolves.toBe(true)
        expect(fresh.values.get('dashboard')).toEqual({ status: 'error', message: 'boom' })
        expect(fresh.values.get('refresh')).toEqual({ fetchedAt: 1_000, failed: false })
        expect(mod.session.snapshot).toBe('kept')
        expect(fresh.values.get('sessionStart')).toBeNull()
        expect(fresh.values.get('startCycles')).toBeNull()
    })

    it('turns the mod off when the wrapper finds no binary, and a later refresh runs nothing', async () => {
        const { io, runs, values } = fakePort({}, { dashboard: json({ status: 'no-binary' }) })
        await expect(refreshDashboard(io)).resolves.toBe(false)
        expect(mod.session.disabled).toBe(true)
        expect(values.get('dashboard')).toBeNull()
        expect(runs).toHaveLength(1)
        await expect(refreshDashboard(io)).resolves.toBe(false)
        expect(runs).toHaveLength(1)
    })

    it('toasts a new cycle once, however often it comes back', async () => {
        const { io, toasts, answers } = fakePort({ dashboard: dashboard('s1') }, { dashboard: json(dashboard('s2', { cycles: [cycle] })) })
        await refreshDashboard(io)
        expect(toasts).toHaveLength(1)
        expect(toasts[0]).toContain('a new dependency cycle of 2')
        expect(mod.alerts.toasted.has(`cycle:App\\A\u0000App\\B`)).toBe(true)
        // The cycle goes away and comes back in a later scan: it was said already.
        answers.dashboard = json(dashboard('s3'))
        await refreshDashboard(io)
        answers.dashboard = json(dashboard('s4', { cycles: [cycle] }))
        await refreshDashboard(io)
        expect(toasts).toHaveLength(1)
    })

    it('toasts unreadable policies again after they were fixed and broke again', async () => {
        const broken = (snapshot: string): Dashboard => ({ ...dashboard(snapshot), policy: { status: 'config_invalid', message: 'bad', total: 0, truncated: false, truncation_reasons: [], items: [] } }) as unknown as Dashboard
        const { io, toasts, answers } = fakePort({ dashboard: dashboard('s1') }, { dashboard: json(broken('s2')) })
        await refreshDashboard(io)
        answers.dashboard = json(dashboard('s3'))
        await refreshDashboard(io)
        answers.dashboard = json(broken('s4'))
        await refreshDashboard(io)
        expect(toasts).toEqual(['knossos: policies unreadable: bad', 'knossos: policies unreadable: bad'])
    })

    it('toasts nothing when notifications are off', async () => {
        reset({ ...options, notifications: false })
        const { io, toasts, values } = fakePort({ dashboard: dashboard('s1') }, { dashboard: json(dashboard('s2', { cycles: [cycle] })) })
        await expect(refreshDashboard(io)).resolves.toBe(true)
        expect(toasts).toEqual([])
        expect(mod.alerts.toasted.size).toBe(0)
        expect(values.get('dashboard')).toEqual(dashboard('s2', { cycles: [cycle] }))
    })

    it('takes the first snapshot it sees as where the session began, and saves that baseline', async () => {
        const { io, values, stored, clock } = fakePort({}, { dashboard: json(dashboard('s1')) })
        clock.now = 2_500
        await refreshDashboard(io)
        expect(values.get('sessionStart')).toBe('s1')
        expect(stored.get(BASELINES_KEY)).toEqual({ [SESSION]: { rev: null, snapshot: 's1', startedAt: 2_500 } })
        expect(values.get('sessionBegan')).toBe(2_500)
    })

    it('leaves a session start already recorded as it is', async () => {
        const { io, values, stored } = fakePort({ sessionStart: 's0' }, { dashboard: json(dashboard('s1')) })
        await refreshDashboard(io)
        expect(values.get('sessionStart')).toBe('s0')
        expect(stored.has(BASELINES_KEY)).toBe(false)
    })

    it('records the cycles the session starts with only once', async () => {
        const first = dashboard('s1', { cycles: [cycle] })
        const { io, values, answers } = fakePort({}, { dashboard: json(first) })
        await refreshDashboard(io)
        const seeded = cyclesOf(first)
        expect(values.get('startCycles')).toEqual(seeded)
        expect(seeded.count).toBe(1)
        answers.dashboard = json(dashboard('s2'))
        await refreshDashboard(io)
        expect(values.get('startCycles')).toEqual(seeded)

        reset(options)
        const kept = { count: 4, keys: ['x'], complete: true, exact: true }
        const preset = fakePort({ startCycles: kept }, { dashboard: json(first) })
        await refreshDashboard(preset.io)
        expect(preset.values.get('startCycles')).toBe(kept)
    })

    it('an ok dashboard without a snapshot keeps the snapshot known and starts no session', async () => {
        mod.session.snapshot = 'prior'
        const { io, values, stored } = fakePort({}, { dashboard: json(dashboard(null)) })
        await expect(refreshDashboard(io)).resolves.toBe(true)
        expect(mod.session.snapshot).toBe('prior')
        expect(values.get('sessionStart')).toBeNull()
        expect(stored.has(BASELINES_KEY)).toBe(false)
    })

    it('looks the component on show up again in the new graph', async () => {
        const shown = { name: 'App\\Kernel', label: 'Kernel' }
        const { io, values, runs, advance } = fakePort({ view: view({ inspect: shown }) }, { dashboard: json(dashboard('s2')) })
        await refreshDashboard(io)
        expect(values.get('detail')).toEqual({ snapshot_id: 's2', name: 'App\\Kernel', detail: null, phase: 'loading' })
        await advance()
        const asked = runs.filter(r => r[2] !== 'dashboard').map(r => [r[2], ...r.slice(4)])
        expect(asked).toEqual(
            expect.arrayContaining([
                ['blast-radius', '--component=App\\Kernel'],
                ['component-detail', 'App\\Kernel'],
            ]),
        )
    })

    it('reads the open Branch tab again for the new graph', async () => {
        const { io, subs, advance } = fakePort({ view: view({ tab: 'branch' }) }, { dashboard: json(dashboard('s2')) })
        await refreshDashboard(io)
        await advance()
        expect(subs()).toContain('branch-diff')
    })

    it('coalesces refreshes asked for during a load into one load after it', async () => {
        const { io, subs } = fakePort({}, { dashboard: json(dashboard('s1')) })
        const all = await Promise.all([refreshDashboard(io), refreshDashboard(io), refreshDashboard(io)])
        expect(all).toEqual([true, true, true])
        expect(subs().filter(s => s === 'dashboard')).toHaveLength(2)
    })

    it('starts the motion when a new snapshot lands on an open pane, and not when the same one is read again', async () => {
        mod.pane.text = 'live'
        const moved = fakePort({ dashboard: dashboard('s1') }, { dashboard: json(dashboard('s2')) })
        moved.clock.now = 9_000
        await refreshDashboard(moved.io)
        expect(moved.values.get('motion')).toEqual({ since: 9_000, frame: 0 })
        const same = fakePort({ dashboard: dashboard('s2') }, { dashboard: json(dashboard('s2')) })
        same.clock.now = 9_000
        await refreshDashboard(same.io)
        expect(same.values.get('motion')).toBeUndefined()
    })

    it('lights the hub a new snapshot moved', async () => {
        const { io, values, clock } = fakePort({ dashboard: dashboard('s1', { hubs: [hub(3)] }) }, { dashboard: json(dashboard('s2', { hubs: [hub(5)] })) })
        clock.now = 9_000
        await refreshDashboard(io)
        const flash = values.get('flash') as { keys: string[]; until: number }
        expect(flash.keys).toContain('hub:App\\Kernel')
        expect(flash.until).toBe(9_000 + FLASH_MS)
    })
})
