import { ServerResponse, createServer as createHttpServer, request } from 'node:http'
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import type { IncomingMessage } from 'node:http'
import { stripTypeScriptTypes } from 'node:module'
import { createServer as createNetServer } from 'node:net'
import type { Socket } from 'node:net'
import { networkInterfaces } from 'node:os'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { activeTab, fixturePluginRoot, frameText, scratch, spawnFixture } from './fixtures/helpers'
import { Hub } from './hub'
import type { Frame } from './hub'
import { cardPlace } from '../hooks/lib/place'
import { followHead } from '../hooks/mod/watcher'
import { mod } from '../hooks/mod/state'
import { CHALLENGE_HEADER, PANE_HEADER, PROOF_HEADER, proves } from './lock'
import { startServer } from './server'

// A wheel whose handler throws: what the server answers for an internal failure.
vi.mock('../hooks/mod/navigate-actions', async original => ({
  ...(await original<typeof import('../hooks/mod/navigate-actions')>()),
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
  // Start-up writes into the data directory: it is waited for, so nothing lands there once the test has removed it.
  await running?.ready
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

  it('closes with a stream open and an event arriving as it ends, without writing to the ended stream', async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    const stream = await events(`${base}events?t=${token}&id=a&cols=100&rows=40`)
    await stream.next(() => true)
    // Between a stream's end and the close event that removes its page, the hub may still send to it: make that the very next thing.
    const hubs = new Set<Hub>()
    const connect = Hub.prototype.connect
    vi.spyOn(Hub.prototype, 'connect').mockImplementation(function (this: Hub, ...args) {
      hubs.add(this)
      return connect.apply(this, args)
    })
    const end = ServerResponse.prototype.end as (...args: unknown[]) => ServerResponse
    vi.spyOn(ServerResponse.prototype, 'end').mockImplementation(function (this: ServerResponse, ...args: unknown[]) {
      const done = end.apply(this, args)
      for (const hub of hubs) hub.send({ type: 'toast', text: 'late' })
      return done
    } as never)
    const crashes: unknown[] = []
    const heard = (error: unknown) => crashes.push(error)
    process.on('uncaughtException', heard)
    try {
      // The hub of this server is the one that was connected to: connect again so that the spy sees it.
      const second = await events(`${base}events?t=${token}&id=b&cols=100&rows=40`)
      await second.next(() => true)
      await running!.close()
      await new Promise(resolve => setTimeout(resolve, 50))
      second.close()
      stream.close()
    } finally {
      process.off('uncaughtException', heard)
      vi.restoreAllMocks()
    }
    expect(crashes).toEqual([])
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
    await moved.ready
    await moved.close()
  })

  it('claims the folder it was started in, once start-up has loaded the dashboard', async () => {
    // The mod's state is one per process: a claim an earlier server's start-up still has in flight would hold this one off.
    await new Promise(resolve => setTimeout(resolve, 100))
    await vi.waitFor(() => expect(mod.deliberate.claiming).toBe(false))
    const root = scratch()
    const plugin = fixturePluginRoot()
    const log = join(scratch(), 'subcommands')
    const shim = join(plugin, 'hooks/scripts/knossos-run.sh')
    const fixture = readFileSync(shim, 'utf8')
    writeFileSync(shim, fixture.replace('exec sh', `printf '%s %s %s\\n' "$1" "$3" "\${KNOSSOS_ROOTS_FILE-unset}" >> '${log}'\nexec sh`))
    const dataDir = scratch()
    const saved = process.env.KNOSSOS_ROOTS_FILE
    delete process.env.KNOSSOS_ROOTS_FILE
    try {
      running = await startServer({ root, pluginRoot: plugin, dataDir, port: 0 })
      await running.ready
      // The roots file defaults to the data directory's, which the real wrapper needs to accept a claim at all.
      await vi.waitFor(() => expect(readFileSync(log, 'utf8')).toContain(`claim --execute ${join(dataDir, 'roots.json')}`))
    } finally {
      if (saved !== undefined) process.env.KNOSSOS_ROOTS_FILE = saved
    }
    const lines = readFileSync(log, 'utf8').split('\n')
    expect(lines.findIndex(l => l.startsWith('dashboard'))).toBeLessThan(lines.findIndex(l => l.startsWith('claim --execute')))
  })

  it('tells its pages where the project went, then closes itself, once another server took the lock over', async () => {
    let holder: number | null = null
    let asked = 0
    running = await startServer({
      root: scratch(),
      pluginRoot: fixturePluginRoot(),
      dataDir: scratch(),
      port: 0,
      headPollMs: 20,
      holder: async () => {
        asked++
        return holder
      },
    })
    await running.ready
    const base = running.url.slice(0, running.url.indexOf('?'))
    const raw = await fetch(`${base}events?t=${running.token}&id=a&cols=100&rows=40`)
    const body = raw.body!.getReader()
    let text = ''
    const read = (async () => {
      for (;;) {
        const { done, value } = await body.read()
        if (done) return
        text += new TextDecoder().decode(value)
      }
    })()
    let stepped = false
    void running.stepped.then(() => (stepped = true))
    // Many ticks while the lock is still its own: it keeps serving.
    await vi.waitFor(() => expect(asked).toBeGreaterThan(3))
    expect(stepped).toBe(false)
    expect((await get(running.url)).status).toBe(200)
    holder = 41234
    await running.stepped
    // The stream carried the other's port before it ended, and nothing of the token.
    await read
    const events = text.split('\n\n').filter(block => block.startsWith('data: ')).map(block => JSON.parse(block.slice(6)) as { type: string; port?: number })
    expect(events.at(-1)).toEqual({ type: 'moved', port: 41234 })
    await expect(fetch(running.url)).rejects.toThrow()
    // Its host's own stop afterwards is the same stop, not a second one that fails.
    await running.close()
  })

  it('sends the first keep-alive the moment a stream opens, not a period later', async () => {
    const server = await started()
    const base = server.url.slice(0, server.url.indexOf('?'))
    const raw = await fetch(`${base}events?t=${server.token}&id=a&cols=100&rows=40`)
    const reader = raw.body!.getReader()
    const first = new TextDecoder().decode((await reader.read()).value)
    await reader.cancel()
    expect(first.startsWith('data: {"type":"alive"}')).toBe(true)
  })

  it('sends each page a keep-alive event while nothing else happens', async () => {
    running = await startServer({ root: scratch(), pluginRoot: fixturePluginRoot(), dataDir: scratch(), port: 0, aliveMs: 20 })
    await running.ready
    const base = running.url.slice(0, running.url.indexOf('?'))
    const raw = await fetch(`${base}events?t=${running.token}&id=a&cols=100&rows=40`)
    const reader = raw.body!.getReader()
    let text = ''
    // Two keep-alives, as events a page sees, not comments it never does.
    while (text.split('data: {"type":"alive"}').length < 3) text += new TextDecoder().decode((await reader.read()).value)
    await reader.cancel()
  })

  describe('on the port of the holder it replaced', { timeout: 15_000 }, () => {
    /** A process standing for the replaced holder: alive until `end`. */
    const holderProcess = () => {
      const child = spawnFixture('sleep', ['30'])
      return { pid: child.pid!, end: () => new Promise<void>(resolve => child.once('exit', () => resolve()).kill('SIGKILL')) }
    }
    /** Something on a free port: silent (it accepts and never answers, as a suspended pane), or another program's page. */
    const listener = async (kind: 'silent' | 'app') => {
      // Each records what it was sent: no probe of a port not yet proven carries the token.
      const sent: string[] = []
      const server = kind === 'silent' ? createNetServer(socket => void socket.on('data', chunk => sent.push(String(chunk)))) : createHttpServer((req, res) => void (sent.push(`${req.url} ${JSON.stringify(req.headers)}`), res.writeHead(200).end('it works')))
      const sockets = new Set<Socket>()
      server.on('connection', (socket: Socket) => void sockets.add(socket))
      await new Promise<void>(resolve => server.listen(0, '127.0.0.1', resolve))
      const port = (server.address() as { port: number }).port
      // Its connections go with it: a close otherwise waits on those a probe left open.
      return { port, sent, close: () => new Promise<void>(resolve => (server.close(() => resolve()), sockets.forEach(socket => socket.destroy()))) }
    }
    const pointing = async (former: { pid: number; port: number }, formerGraceMs?: number) => {
      running = await startServer({ root: scratch(), pluginRoot: fixturePluginRoot(), dataDir: scratch(), port: 0, headPollMs: 20, former, ...(formerGraceMs === undefined ? {} : { formerGraceMs }) })
      await running.ready
      return running
    }
    const at = (port: number, path: string) => fetch(`http://127.0.0.1:${port}${path}`, { redirect: 'manual' })
    const answers = (port: number) => at(port, '/').then(() => true, () => false)
    const ticks = (n: number) => new Promise(resolve => setTimeout(resolve, 20 * n))

    it('takes it once the live, silent holder lets it go, with only directions there, and lets it go with the server', async () => {
      const holder = holderProcess()
      const held = await listener('silent')
      const server = await pointing({ pid: holder.pid, port: held.port })
      // Tried on each tick while the holder has it, and taken once it is free; what it asked the holder had no token.
      await ticks(10)
      expect(held.sent.length).toBeGreaterThan(0)
      expect(held.sent.join('')).not.toContain(server.token)
      await held.close()
      await vi.waitFor(async () => expect((await at(held.port, `/events?t=${server.token}&id=a`)).status).toBe(200), { timeout: 5_000 })
      // A tab's stream is told where the pane is, as a connected one is when its server steps down.
      expect(await (await at(held.port, `/events?t=${server.token}&id=a`)).text()).toBe(`data: ${JSON.stringify({ type: 'moved', port: server.port })}\n\n`)
      // A tab that loads again is sent there, its address kept but for the port.
      const page = await at(held.port, `/?t=${server.token}`)
      expect(page.status).toBe(307)
      expect(page.headers.get('location')).toBe(`http://127.0.0.1:${server.port}/?t=${server.token}`)
      // The same token rule, and nothing that a start could take for a pane server.
      const refused = await at(held.port, '/events')
      expect(refused.status).toBe(403)
      expect(refused.headers.has(PANE_HEADER)).toBe(false)
      // It proves itself to a page that comes back there, as directions, which no start takes for the pane.
      const asked = await fetch(`http://127.0.0.1:${held.port}/`, { headers: { [CHALLENGE_HEADER]: 'ab'.repeat(16) } })
      await asked.body?.cancel()
      expect(proves(asked.headers.get(PROOF_HEADER), server.token, 'ab'.repeat(16), held.port, 'directions')).toBe(true)
      expect(proves(asked.headers.get(PROOF_HEADER), server.token, 'ab'.repeat(16), held.port)).toBe(false)
      expect(page.headers.has(PANE_HEADER)).toBe(false)
      await server.close()
      expect(await answers(held.port)).toBe(false)
      await holder.end()
    })

    it('leaves the port to another program that holds it, also after that program restarts', async () => {
      const holder = holderProcess()
      const app = await listener('app')
      const server = await pointing({ pid: holder.pid, port: app.port })
      await ticks(50)
      expect(app.sent.length).toBeGreaterThan(0)
      expect(app.sent.join('')).not.toContain(server.token)
      // The program restarts: the port is free for a moment, and stays the program's to take again.
      await app.close()
      await ticks(50)
      expect(await answers(app.port)).toBe(false)
      await holder.end()
    })

    it('gives the port up at once once its holder ended and something else listens there', async () => {
      const holder = holderProcess()
      const other = await listener('silent')
      // Ended before the server looks: whatever listens there now is not the holder's.
      await holder.end()
      await pointing({ pid: holder.pid, port: other.port })
      await ticks(50)
      await other.close()
      await ticks(50)
      expect(await answers(other.port)).toBe(false)
    })

    it('stops trying once the grace after its holder ended is over', async () => {
      // Held without listening, as a port being let go: a try fails, and nothing answers to tell whose it is.
      const bound = spawnFixture('python3', ['-c', 'import socket,sys,time; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1], flush=True); time.sleep(30)'])
      const port = await new Promise<number>(resolve => bound.stdout.once('data', (chunk: Buffer) => resolve(Number(String(chunk).trim()))))
      const holder = holderProcess()
      await holder.end()
      await pointing({ pid: holder.pid, port }, 200)
      await ticks(50)
      await new Promise<void>(resolve => bound.once('exit', () => resolve()).kill('SIGKILL'))
      await ticks(50)
      expect(await answers(port)).toBe(false)
    })

    it('takes it within the grace when its holder ended a moment ago and the port is free', async () => {
      const holder = holderProcess()
      const was = await listener('silent')
      await was.close()
      await holder.end()
      const server = await pointing({ pid: holder.pid, port: was.port })
      await vi.waitFor(async () => expect((await at(was.port, `/events?t=${server.token}&id=a`)).status).toBe(200), { timeout: 5_000 })
    })
  })

  it("proves it is this project's pane to a probe without the token, and tells nothing of the token", async () => {
    const server = await started()
    const challenge = 'c0ffee'.repeat(6)
    const res = await fetch(`http://127.0.0.1:${server.port}/`, { headers: { [CHALLENGE_HEADER]: challenge } })
    const body = await res.text()
    expect(res.status).toBe(403)
    const proof = res.headers.get(PROOF_HEADER)
    expect(proves(proof, server.token, challenge, server.port)).toBe(true)
    // Bound to its port and to this challenge: no use on another port, or for another challenge.
    expect(proves(proof, server.token, challenge, server.port + 1)).toBe(false)
    expect(proves(proof, server.token, 'beef'.repeat(8), server.port)).toBe(false)
    expect(`${body} ${JSON.stringify([...res.headers])}`).not.toContain(server.token)
    // A request with no challenge, or one that is not a challenge, gets no proof.
    for (const headers of [{}, { [CHALLENGE_HEADER]: 'not hex' }]) {
      const plain = await fetch(`http://127.0.0.1:${server.port}/`, { headers })
      await plain.body?.cancel()
      expect(plain.headers.has(PROOF_HEADER)).toBe(false)
    }
  })

  it('says it is a pane server in every answer, the refused ones too', async () => {
    const server = await started()
    const base = server.url.slice(0, server.url.indexOf('?'))
    for (const url of [server.url, base, `${base}nowhere?t=${server.token}`]) {
      const res = await fetch(url)
      await res.body?.cancel()
      expect(res.headers.get(PANE_HEADER)).toBe('1')
    }
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
    expect(mod.navigation.editor).toBe('zed {file}:{line}')
    expect(mod.session.host).toBe('web')
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
