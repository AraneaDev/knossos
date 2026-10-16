import { beforeEach, describe, expect, it } from 'vitest'
import { LIVE_OFF } from '../lib/live'
import { MOTION_FRAME_MS, MOTION_MS, NO_MOTION } from '../lib/motion'
import { startMotion } from './motion'
import { disable } from './port'
import type { Port } from './port'
import { mod, reset } from './state'

/** A port with a clock whose repeating timers the test fires, and the state motion reads. */
function motionPort(live = LIVE_OFF, onRead: (key: string) => void = () => undefined) {
  const values = new Map<string, unknown>([
    ['motion', NO_MOTION],
    ['live', live],
    ['rescan', { phase: 'idle', reason: null }],
  ])
  const env = { now: 1_000 }
  const timers: { ms: number; run: () => void; cancelled: boolean }[] = []
  const state = new Proxy(
    {},
    {
      get: (_, key: string) => ({
        read: async () => {
          // The value as it stood when read; what the hook starts lands after it.
          const value = values.get(key)
          onRead(key)
          return value
        },
        update: async (change: (v: unknown) => unknown) => void values.set(key, change(values.get(key))),
      }),
    },
  )
  const io = {
    state,
    ui: { log: () => undefined, invalidate: () => undefined },
    clock: {
      now: async () => env.now,
      every: (ms: number, run: () => void) => {
        const timer = { ms, run, cancelled: false }
        timers.push(timer)
        return { cancel: () => void (timer.cancelled = true) }
      },
    },
  } as unknown as Port
  const live_ = () => timers.filter(t => !t.cancelled)
  /** Moves the clock on by a frame and fires the running timer, letting what it started settle. */
  const frame = async () => {
    env.now += MOTION_FRAME_MS
    for (const t of live_()) t.run()
    for (let i = 0; i < 3; i++) await new Promise(resolve => setTimeout(resolve, 0))
  }
  return { io, values, env, timers, running: live_, frame }
}

beforeEach(() => {
  reset({ fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: true, watchPollMs: 1000, notifications: true })
  mod.paneText = 'live'
})

describe('the frames motion draws', () => {
  it('starts when a scan lands, counts a frame each tick, and stops on its own once its moment is over', async () => {
    const port = motionPort()
    await startMotion(port.io, 1_000)
    expect(port.values.get('motion')).toEqual({ since: 1_000, frame: 0 })
    expect(port.running()).toHaveLength(1)
    expect(port.running()[0]!.ms).toBe(MOTION_FRAME_MS)
    await port.frame()
    expect(port.values.get('motion')).toEqual({ since: 1_000, frame: 1 })
    while (port.env.now < 1_000 + MOTION_MS) await port.frame()
    await port.frame()
    expect(port.running()).toEqual([])
  })

  it('runs one timer however often scans land, the moment starting again from the latest', async () => {
    const port = motionPort()
    await startMotion(port.io, 1_000)
    await port.frame()
    await startMotion(port.io, 1_200)
    expect(port.running()).toHaveLength(1)
    expect(port.values.get('motion')).toEqual({ since: 1_200, frame: 0 })
  })

  it('keeps turning while a scan runs, and stops once it is done and the moment over', async () => {
    const port = motionPort({ phase: 'scanning' })
    await startMotion(port.io, null)
    for (let i = 0; i < 40; i++) await port.frame()
    expect(port.running()).toHaveLength(1)
    port.values.set('live', { phase: 'live' })
    await port.frame()
    expect(port.running()).toEqual([])
  })

  it('draws no frames for a pane nobody has open', async () => {
    mod.paneText = null
    const port = motionPort()
    await startMotion(port.io, 1_000)
    expect(port.running()).toEqual([])
  })

  it('comes to rest when it stops: the dot drawn full and the bars whole again', async () => {
    const port = motionPort()
    await startMotion(port.io, 1_000)
    await port.frame()
    while (port.running().length > 0) await port.frame()
    expect(port.values.get('motion')).toEqual(NO_MOTION)
  })

  it('keeps going when a scan lands just as a frame decides to stop', async () => {
    let again: (() => void) | null = null
    const port = motionPort(LIVE_OFF, key => {
      if (key === 'motion' && again !== null) {
        const run = again
        again = null
        run()
      }
    })
    await startMotion(port.io, 1_000)
    port.env.now = 1_000 + MOTION_MS
    // The next frame reads the moment as over; a new snapshot lands just after that read.
    again = () => void startMotion(port.io, port.env.now)
    await port.frame()
    expect(port.running()).toHaveLength(1)
    expect((port.values.get('motion') as { since: number }).since).toBe(1_000 + MOTION_MS + MOTION_FRAME_MS)
  })

  it('stops for a pane closed while a scan runs, and steps the spinner at half the rate once the moment is over', async () => {
    const port = motionPort({ phase: 'scanning' })
    await startMotion(port.io, null)
    const before = (port.values.get('motion') as { frame: number }).frame
    for (let i = 0; i < 8; i++) await port.frame()
    expect((port.values.get('motion') as { frame: number }).frame - before).toBe(4)
    mod.paneText = null
    await port.frame()
    expect(port.running()).toEqual([])
  })

  it('stops with the mod when it turns itself off', async () => {
    const port = motionPort({ phase: 'scanning' })
    await startMotion(port.io, null)
    await disable(port.io)
    expect(port.running()).toEqual([])
  })
})

