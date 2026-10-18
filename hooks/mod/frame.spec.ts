import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Row } from '../lib/layout'
import { busy, NO_MOTION, spinGlyph } from '../lib/motion'
import { fieldRooms, noteSpinners, paneSource, spunRow } from './frame'
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

  it('shows the scan running on the web once the scan it offered is under way, and no button', async () => {
    mod.host = 'web'
    const source = await paneSource(fakePort({ dashboard: { status: 'unscanned' }, rescan: { phase: 'scanning', reason: null } }).io, 80, true)
    expect(source.kind).toBe('empty')
    const text = JSON.stringify(source)
    expect(text).toContain('Scanning the project')
    expect(text).not.toContain('"press"')
  })

  it('leaves the empty pane of Claude Code as it was while a rescan runs: its offer, no scanning line', async () => {
    const cells = { dashboard: { status: 'unscanned' } }
    const idle = await paneSource(fakePort(cells).io, 80, true)
    const scanning = await paneSource(fakePort({ ...cells, rescan: { phase: 'scanning', reason: null } }).io, 80, true)
    expect(scanning).toEqual(idle)
    expect(JSON.stringify(scanning)).not.toContain('Scanning the project')
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

describe('spunRow', () => {
  it('turns only the busy segments to the frame, and leaves a row with none as it is', () => {
    const row: Row = { key: 'r', segments: [{ text: busy('reading') }, { text: 'done', color: 'text' }] }
    const turned = spunRow(row, 3)
    expect(turned.segments[0]!.text).toBe(`${spinGlyph(3)} reading`)
    expect(turned.segments[1]).toBe(row.segments[1])
    const still: Row = { key: 's', segments: [{ text: 'idle' }] }
    expect(spunRow(still, 3)).toBe(still)
  })
})

describe('fieldRooms', () => {
  it('gives a field the rest of its row: the blank cells after it, up to the next drawn segment', () => {
    const segments = [{ text: 'find ' }, { text: '', field: { id: 'finder', value: '', placeholder: '' } }, { text: '   ' }, { text: '', hidden: true as const, press: { id: 'x', label: 'x', hotkey: 'x' } }, { text: ' │' }]
    expect([...fieldRooms(segments, 40)]).toEqual([[1, { room: 33, next: 3 }]])
  })

  it('keeps a blank on a ground, and a row with no field has no rooms', () => {
    const segments = [{ text: '', field: { id: 'f', value: '', placeholder: '' } }, { text: '  ', bg: 'selectionBg' }]
    expect(fieldRooms(segments, 10).get(0)).toEqual({ room: 8, next: 1 })
    expect(fieldRooms([{ text: 'plain' }], 10).size).toBe(0)
    // Never less than one cell, however full the row.
    expect(fieldRooms([{ text: 'x'.repeat(12) }, { text: '', field: { id: 'f', value: '', placeholder: '' } }], 10).get(1)!.room).toBe(1)
  })
})
