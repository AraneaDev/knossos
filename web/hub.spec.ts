import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { THEMES } from '../hooks/lib/themes'
import { handlersOf } from '../hooks/mod/actions'
import { mod, reset } from '../hooks/mod/state'
import { activeTab, fixtureDashboard, fixturePluginRoot, frameText, scratch } from './fixtures/helpers'
import { Hub } from './hub'
import type { Frame } from './hub'
import { webPort } from './port'

const ROOT = '/work/app'

/** A hub over a port whose dashboard is already loaded, and the frames each page received. */
async function loaded() {
  const hub = new Hub()
  const io = webPort({ root: ROOT, pluginRoot: fixturePluginRoot(), storeFile: join(scratch(), 'store.json'), hub })
  hub.attach(io)
  await io.state.dashboard.update(() => fixtureDashboard(ROOT))
  const frames = new Map<string, { at: number; frame: Frame }[]>()
  const page = (id: string, columns: number, rows = 40) => {
    frames.set(id, [])
    hub.connect(id, { columns, rows }, event => {
      if (event.type === 'frame') frames.get(id)!.push({ at: Date.now(), frame: event as Frame })
    })
    return frames.get(id)!
  }
  return { hub, io, page }
}

beforeEach(() => {
  reset({ watch: false })
  mod.host = 'web'
})

describe('Hub', () => {
  it('coalesces bursts, never drops the last frame', async () => {
    const { hub, io, page } = await loaded()
    const got = page('a', 100)
    await vi.waitFor(() => expect(got).toHaveLength(1))
    await new Promise(resolve => setTimeout(resolve, 60))
    const start = Date.now()
    for (let i = 1; i <= 10; i++) {
      await io.state.feedback.update(() => ({ text: `step ${i}`, tone: 'ok', until: Date.now() + 60_000 }))
      hub.changed()
    }
    expect(Date.now() - start).toBeLessThan(40)
    await new Promise(resolve => setTimeout(resolve, 150))
    const burst = got.slice(1)
    expect(burst).toHaveLength(2)
    expect(burst[1]!.at - burst[0]!.at).toBeGreaterThanOrEqual(45)
    expect(frameText(burst[1]!.frame)).toContain('step 10')
  })

  it('lays each page out at its own size, one state', async () => {
    const { io, page } = await loaded()
    const narrow = page('narrow', 60)
    const wide = page('wide', 200)
    await vi.waitFor(() => expect(narrow.length * wide.length).toBeGreaterThan(0))
    const widest = (frame: Frame) => Math.max(...frameText(frame).split('\n').map(line => line.length))
    expect(widest(narrow.at(-1)!.frame)).toBeLessThanOrEqual(60)
    expect(widest(wide.at(-1)!.frame)).toBeGreaterThan(130)
    // Narrow, only the open tab is named; wide, every one.
    expect(frameText(narrow.at(-1)!.frame)).not.toContain('Boundaries')
    expect(frameText(wide.at(-1)!.frame)).toContain('Boundaries')
    handlersOf(io).press('tab:hubs')
    await vi.waitFor(() => {
      expect(activeTab(narrow.at(-1)!.frame)).toBe('hubs')
      expect(activeTab(wide.at(-1)!.frame)).toBe('hubs')
    })
  })

  it('sends each page its theme colours for the keys its frame uses, and the wheel the page rows', async () => {
    const { hub, page } = await loaded()
    const dark = page('dark', 100)
    await vi.waitFor(() => expect(dark).toHaveLength(1))
    const { frame } = dark[0]!
    expect(frame.theme).toBe('dark')
    // The page's own ink and ground, a hover card's and a hotkey's accent, whatever the rows name.
    expect(frame.colours).toEqual({ ground: '#1e1e1e', text: '#ffffff', card: '#373737', suggestion: THEMES.dark.suggestion })
    // Every segment's look comes resolved: no theme key reaches the page.
    const looks = [...frame.body, ...frame.footer].flatMap(row => row.segments.flatMap(s => [s.look.color, s.look.background]))
    expect(looks.filter(v => v !== undefined).length).toBeGreaterThan(0)
    for (const value of looks) if (value !== undefined) expect(value).toMatch(/^#[0-9a-f]{6}$/)
    hub.setTheme('dark', 'light')
    await vi.waitFor(() => expect(dark.at(-1)!.frame.theme).toBe('light'))
    expect(dark.at(-1)!.frame.colours.text).toBe('#000000')
    expect(dark.at(-1)!.frame.colours.ground).toBe('#ffffff')
    expect(hub.rowsOf('dark').length).toBeGreaterThan(0)
    hub.disconnect('dark')
    expect(hub.connected).toBe(false)
    expect(hub.rowsOf('dark')).toEqual([])
  })

  it('logs a layout that throws and leaves the page on its last frame', async () => {
    const { hub, io, page } = await loaded()
    const got = page('a', 100)
    await vi.waitFor(() => expect(got).toHaveLength(1))
    const rows = hub.rowsOf('a')
    expect(rows.length).toBeGreaterThan(0)
    const log = vi.spyOn(io.ui, 'log').mockImplementation(() => undefined)
    io.state.view.read = async () => {
      throw new Error('no view')
    }
    hub.changed()
    await vi.waitFor(() => expect(log).toHaveBeenCalledWith(expect.stringContaining('no view')))
    await new Promise(resolve => setTimeout(resolve, 80))
    expect(got).toHaveLength(1)
    expect(hub.rowsOf('a')).toBe(rows)
  })

  it('hands an event to every page', async () => {
    const { hub } = await loaded()
    const seen: unknown[] = []
    hub.connect('a', { columns: 80, rows: 30 }, event => event.type === 'toast' && seen.push(event))
    hub.connect('b', { columns: 80, rows: 30 }, event => event.type === 'toast' && seen.push(event))
    hub.send({ type: 'toast', text: 'hi' })
    expect(seen).toEqual([{ type: 'toast', text: 'hi' }, { type: 'toast', text: 'hi' }])
  })
})
