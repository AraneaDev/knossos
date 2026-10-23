import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AllowState, RescanState } from '../../types'
import { PRESSES } from './actions'
import type { Port } from './port'
import { mod, reset } from './state'
import { scanSafely } from './watcher'

vi.mock('./watcher', async importOriginal => ({ ...(await importOriginal<typeof import('./watcher')>()), scanSafely: vi.fn(async () => undefined) }))

const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }
const ROOT = '/work/refused'
const IDLE: AllowState = { phase: 'idle', root: null, reason: null }
const ALLOWED = JSON.stringify({ path: ROOT, added: true, roots_file: '/data/roots.json' })

/**
 * A port with what the allow flow touches: the session state as plain values,
 * timers that run only when the test runs them, and a wrapper that answers
 * `stdout` and records each command it was asked to run. `refuse` makes the
 * allow state's update throw for a phase, as a torn-down session would.
 */
function allowPort(init: { allow?: AllowState; rescan?: RescanState; brief?: unknown; stdout?: string; scan?: string; refuse?: AllowState['phase'] } = {}) {
    const values = new Map<string, unknown>(Object.entries({ allow: init.allow ?? IDLE, rescan: init.rescan ?? { phase: 'idle', reason: null }, brief: init.brief ?? null }))
    const runs: string[][] = []
    const timers: (() => void)[] = []
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => values.get(key),
                update: async (change: (v: unknown) => unknown) => {
                    const next = change(values.get(key))
                    if (key === 'allow' && (next as AllowState).phase === init.refuse) throw new Error('state refused')
                    values.set(key, next)
                    return next
                },
            }),
        },
    )
    const io = {
        clock: { now: async () => 0, after: (_ms: number, run: () => void) => (timers.push(run), { cancel: () => undefined }) },
        ui: { log: () => undefined, invalidate: () => undefined, toast: () => undefined },
        session: { root: async () => '/work/session' },
        plugin: { root: '/plugin' },
        process: {
            run: async (argv: string[]) => {
                runs.push(argv)
                return { exitCode: 0, stdout: argv[2] === 'allow-root' ? (init.stdout ?? ALLOWED) : (init.scan ?? ''), stderr: '' }
            },
        },
        state,
    } as unknown as Port
    const settle = async () => {
        for (let i = 0; i < 20; i++) await new Promise(resolve => setTimeout(resolve, 0))
    }
    /** Runs every queued timer, then lets what they started settle. */
    const runTimers = async () => {
        for (const run of timers.splice(0)) run()
        await settle()
    }
    return { io, values, runs, timers, runTimers, allow: () => values.get('allow') as AllowState }
}

const press = (io: Port, id: string) => PRESSES.get(id)!(io, '', undefined, id)

beforeEach(() => {
    reset(options)
    vi.mocked(scanSafely).mockClear()
})

describe('allow', () => {
    it('changes nothing with no refused root while idle', async () => {
        const port = allowPort()
        await press(port.io, 'allow')
        expect(port.allow()).toEqual(IDLE)
    })

    it('asks about the root a rescan was refused for', async () => {
        const port = allowPort({ rescan: { phase: 'failed', reason: 'not an allowed root', refusedRoot: ROOT } })
        await press(port.io, 'allow')
        expect(port.allow()).toEqual({ phase: 'confirming', root: ROOT, reason: null })
    })

    it('asks about the root the turn brief was refused for', async () => {
        const port = allowPort({ brief: { status: 'not-allowed', refused_root: '/work/brief-root', path: '/work/brief-root/src', roots_file: '/data/roots.json' } })
        await press(port.io, 'allow')
        expect(port.allow()).toEqual({ phase: 'confirming', root: '/work/brief-root', reason: null })
    })

    it('asks again about the root a failed run was for, its reason cleared', async () => {
        const port = allowPort({ allow: { phase: 'failed', root: ROOT, reason: 'knossos did not allow it' } })
        await press(port.io, 'allow')
        expect(port.allow()).toEqual({ phase: 'confirming', root: ROOT, reason: null })
    })

    it('changes nothing while a run is under way', async () => {
        const running: AllowState = { phase: 'running', root: ROOT, reason: null }
        const port = allowPort({ allow: running, rescan: { phase: 'failed', reason: null, refusedRoot: ROOT } })
        await press(port.io, 'allow')
        expect(port.allow()).toEqual(running)
    })
})

