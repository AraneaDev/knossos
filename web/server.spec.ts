import { request } from 'node:http'
import type { IncomingMessage } from 'node:http'
import { networkInterfaces } from 'node:os'
import { afterEach, describe, expect, it } from 'vitest'
import { activeTab, fixturePluginRoot, frameText, scratch } from './fixtures/helpers'
import type { Frame } from './hub'
import { startServer } from './server'

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
function get(url: string): Promise<{ status: number; body: string }> {
  return new Promise((resolve, reject) => {
    request(url, res => {
      let body = ''
      res.setEncoding('utf8')
      res.on('data', chunk => (body += chunk))
      res.on('end', () => resolve({ status: res.statusCode ?? 0, body }))
    })
      .on('error', reject)
      .end()
  })
}

/** A POST of `body` as JSON; its status. */
function post(url: string, body: unknown): Promise<number> {
  return new Promise((resolve, reject) => {
    const req = request(url, { method: 'POST', headers: { 'content-type': 'application/json' } }, res => {
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
  it('refuses a request without the token', async () => {
    const { url, token } = await started()
    const base = url.slice(0, url.indexOf('?'))
    expect((await get(`${base}events?id=a&cols=80&rows=30`)).status).toBe(403)
    expect((await get(`${base}events?t=wrong&id=a&cols=80&rows=30`)).status).toBe(403)
    expect(await post(`${base}input`, { id: 'a', kind: 'press', press: 'tab:hubs' })).toBe(403)
    expect((await get(`${base}?t=${token}`)).status).not.toBe(403)
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
