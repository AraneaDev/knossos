import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Row } from '../lib/layout'
import { busy, NO_MOTION } from '../lib/motion'
import { noteSpinners, paneSource } from './frame'
import { currentInput } from './input'
import type { Port } from './port'
import { mod, reset } from './state'

vi.mock('./input', () => ({ currentInput: vi.fn() }))

const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: true, watchPollMs: 1000, notifications: true }

/** A port over plain cells, with the timers the clock was asked for. */
function fakePort(cells: Record<string, unknown> = {}) {
  const values: Record<string, unknown> = {
    brief: null,
    dashboard: null,
    view: { inspect: null, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' },
    refresh: { fetchedAt: null, failed: false },
    rescan: { phase: 'idle', reason: null },
    allow: { phase: 'idle', root: null, reason: null },
    motion: { ...NO_MOTION, frame: 3 },
    ...cells,
  }
  const state = new Proxy({}, { get: (_, key: string) => ({ read: async () => values[key], update: async (change: (v: unknown) => unknown) => (values[key] = change(values[key])) }) })
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
  } as unknown as Port
  return { io, timers }
}

const row = (text: string): Row => ({ key: 'r', segments: [{ text }] })

beforeEach(() => {
  reset(options)
  vi.mocked(currentInput).mockReset()
})

describe('paneSource', () => {
  it('is off while the mod is disabled', async () => {
    mod.disabled = true
    expect(await paneSource(fakePort().io, 80, true)).toEqual({ kind: 'off' })
  })

  it('is the empty state, a line under way, while there is no dashboard', async () => {
    const source = await paneSource(fakePort().io, 80, true)
    expect(source.kind).toBe('empty')
    expect(JSON.stringify(source)).toContain('Reading the graph')
    expect(mod.emptyShown).toBe(true)
  })

  it('offers an unscanned project the direct scan on the web host, and asking Claude otherwise', async () => {
    const cells = { dashboard: { status: 'unscanned' } }
    expect(JSON.stringify(await paneSource(fakePort(cells).io, 80, true))).toContain('scan-ask')
    mod.host = 'web'
    const web = JSON.stringify(await paneSource(fakePort(cells).io, 80, true))
    expect(web).toContain('"id":"scan"')
    expect(web).not.toContain('scan-ask')
  })

  it('shows the scan running once the scan it offered is under way, and no button, on either host', async () => {
    for (const host of ['claude', 'web'] as const) {
      mod.host = host
      const source = await paneSource(fakePort({ dashboard: { status: 'unscanned' }, rescan: { phase: 'scanning', reason: null } }).io, 80, true)
      expect(source.kind).toBe('empty')
      const text = JSON.stringify(source)
      expect(text).toContain('Scanning the project')
      expect(text).not.toContain('"press"')
    }
  })

  it('is the detail while something is inspected, the tab otherwise, and notes what the pane shows', async () => {
    vi.mocked(currentInput).mockResolvedValue({ status: { text: 'shown' } } as never)
    const dashboard = { status: 'ok' }
    expect(await paneSource(fakePort({ dashboard }).io, 140, true)).toMatchObject({ kind: 'pane', key: 'pane' })
    expect(mod.paneText).toBe('shown')
    expect(mod.paneWide).toBe(true)
    const inspect = { name: 'A', canonical: 'App\\A' }
    expect(await paneSource(fakePort({ dashboard, view: { inspect } }).io, 40, true)).toMatchObject({ kind: 'pane', key: 'detail' })
    expect(mod.paneWide).toBe(false)
  })
})

describe('noteSpinners', () => {
  it('marks the pane busy at the motion\'s frame and starts one timer for a row under way', async () => {
    const { io, timers } = fakePort()
    mod.paneText = 'shown'
    await noteSpinners(io, [row(busy('x'))])
    await noteSpinners(io, [row(busy('x'))])
    expect(mod.spinFrame).toBe(3)
    expect(mod.paneBusy).toBe(true)
    expect(timers).toHaveLength(1)
  })

  it('clears it when no row is under way', async () => {
    const { io, timers } = fakePort()
    mod.paneBusy = true
    await noteSpinners(io, [row('done')])
    expect(mod.paneBusy).toBe(false)
    expect(timers).toHaveLength(0)
  })
})