describe('allow-yes', () => {
    it.each<AllowState>([IDLE, { phase: 'failed', root: ROOT, reason: 'x' }, { phase: 'done', root: ROOT, reason: null }, { phase: 'confirming', root: null, reason: null }])(
        'runs nothing unless the pane is asking about a root (%o)',
        async allow => {
            const port = allowPort({ allow })
            await press(port.io, 'allow-yes')
            expect(port.timers).toEqual([])
            expect(mod.allow.inFlight).toBe(false)
            expect(port.allow()).toEqual(allow)
        },
    )

    it('starts the run on a timer, not inside the press, and a second press adds nothing', async () => {
        const port = allowPort({ allow: { phase: 'confirming', root: ROOT, reason: null } })
        await press(port.io, 'allow-yes')
        expect(mod.allow.inFlight).toBe(true)
        expect(port.allow()).toEqual({ phase: 'running', root: ROOT, reason: null })
        expect(port.runs).toEqual([])
        // Put the question back: only mod.allow.inFlight keeps a second run from being queued.
        await port.io.state.allow.update(() => ({ phase: 'confirming', root: ROOT, reason: null }))
        await press(port.io, 'allow-yes')
        expect(port.timers).toHaveLength(1)
        await port.runTimers()
        expect(port.runs).toEqual([
            ['sh', '/plugin/hooks/scripts/knossos-run.sh', 'allow-root', ROOT],
            ['sh', '/plugin/hooks/scripts/knossos-run.sh', 'scan', ROOT],
        ])
    })

    it('allowed: done, the refusal forgotten, the watcher may watch, and the root scanned for the first time', async () => {
        const port = allowPort({ allow: { phase: 'confirming', root: ROOT, reason: null }, rescan: { phase: 'failed', reason: 'not an allowed root', refusedRoot: ROOT } })
        mod.watch.refused = true
        await press(port.io, 'allow-yes')
        await port.runTimers()
        expect(port.allow()).toEqual({ phase: 'done', root: ROOT, reason: null })
        expect((port.values.get('rescan') as RescanState).refusedRoot ?? null).toBeNull()
        expect(mod.watch.refused).toBe(false)
        // The pane's rescan creates the project at the allowed root; the turn brief would only answer unscanned.
        expect(port.runs[0]).toEqual(['sh', '/plugin/hooks/scripts/knossos-run.sh', 'allow-root', ROOT])
        expect(port.runs[1]).toEqual(['sh', '/plugin/hooks/scripts/knossos-run.sh', 'scan', ROOT])
        expect(vi.mocked(scanSafely)).not.toHaveBeenCalled()
        expect(mod.allow.inFlight).toBe(false)
    })

    it('a first scan that lands leaves the pane idle, one that does not says it failed', async () => {
        const landed = allowPort({ allow: { phase: 'confirming', root: ROOT, reason: null }, scan: JSON.stringify({ status: 'ok', snapshot_id: 'scan_1', project_root: ROOT }) })
        await press(landed.io, 'allow-yes')
        await landed.runTimers()
        expect(landed.values.get('rescan')).toMatchObject({ phase: 'idle', reason: null })
        const failed = allowPort({ allow: { phase: 'confirming', root: ROOT, reason: null }, scan: JSON.stringify({ status: 'unscanned' }) })
        await press(failed.io, 'allow-yes')
        await failed.runTimers()
        expect((failed.values.get('rescan') as RescanState).phase).toBe('failed')
    })

    it('a wrapper refusal: failed, with the reason the wrapper gave', async () => {
        const reason = 'no roots file is known to the hooks; reinstall the plugin with knossos install-agent-plugin --execute'
        const port = allowPort({ allow: { phase: 'confirming', root: ROOT, reason: null }, stdout: JSON.stringify({ status: 'refused', by: 'wrapper', reason }) })
        await press(port.io, 'allow-yes')
        await port.runTimers()
        expect(port.allow()).toEqual({ phase: 'failed', root: ROOT, reason })
        expect(vi.mocked(scanSafely)).not.toHaveBeenCalled()
    })

    it.each(['', 'not json', JSON.stringify({ path: ROOT, added: true, preview: true })])('an answer it cannot read (%j): failed, knossos did not allow it', async stdout => {
        const port = allowPort({ allow: { phase: 'confirming', root: ROOT, reason: null }, rescan: { phase: 'failed', reason: null, refusedRoot: ROOT }, stdout })
        mod.watch.refused = true
        await press(port.io, 'allow-yes')
        await port.runTimers()
        expect(port.allow()).toEqual({ phase: 'failed', root: ROOT, reason: 'knossos did not allow it' })
        expect((port.values.get('rescan') as RescanState).refusedRoot).toBe(ROOT)
        expect(mod.watch.refused).toBe(true)
        expect(vi.mocked(scanSafely)).not.toHaveBeenCalled()
        expect(mod.allow.inFlight).toBe(false)
    })

    it('no binary: back to idle with no root, and the mod turns itself off', async () => {
        const port = allowPort({ allow: { phase: 'confirming', root: ROOT, reason: null }, stdout: JSON.stringify({ status: 'no-binary' }) })
        await press(port.io, 'allow-yes')
        await port.runTimers()
        expect(port.allow()).toEqual(IDLE)
        expect(mod.session.disabled).toBe(true)
        expect(vi.mocked(scanSafely)).not.toHaveBeenCalled()
        expect(mod.allow.inFlight).toBe(false)
    })

    it('lets a later press run again when the run throws', async () => {
        const port = allowPort({ allow: { phase: 'confirming', root: ROOT, reason: null }, refuse: 'done' })
        await press(port.io, 'allow-yes')
        await port.runTimers()
        expect(mod.allow.inFlight).toBe(false)
        expect(port.allow().phase).toBe('running')
        await port.io.state.allow.update(() => ({ phase: 'confirming', root: ROOT, reason: null }))
        await press(port.io, 'allow-yes')
        expect(port.timers).toHaveLength(1)
    })
})

describe('allow-no', () => {
    it('puts the question away, back to idle', async () => {
        const port = allowPort({ allow: { phase: 'confirming', root: ROOT, reason: null } })
        await press(port.io, 'allow-no')
        expect(port.allow()).toEqual(IDLE)
    })

    it.each<AllowState>([
        { phase: 'running', root: ROOT, reason: null },
        { phase: 'failed', root: ROOT, reason: 'knossos did not allow it' },
        { phase: 'done', root: ROOT, reason: null },
    ])('leaves any other phase alone (%o)', async allow => {
        const port = allowPort({ allow })
        await press(port.io, 'allow-no')
        expect(port.allow()).toEqual(allow)
    })
})
