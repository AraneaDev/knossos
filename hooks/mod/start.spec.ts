import { beforeEach, describe, expect, it } from 'vitest'
import type { AllowState, Dashboard, RescanState } from '../../types'
import { NO_CHANGES } from '../lib/session-changes'
import { LIVE_OFF } from '../lib/live'
import type { Port } from './port'
import { startDeliberately } from './start'
import { mod, reset } from './state'

const ROOT = '/work/app'
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }
const GRAPH = {
    status: 'ok',
    path: ROOT,
    project_root: ROOT,
    project_id: 'p1',
    snapshot_id: 's1',
    freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
    hubs: [],
    hubs_truncated: false,
    hubs_truncation_reasons: [],
    hotspots: [],
    dead_code_candidates: 0,
    dead_code_truncated: false,
    cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
    trend: [],
    fan_in: [],
    fan_in_truncated: false,
} as Dashboard
const claim = (status: string, reason: string | null = null) => JSON.stringify({ status, root: ROOT, reason })
const SCANNED = JSON.stringify({ status: 'ok', snapshot_id: 's2' })

/**
 * A port over plain values for the start-up claim: the session state, timers
 * that never run on their own, and a wrapper that answers each subcommand from
 * `answers` and records every run as `subcommand directory args`.
 */
function fakePort(init: { dashboard?: Dashboard | null; answers?: Record<string, string>; exitCode?: number } = {}) {
    const blank: Record<string, unknown> = {
        brief: null,
        dashboard: init.dashboard ?? null,
        refresh: { fetchedAt: null, failed: false },
        rescan: { phase: 'idle', reason: null },
        allow: { phase: 'idle', root: null, reason: null } satisfies AllowState,
        changes: NO_CHANGES,
        live: LIVE_OFF,
        sessionScans: [],
        sessionEdits: [],
        sessionStart: null,
        sessionBegan: null,
        sessionLedger: null,
        sessionRev: null,
        startCycles: null,
        job: null,
        feedback: null,
        flash: null,
        view: { inspect: null, isBandHidden: false, tab: 'churn', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' },
    }
    const values = new Map<string, unknown>(Object.entries(blank))
    const runs: string[] = []
    const answers: Record<string, string> = { dashboard: JSON.stringify(GRAPH), scan: SCANNED, ...init.answers }
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
        clock: { now: async () => 0, after: () => ({ cancel: () => undefined }), every: () => ({ cancel: () => undefined }) },
        ui: { log: () => undefined, invalidate: () => undefined, toast: () => undefined },
        session: { root: async () => ROOT, id: async () => null },
        plugin: { root: '/plugin' },
        process: {
            run: async (argv: string[]) => {
                runs.push([argv[2], argv[3], ...argv.slice(4)].join(' '))
                return { exitCode: init.exitCode ?? 0, stdout: answers[argv[2]!] ?? '', stderr: '' }
            },
        },
        state,
    } as unknown as Port
    const subs = () => runs.map(r => r.split(' ')[0])
    return { io, values, runs, subs, rescan: () => values.get('rescan') as RescanState, allow: () => values.get('allow') as AllowState }
}

beforeEach(() => reset(options))

describe('startDeliberately', () => {
    it('claims the folder with --execute, scans the claimed root, then loads the dashboard, in that order', async () => {
        const port = fakePort({ answers: { claim: claim('added') } })
        await startDeliberately(port.io)
        expect(port.runs[0]).toBe(`claim ${ROOT} --execute`)
        expect(port.subs().slice(0, 3)).toEqual(['claim', 'scan', 'dashboard'])
        expect(port.runs[1]).toBe(`scan ${ROOT}`)
        expect(port.values.get('dashboard')).toEqual(GRAPH)
    })

    it('scans a present root that has no graph yet', async () => {
        const port = fakePort({ answers: { claim: claim('present') }, dashboard: { status: 'unscanned' } as unknown as Dashboard })
        await startDeliberately(port.io)
        expect(port.subs().slice(0, 3)).toEqual(['claim', 'scan', 'dashboard'])
    })

    it('scans nothing for a present root that has a graph', async () => {
        const port = fakePort({ answers: { claim: claim('present') }, dashboard: GRAPH })
        await startDeliberately(port.io)
        expect(port.subs()).toEqual(['claim'])
    })

    it('scans nothing for an ask, and leaves the root refused so the pane makes today\'s offer', async () => {
        const port = fakePort({ answers: { claim: claim('ask', 'a folder above the home folder') } })
        await startDeliberately(port.io)
        expect(port.subs()).toEqual(['claim'])
        expect(port.rescan().refusedRoot).toBe(ROOT)
    })

    it.each([
        ['fixed', claim('fixed', 'a container')],
        ['silence', ''],
        ['nonsense', 'not json'],
    ])('scans nothing and raises nothing for %s', async (_, stdout) => {
        const port = fakePort({ answers: { claim: stdout } })
        await expect(startDeliberately(port.io)).resolves.toBeUndefined()
        expect(port.subs()).toEqual(['claim'])
        expect(port.rescan()).toEqual({ phase: 'idle', reason: null })
        expect(port.allow().phase).toBe('idle')
    })

    it('shows a refusal from the wrapper as the reason nothing was added, and scans nothing', async () => {
        const port = fakePort({ answers: { claim: JSON.stringify({ status: 'refused', by: 'wrapper', reason: 'no roots file is known to the hooks' }) } })
        await startDeliberately(port.io)
        expect(port.subs()).toEqual(['claim'])
        expect(port.allow()).toEqual({ phase: 'failed', root: ROOT, reason: 'no roots file is known to the hooks' })
    })

    it.each([
        ['a dashboard that failed to load', { status: 'error' }],
        ['no dashboard yet', null],
    ])('scans nothing for a present root with %s: only "unscanned" is a reason to scan', async (_, dashboard) => {
        const port = fakePort({ answers: { claim: claim('present') }, dashboard: dashboard as unknown as Dashboard })
        await startDeliberately(port.io)
        expect(port.subs()).toEqual(['claim'])
        expect(port.allow().phase).toBe('idle')
    })

    it('shows the reason of a roots file that cannot be used, and scans nothing', async () => {
        const port = fakePort({ answers: { claim: claim('error', 'roots.json is not valid JSON') } })
        await startDeliberately(port.io)
        expect(port.subs()).toEqual(['claim'])
        expect(port.allow()).toEqual({ phase: 'failed', root: ROOT, reason: 'roots.json is not valid JSON' })
    })

    it('turns the mod off when there is no binary', async () => {
        const port = fakePort({ answers: { claim: JSON.stringify({ status: 'no-binary' }) } })
        await startDeliberately(port.io)
        expect(mod.session.disabled).toBe(true)
        expect(port.subs()).toEqual(['claim'])
    })
})
