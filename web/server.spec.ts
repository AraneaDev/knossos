import { request } from 'node:http'
import { readFileSync } from 'node:fs'
import type { IncomingMessage } from 'node:http'
import { stripTypeScriptTypes } from 'node:module'
import { networkInterfaces } from 'node:os'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { activeTab, fixturePluginRoot, frameText, scratch } from './fixtures/helpers'
import type { Frame } from './hub'
import { cardPlace } from '../hooks/lib/place'
import { followHead } from '../hooks/mod/watcher'
import { mod } from '../hooks/mod/state'
import { startServer } from './server'

// A wheel whose handler throws: what the server answers for an internal failure.
vi.mock('../hooks/mod/actions', async original => ({
  ...(await original<typeof import('../hooks/mod/actions')>()),
  wheelScrolled: vi.fn(async () => {
    throw new Error('boom')
  }),
}))
// The strip that makes the placement rule JavaScript, so a spec can make it fail.
vi.mock('node:module', async original => {
  const real = await original<typeof import('node:module')>()
  return { ...real, stripTypeScriptTypes: vi.fn(real.stripTypeScriptTypes) }
})
// The checkout's commit read, counted: the head poll reads it only while a page is connected.
vi.mock('../hooks/mod/watcher', async original => {
  const real = await original<typeof import('../hooks/mod/watcher')>()
  return { ...real, followHead: vi.fn(real.followHead) }
})

type Running = Awaited<ReturnType<typeof startServer>>
let running: Running | null = null

afterEach(async () => {
  await running?.close()
  running = null
})

/** A server over the fixture wrapper, for a project dir and data dir of its own, once start-up has loaded the dashboard. */
async function started(): Promise<Running> {
  running = await startServer({ root: scratch(), pluginRoot: fixturePluginRoot(), dataDir: scratch(), port: 0 })
  await running.ready
  return running
}

/** A GET's status and body. */
function get(url: string): Promise<{ status: number; body: string; type: string }> {
  return new Promise((resolve, reject) => {
    request(url, res => {
      let body = ''
      res.setEncoding('utf8')
      res.on('data', chunk => (body += chunk))
      res.on('end', () => resolve({ status: res.statusCode ?? 0, body, type: String(res.headers['content-type'] ?? '') }))
    })
      .on('error', reject)
      .end()
  })
}

/** A POST of `body` as JSON (or with `headers` of its own); its status. */
function post(url: string, body: unknown, headers: Record<string, string> = { 'content-type': 'application/json' }): Promise<number> {
  return new Promise((resolve, reject) => {
    const req = request(url, { method: 'POST', headers }, res => {
      res.resume()
      res.on('end', () => resolve(res.statusCode ?? 0))
    })
    req.on('error', reject)
    req.end(JSON.stringify(body))
  })
}

/** An open event stream: the frames it has received so far, and a way to wait for one that matches. */
function events(url: string): Promise<{ frames: Frame[]; next(match: (f: Frame) => boolean): Promise<Frame>; close(): void }> {
  return new Promise((resolve, reject) => {
    const frames: Frame[] = []
    const waiting: { match: (f: Frame) => boolean; done: (f: Frame) => void }[] = []
    const req = request(url, (res: IncomingMessage) => {
      expect(res.statusCode).toBe(200)
      expect(res.headers['content-type']).toMatch(/text\/event-stream/)
      let rest = ''
      res.setEncoding('utf8')
      res.on('data', (chunk: string) => {
        rest += chunk
        let cut
        while ((cut = rest.indexOf('\n\n')) >= 0) {
          const line = rest.slice(0, cut).split('\n').find(l => l.startsWith('data: '))
          rest = rest.slice(cut + 2)
          if (line === undefined) continue
          const event = JSON.parse(line.slice(6)) as Frame
          if (event.type !== 'frame') continue
          frames.push(event)
          for (const w of waiting.splice(0)) {
            if (w.match(event)) w.done(event)
            else waiting.push(w)
          }
        }
      })
      resolve({
        frames,
        next: match => new Promise(done => (frames.some(match) ? done(frames.find(match)!) : waiting.push({ match, done }))),
        close: () => req.destroy(),
      })
    })
    req.on('error', reject)
    req.end()
  })
}

