import { createServer as createHttpServer } from 'node:http'
import { createServer as createNetServer } from 'node:net'
import type { Socket } from 'node:net'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { fixturePluginRoot, scratch, spawnFixture } from './fixtures/helpers'
import { CHALLENGE_HEADER, PANE_HEADER, PROOF_HEADER, proofOf, proves } from './lock'
import { startServer } from './server'

/** The server on the port of the holder its claim replaced: when it takes that port, and when it leaves it be. */
type Running = Awaited<ReturnType<typeof startServer>>
let running: Running | null = null

afterEach(async () => {
  // Start-up writes into the data directory: it is waited for, so nothing lands there once the test has removed it.
  await running?.ready
  await running?.close()
  running = null
})

describe('startServer', () => {
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
    /** Whether this server's directions answer on `port`: any other answer there is another spec's, on a port freed and taken again. */
    const answers = async (port: number) => {
      const token = running?.token ?? ''
      const text = await at(port, `/events?t=${token}&id=x`).then(
        r => r.text(),
        () => '',
      )
      return text.startsWith('data: {"type":"moved"')
    }
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

    it("leaves the port to another project's pane server while its own holder still runs", async () => {
      const holder = holderProcess()
      // A pane server keyed with another token: its proof does not hold for this project.
      let foreignPort = 0
      const foreign = createHttpServer((req, res) => {
        const challenge = String(req.headers[CHALLENGE_HEADER] ?? '')
        res.writeHead(403, { [PANE_HEADER]: '1', [PROOF_HEADER]: proofOf('cd'.repeat(24), challenge, foreignPort) }).end()
      })
      await new Promise<void>(resolve => foreign.listen(0, '127.0.0.1', resolve))
      foreignPort = (foreign.address() as { port: number }).port
      await pointing({ pid: holder.pid, port: foreignPort })
      await ticks(50)
      foreign.closeAllConnections()
      await new Promise<void>(resolve => foreign.close(() => resolve()))
      await ticks(50)
      expect(await answers(foreignPort)).toBe(false)
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
})
