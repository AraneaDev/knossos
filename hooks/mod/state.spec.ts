import { describe, expect, it, vi } from 'vitest'
import { initialActivity, initialAgent, initialAlerts, initialAllow, initialLoads, initialMotion, initialNavigation, initialNotes, initialPane, initialRegistration, initialScans, initialSearch, initialSession, initialTurn, initialWatch, mod, reset } from './state'

const options = { fanInThreshold: 7, enforcePolicies: false, agentNotes: false, watch: false, watchPollMs: 2000, notifications: false, editor: 'zed {file}:{line}' }

/** A slice with its promise and collections compared by what they hold, not by identity. */
const plain = (slice: object): unknown => JSON.parse(JSON.stringify(slice, (_, v: unknown) => (v instanceof Set || v instanceof Map ? [...v] : v instanceof Promise ? 'promise' : v)))

describe('reset', () => {
  it('starts every slice over from its factory, in place', () => {
    const slices = { session: mod.session, turn: mod.turn, agent: mod.agent, loads: mod.loads }
    mod.session.disabled = true
    mod.turn.edited.add('src/a.ts')
    mod.agent.noted.add('main\u0000src/a.ts')
    mod.loads.dashboardMisses = 4
    mod.navigation.focused = 'tab:issues'
    reset(options, 'web')
    expect(mod.session).toBe(slices.session)
    expect(mod.turn).toBe(slices.turn)
    const expected = {
      session: initialSession(options, 'web'),
      registration: initialRegistration(),
      turn: initialTurn(),
      activity: initialActivity(),
      scans: initialScans(),
      loads: initialLoads(),
      watch: initialWatch(options),
      motion: initialMotion(),
      pane: initialPane(),
      search: initialSearch(),
      agent: initialAgent(options),
      alerts: initialAlerts(options),
      navigation: initialNavigation(options),
      allow: initialAllow(),
      notes: initialNotes(),
    }
    for (const [key, slice] of Object.entries(expected)) expect(plain(mod[key as keyof typeof expected])).toEqual(plain(slice))
    expect(mod.session).toMatchObject({ threshold: 7, enforce: false, host: 'web' })
    expect(mod.watch).toMatchObject({ on: false, pollMs: 2000 })
    expect(mod.navigation.editor).toBe('zed {file}:{line}')
    reset({})
    expect(mod.session.host).toBe('claude')
  })

  it('cancels the old load\'s timers', () => {
    const timers = [1, 2, 3, 4, 5].map(() => ({ cancel: vi.fn() }))
    mod.motion.ticker = timers[0]
    mod.motion.timer = timers[1]
    mod.registration.timer = timers[2]
    mod.search.timer = timers[3]
    mod.loads.timers.set('peek', timers[4])
    reset(options)
    for (const timer of timers) expect(timer.cancel).toHaveBeenCalledOnce()
  })

  it('never starts the generations or the drawn rows over: it bumps the generations and lets the watcher go', () => {
    const stream = { return: vi.fn(async () => undefined) } as unknown as AsyncGenerator<unknown, unknown>
    mod.drawn.rows = [{ key: 'r', segments: [] }]
    mod.drawn.offset = 3
    mod.watcher.stream = stream
    mod.watcher.pid = 42
    mod.watcher.pending = true
    const { register, watch } = mod.generations
    reset(options)
    expect(mod.generations).toEqual({ register: register + 1, watch: watch + 1 })
    expect(mod.drawn).toEqual({ rows: [{ key: 'r', segments: [] }], offset: 3 })
    expect(mod.watcher).toEqual({ stream: null, pid: null, pending: false })
    expect(stream.return).toHaveBeenCalledOnce()
  })
})