describe('startServer', () => {
  it('serves the canonical release version to authenticated browser shells', async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    const got = await get(`${base}static/version.txt?t=${token}`)
    expect(got.status).toBe(200)
    expect(got.body).toBe(readFileSync(new URL('../version.txt', import.meta.url), 'utf8'))
    expect((await get(`${base}static/version.txt`)).status).toBe(403)
  })

  it('refuses a request without the token', async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    expect((await get(`${base}events?id=a&cols=80&rows=30`)).status).toBe(403)
    expect((await get(`${base}events?t=wrong&id=a&cols=80&rows=30`)).status).toBe(403)
    expect(await post(`${base}input`, { id: 'a', kind: 'press', press: 'tab:hubs' })).toBe(403)
    expect((await get(`${base}?t=${token}`)).status).not.toBe(403)
  })

  it('takes the token in its header, never a cookie, and tells no page where it came from', async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    const json = { 'content-type': 'application/json' }
    expect(await post(`${base}input`, { id: 'a', kind: 'press', press: 'tab:hubs' }, { ...json, 'x-knossos-token': token })).toBe(204)
    expect(await post(`${base}input`, { id: 'a', kind: 'press', press: 'tab:hubs' }, { ...json, cookie: `knossos=${token}` })).toBe(403)
    const page = await new Promise<IncomingMessage>(resolve => request(`${base}?t=${token}`, resolve).end())
    page.resume()
    expect(page.headers['set-cookie']).toBeUndefined()
    expect(page.headers['referrer-policy']).toBe('no-referrer')
  })

  it("refuses an input that is not JSON or comes from another page's origin", async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    const press = { id: 'a', kind: 'press', press: 'tab:hubs' }
    expect(await post(`${base}input?t=${token}`, press, { 'content-type': 'text/plain' })).toBe(415)
    expect(await post(`${base}input?t=${token}`, press, { 'content-type': 'application/json', origin: 'http://127.0.0.1:1' })).toBe(403)
    expect(await post(`${base}input?t=${token}`, press, { 'content-type': 'application/json', origin: base.replace(/\/$/, '') })).toBe(204)
    expect(await post(`${base}input?t=${token}`, 'not an object')).toBe(400)
  })

  it('serves nothing outside the page directory, and nothing without the token', async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    expect((await get(`${base}static/app.mjs`)).status).toBe(403)
    for (const path of ['static/../server.ts', 'static/%2e%2e/server.ts', 'static/..%2fserver.ts', 'static/%2e%2e%2fserver.ts']) {
      const got = await get(`${base}${path}?t=${token}`)
      expect([403, 404], path).toContain(got.status)
      expect(got.body, path).not.toContain('startServer')
    }
  })

  it('answers 500 for an input whose handler fails, and logs why', async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    const logged = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    expect(await post(`${base}input?t=${token}`, { id: 'a', kind: 'wheel', row: 1, column: 1, by: 1 })).toBe(500)
    expect(logged.mock.calls.flat().join('\n')).toContain('boom')
    logged.mockRestore()
  })

  it('streams the pane: the first event is the current frame, and a press moves the next one', async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    const stream = await events(`${base}events?t=${token}&id=a&cols=100&rows=40&theme=light`)
    const first = await stream.next(() => true)
    expect(frameText(first)).toContain('Overview')
    expect(first.theme).toBe('light')
    expect(await post(`${base}input?t=${token}`, { id: 'a', kind: 'press', press: 'tab:hubs' })).toBe(204)
    await stream.next(f => activeTab(f) === 'hubs')
    stream.close()
  })

  it('says, to a request without the token, where the right address is', async () => {
    const { url } = await started()
    const base = url.slice(0, url.indexOf('?'))
    for (const path of ['', '?t=stale', 'static/app.mjs']) {
      const got = await get(`${base}${path}`)
      expect(got.status).toBe(403)
      expect(got.body).toBe('This address is out of date or incomplete. Open the address knossos web printed in its terminal.\n')
    }
  })

  it('serves the app manifest for this server: one id, and a start address that carries the token', async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    const got = await get(`${base}manifest.webmanifest?t=${token}`)
    expect(got.status).toBe(200)
    const manifest = JSON.parse(got.body) as { id: string; start_url: string; scope: string; display: string; icons: { src: string }[] }
    expect(manifest.id).toBe('/')
    expect(manifest.start_url).toBe(url)
    expect(manifest.scope).toBe(base)
    expect(manifest.display).toBe('standalone')
    expect(manifest.icons[0]!.src).toBe(`${base}static/icon.svg?t=${token}`)
    expect((await get(`${base}manifest.webmanifest`)).status).toBe(403)
  })

  it('serves with the token and on the port it is given, and on a free port when that one is taken and it may move', async () => {
    const token = 'a'.repeat(48)
    const first = await startServer({ root: scratch(), pluginRoot: fixturePluginRoot(), dataDir: scratch(), port: 0, token })
    running = first
    expect(first.token).toBe(token)
    expect(first.url).toBe(`http://127.0.0.1:${first.port}/?t=${token}`)
    // The port is taken by the first: the second is refused it, unless it may move.
    await expect(startServer({ root: scratch(), pluginRoot: fixturePluginRoot(), dataDir: scratch(), port: first.port })).rejects.toThrow(/EADDRINUSE/)
    const moved = await startServer({ root: scratch(), pluginRoot: fixturePluginRoot(), dataDir: scratch(), port: first.port, movable: true })
    expect(moved.port).not.toBe(first.port)
    await moved.close()
  })

  it("reads the checkout's commit only while a page is connected, and at once when one connects", async () => {
    running = await startServer({ root: scratch(), pluginRoot: fixturePluginRoot(), dataDir: scratch(), port: 0, headPollMs: 20 })
    await running.ready
    const reads = vi.mocked(followHead)
    reads.mockClear()
    await new Promise(resolve => setTimeout(resolve, 150))
    expect(reads).not.toHaveBeenCalled()
    const base = running.url.slice(0, running.url.indexOf('?'))
    const stream = await events(`${base}events?t=${running.token}&id=a&cols=100&rows=40`)
    await stream.next(() => true)
    await vi.waitFor(() => expect(reads.mock.calls.length).toBeGreaterThan(1))
    stream.close()
    // The page's stream is gone once the server hears it close; after that nothing more is read.
    await new Promise(resolve => setTimeout(resolve, 100))
    const seen = reads.mock.calls.length
    await new Promise(resolve => setTimeout(resolve, 150))
    expect(reads.mock.calls.length).toBe(seen)
  })

  it('opens files with the editor command it is given', async () => {
    running = await startServer({ root: scratch(), pluginRoot: fixturePluginRoot(), dataDir: scratch(), port: 0, editor: 'zed {file}:{line}' })
    expect(mod.editor).toBe('zed {file}:{line}')
    expect(mod.host).toBe('web')
  })

  it('hands every kind of input to the pane, and refuses a kind it does not know or a body too large', async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    const send = (body: unknown) => post(`${base}input?t=${token}`, body)
    const stream = await events(`${base}events?t=${token}&id=a&cols=100&rows=40`)
    await stream.next(() => true)
    expect(await send({ id: 'a', kind: 'press', press: 'tab:hubs' })).toBe(204)
    expect(await send({ id: 'a', kind: 'type', field: 'filter', value: 'Ro' })).toBe(204)
    expect(await send({ id: 'a', kind: 'submit', field: 'filter', value: 'Ro' })).toBe(204)
    expect(await send({ id: 'a', kind: 'focus', element: 'tab:overview' })).toBe(204)
    expect(await send({ id: 'a', kind: 'resize', cols: 60, rows: 20 })).toBe(204)
    await stream.next(f => Math.max(...frameText(f).split('\n').map(l => l.length)) <= 60)
    expect(await send({ id: 'a', kind: 'theme', theme: 'light' })).toBe(204)
    await stream.next(f => f.theme === 'light')
    expect(await send({ id: 'a', kind: 'dance' })).toBe(400)
    expect(await post(`${base}input?t=${token}`, { id: 'a', kind: 'type', value: 'x'.repeat(70 * 1024) }).catch(() => 400)).toBe(400)
    stream.close()
  })

  it('serves the page and its files, and nothing at an address it does not know', async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    expect((await get(`${base}?t=${token}`)).body).toMatch(/<main\s+id="view"/)
    expect((await get(`${base}static/draw.mjs?t=${token}`)).body).toContain('export function patchPlan')
    expect((await get(`${base}static/missing.mjs?t=${token}`)).status).toBe(404)
    expect((await get(`${base}elsewhere?t=${token}`)).status).toBe(404)
  })

  it("serves the hooks module's placement rule as JavaScript that places a card as the TypeScript does", async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    expect((await get(`${base}static/place.mjs`)).status).toBe(403)
    const served = await get(`${base}static/place.mjs?t=${token}`)
    expect(served.status).toBe(200)
    expect(served.type).toMatch(/^text\/javascript/)
    expect(served.body).not.toMatch(/: number/)
    const page = (await import(`data:text/javascript;base64,${Buffer.from(served.body).toString('base64')}`)) as { cardPlace: typeof cardPlace }
    const pane = { rows: 20, columns: 80 }
    for (const [row, column, card] of [[3, 10, { rows: 4, columns: 30 }], [18, 10, { rows: 4, columns: 30 }], [2, 0, { rows: 19, columns: 10 }], [3, 70, { rows: 4, columns: 30 }], [3, 5, { rows: 4, columns: 100 }]] as const) {
      expect(page.cardPlace(row, column, card, pane)).toEqual(cardPlace(row, column, card, pane))
    }
  })

  it('answers 500, with no body, and logs why when the placement rule cannot be made', async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    const logged = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    vi.mocked(stripTypeScriptTypes).mockImplementationOnce(() => {
      throw new Error('no strip')
    })
    const got = await get(`${base}static/place.mjs?t=${token}`)
    expect(got.status).toBe(500)
    expect(got.body).toBe('')
    expect(logged.mock.calls.flat().join('\n')).toContain('no strip')
    logged.mockRestore()
  })

  it('listens on 127.0.0.1 only', async () => {
    const { url, token } = await started()
    expect(new URL(url).hostname).toBe('127.0.0.1')
    const port = new URL(url).port
    const outside = Object.values(networkInterfaces())
      .flat()
      .find(a => a !== undefined && a.family === 'IPv4' && !a.internal)
    if (outside === undefined) return
    await expect(get(`http://${outside.address}:${port}/?t=${token}`)).rejects.toThrow()
  })
})
