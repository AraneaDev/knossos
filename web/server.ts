/**
 * The web host's server: one project's pane, served on 127.0.0.1 to the
 * pages that hold its token. A page opens `GET /events` (server-sent events:
 * its first event is the current frame, then one per change) and sends what
 * the person does as `POST /input`, one JSON object a request:
 *
 *   { id, kind: 'press', press }            a press, by its id
 *   { id, kind: 'type' | 'submit', field, value }
 *   { id, kind: 'focus', element }          the focus moved onto an element
 *   { id, kind: 'wheel', row, column, by }  the wheel, over a cell of the page's rows
 *   { id, kind: 'resize', cols, rows }
 *   { id, kind: 'theme', theme }            'dark' or 'light'
 *
 * `id` names the page (one per browser tab). Every request carries the
 * token, as `t` in the query (the page, its files, the event stream) or in
 * the `X-Knossos-Token` header (the inputs); never in a cookie, which any
 * other server on 127.0.0.1 would share. An input is JSON (so a page of
 * another origin cannot send one without asking first) and, when the
 * browser names its origin, comes from this server's own.
 */
import { stripTypeScriptTypes } from 'node:module'
import { createHash, randomBytes, timingSafeEqual } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { createServer } from 'node:http'
import type { IncomingMessage, ServerResponse } from 'node:http'
import { connect, createServer as createNetServer } from 'node:net'
import { dirname, extname, join, normalize, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

import type { WebTheme } from '../hooks/lib/themes'
import { handlersOf } from '../hooks/mod/actions'
import { focusLanded, focusMoved, wheelScrolled } from '../hooks/mod/navigate-actions'
import { stopClocks } from '../hooks/mod/motion'
import { endDeliberately } from '../hooks/mod/start'
import { endWatcher } from '../hooks/mod/port'
import { startUp } from '../hooks/mod/session'
import { reset } from '../hooks/mod/state'
import { followHead } from '../hooks/mod/watcher'
import { Hub } from './hub'
import type { PageEvent, Size } from './hub'
import { CHALLENGE_HEADER, PANE_HEADER, PROOF_HEADER, addressOf, alive, probe, proofOf } from './lock'
import type { Replaced } from './lock'
import { webPort } from './port'

/** How often each page's stream carries a keep-alive: the page's own ALIVE_MS (web/public/app.mjs), which a page counts on to tell a silent stream. */
export const ALIVE_MS = 10_000
/**
 * How long after the former holder ended its port is still tried for {@link directions}. A tab of it that still
 * watches comes back at the latest after its silence check (up to 40 s) and one wait of its backoff (5 s at most),
 * and the server tries the port at its next tick (5 s): 50 s, with room for a loaded machine. A tab that comes back
 * later than that reloads the address knossos printed.
 */
const FORMER_GRACE_MS = 60_000
/** How often the checkout's commit is read again while a page is connected, beside every word from the watcher. */
const HEAD_POLL_MS = 5_000
/** The largest input a page sends. */
const MAX_BODY = 64 * 1024
const PUBLIC = join(dirname(fileURLToPath(import.meta.url)), 'public')
const PLACE = join(dirname(fileURLToPath(import.meta.url)), '..', 'hooks', 'lib', 'place.ts')
const TYPES: Record<string, string> = { '.html': 'text/html', '.mjs': 'text/javascript', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.webmanifest': 'application/manifest+json', '.json': 'application/json', '.png': 'image/png' }

/** What a request without the right token is told: the token changes only when its file goes, so the printed address is the one to use. */
export const REFUSED = 'This address is out of date or incomplete. Open the address knossos web printed in its terminal.\n'

/** `port` when it is free on 127.0.0.1; when it is taken, a free one if `movable`, else the error. 0 asks for any. */
async function portFor(port: number, movable: boolean): Promise<number> {
  if (port === 0) return 0
  try {
    await new Promise<void>((resolve, reject) => {
      const probe = createNetServer()
      probe.once('error', reject)
      probe.listen(port, '127.0.0.1', () => probe.close(() => resolve()))
    })
    return port
  } catch (error) {
    if (movable && (error as NodeJS.ErrnoException).code === 'EADDRINUSE') return 0
    throw error
  }
}

/** The app manifest of the server at `origin`: one app (`id`), opened at the address that carries the token. */
const manifestOf = (origin: string, token: string): string =>
  JSON.stringify({
    id: '/',
    name: 'Knossos',
    short_name: 'Knossos',
    description: 'The Knossos pane for one project, served by knossos web.',
    display: 'standalone',
    start_url: `${origin}/?t=${token}`,
    scope: `${origin}/`,
    icons: [{ src: `${origin}/static/icon.svg?t=${token}`, sizes: 'any', type: 'image/svg+xml' }],
  })

/** An error's message, for the log. */
const messageOf = (error: unknown): string => (error instanceof Error ? error.message : String(error))

const themeOf = (value: unknown): WebTheme => (value === 'light' ? 'light' : 'dark')
/** A page's size in cells, kept to what the layout can draw. */
const sizeOf = (cols: unknown, rows: unknown): Size => ({ columns: clamp(Number(cols), 20, 500, 100), rows: clamp(Number(rows), 5, 300, 40) })
const clamp = (n: number, low: number, high: number, otherwise: number) => (Number.isFinite(n) ? Math.min(high, Math.max(low, Math.round(n))) : otherwise)

/** `origin` when it is a page on this machine's loopback (`http://127.0.0.1:<port>` or `http://localhost:<port>`), else null. */
function loopbackOrigin(origin: string | undefined): string | null {
  return typeof origin === 'string' && /^http:\/\/(?:127\.0\.0\.1|localhost):\d{1,5}$/.test(origin) ? origin : null
}

/** Whether `given` is the token, compared in constant time. */
function tokenMatches(given: string | null | undefined, token: string): boolean {
  if (typeof given !== 'string' || given.length !== token.length) return false
  return timingSafeEqual(Buffer.from(given), Buffer.from(token))
}

/** The token a request carries: in its query, else in its header. */
function tokenOf(req: IncomingMessage, url: URL): string | null {
  const header = req.headers['x-knossos-token']
  return url.searchParams.get('t') ?? (typeof header === 'string' ? header : null)
}

/** Why an input is refused (its status), or null: it must be JSON, and from this server's own origin when the browser names one. */
function refusal(req: IncomingMessage, origin: string): number | null {
  if (!/^application\/json\b/i.test(req.headers['content-type'] ?? '')) return 415
  const from = req.headers.origin
  return from === undefined || from === origin ? null : 403
}

/** A request's body as JSON; null when it is too large or not JSON. */
function bodyOf(req: IncomingMessage): Promise<Record<string, unknown> | null> {
  return new Promise(resolve => {
    let body = ''
    req.setEncoding('utf8')
    req.on('data', (chunk: string) => {
      body += chunk
      if (body.length > MAX_BODY) {
        resolve(null)
        req.destroy()
      }
    })
    req.on('end', () => {
      try {
        const parsed: unknown = JSON.parse(body)
        resolve(parsed !== null && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : null)
      } catch {
        resolve(null)
      }
    })
  })
}

/**
 * Serves the pane of the project at `root` (the plugin's wrapper from
 * `pluginRoot`, knossos's data in `dataDir`) on 127.0.0.1, on `port` or a
 * free one (on a free one too when `port` is taken and it is `movable`),
 * with `token` or a new one. `ready` settles once start-up has read what it
 * reads first.
 */
export async function startServer(o: {
  root: string
  pluginRoot: string
  dataDir: string
  port?: number
  movable?: boolean
  token?: string
  /** The command files open in (see `editorCommand`); VS Code's when null. */
  editor?: string | null
  /** How often each page's stream carries a keep-alive ({@link ALIVE_MS} by default). */
  aliveMs?: number
  /** How often the checkout's commit is read while a page is connected ({@link HEAD_POLL_MS} by default). */
  headPollMs?: number
  /** Where the server's log lines go (`console.error` when absent). */
  log?: (text: string) => void
  /**
   * The port of another pane server that took the project's lock over, or null while the lock is this one's;
   * asked on the head poll's tick. Once it names one, the server tells its pages where it went and closes itself.
   */
  holder?: () => Promise<number | null>
  /**
   * The holder this server's claim replaced while it was alive and silent (see `Claim.replaced`). Once its port is
   * free the server listens there too, with only directions here: a tab of that server that was away when it
   * stepped down (asleep, or between tries) finds its way when it comes back. See {@link FORMER_GRACE_MS}.
   */
  former?: Replaced | null
  /** How long after the former holder ended its port is still tried ({@link FORMER_GRACE_MS} by default). */
  formerGraceMs?: number
}): Promise<{ url: string; token: string; port: number; ready: Promise<void>; stepped: Promise<void>; close(): Promise<void> }> {
  // Before anything starts: a port it cannot have stops it here, with nothing to undo.
  const wanted = await portFor(o.port ?? 0, o.movable === true)
  reset({ watch: true, ...(o.editor == null ? {} : { editor: o.editor }) }, 'web')
  const token = o.token ?? randomBytes(24).toString('hex')
  const hub = new Hub()
  let following = false
  // Only for a page to see: with none connected nothing is read, and the next page to connect reads it at once.
  const follow = () => {
    if (following || !hub.connected) return
    following = true
    void followHead(io)
      .catch((error: unknown) => io.ui.log(`knossos web: reading the checkout's commit failed: ${messageOf(error)}`))
      .finally(() => (following = false))
  }
  const storeFile = join(o.dataDir, 'web', `${createHash('sha256').update(o.root).digest('hex').slice(0, 16)}.json`)
  const io = webPort({ root: o.root, pluginRoot: o.pluginRoot, storeFile, hub, env: { ...process.env, KNOSSOS_DATA_DIR: o.dataDir, KNOSSOS_ROOTS_FILE: process.env.KNOSSOS_ROOTS_FILE || join(o.dataDir, 'roots.json') }, heard: follow, ...(o.log === undefined ? {} : { log: o.log }) })
  hub.attach(io)
  const act = handlersOf(io)
  const ready = startUp(io, 'deliberate').catch((error: unknown) => io.ui.log(`knossos web: start-up failed: ${messageOf(error)}`))
  /**
   * Settles once the server closed itself because another took the project's lock. Its pages were told the other's
   * port first: a browser page moves there, and a terminal pane, whose host learns of it here, takes the project's
   * usual take-over path to the lock's server.
   */
  let stepDown = () => undefined as void
  const stepped = new Promise<void>(resolve => (stepDown = resolve))
  /** The server's stop once asked for: see {@link close}. */
  let closing: Promise<void> | null = null
  /** Whether the server listens yet: its port is known from then on. */
  let listening = false
  /** What answers on the former port once it has it, whether it is trying for it, and whether it gave up for good. */
  let pointer: ReturnType<typeof createServer> | null = null
  let pointing = false
  let pointDone = false
  /** When the former holder was first seen gone, by the monotonic clock. */
  let formerGone: number | null = null
  /** Whether the tick is asking the lock: the ask probes the other server, and a slow one must not pile up asks. */
  let asking = false
  // The poll's tick also asks whether the lock is still this server's: a lock another server holds leaves this one serving no one's project.
  const headPoll = setInterval(() => {
    follow()
    pointFrom()
    if (o.holder === undefined || asking) return
    asking = true
    void o
      .holder()
      .then(port => {
        if (port === null || closing !== null) return
        clearInterval(headPoll)
        hub.send({ type: 'moved', port })
        // Its start-up is let finish first, or it would go on after the close and start a watcher for a server that no longer runs;
        // that wait has no bound of its own, as each step of the start-up is a wrapper call with its own timeout.
        // Input a page posts meanwhile reaches this server and is lost, as in any take-over: a page does not send it twice.
        return ready.then(close).then(stepDown, stepDown)
      })
      .catch(() => undefined)
      .finally(() => (asking = false))
  }, o.headPollMs ?? HEAD_POLL_MS)
  const streams = new Set<ServerResponse>()

  /** One input from a page, handed to the pane's own handlers: false for a kind there is none of. A wheel answers whether the pane took it. */
  async function input(body: Record<string, unknown>): Promise<boolean | { scrolled: boolean }> {
    const id = String(body.id ?? '')
    const text = (field: string) => String(body[field] ?? '')
    switch (body.kind) {
      case 'press':
        act.press(text('press'))
        return true
      case 'type':
        act.type(text('field'), text('value'))
        return true
      case 'submit':
        act.submit(text('field'), text('value'))
        return true
      case 'focus': {
        const moved = await focusMoved(io, body.element)
        if (moved !== null && 'refuse' in moved) return true
        const element = moved === null ? text('element') : moved.to
        // Redirected: the page moves its focus where the pane says.
        if (moved !== null) hub.send({ type: 'focus', key: element })
        await focusLanded(io, element)
        return true
      }
      case 'wheel':
        // Not taken (over no list, or at its end): the page scrolls itself.
        return { scrolled: await wheelScrolled(io, hub.rowsOf(id), 0, { row: Number(body.row), column: Number(body.column) }, Number(body.by)) }
      case 'resize':
        hub.resize(id, sizeOf(body.cols, body.rows))
        return true
      case 'theme': {
        const theme = themeOf(body.theme)
        hub.setTheme(id, theme)
        await io.state.theme.update(() => theme)
        return true
      }
      default:
        return false
    }
  }

  /** The page itself, or one of its files; nothing outside its directory. */
  async function file(res: ServerResponse, path: string): Promise<void> {
    let named: string
    try {
      named = decodeURIComponent(path)
    } catch {
      return void res.writeHead(404).end()
    }
    // Additive browser metadata; release automation already maintains this file.
    if (named === 'version.txt') {
      const version = await readFile(join(PUBLIC, '..', '..', 'version.txt'), 'utf8').catch(() => null)
      if (version === null) return void res.writeHead(404).end()
      return void res.writeHead(200, { 'content-type': 'text/plain; charset=utf-8', 'cache-control': 'no-store' }).end(version)
    }
    // The hover card's rule is the hooks module's own file, as JavaScript: made here, so no copy of it exists.
    if (named === 'place.mjs') {
      let script: string
      try {
        script = stripTypeScriptTypes(await readFile(PLACE, 'utf8'))
      } catch (error) {
        io.ui.log(`knossos web: making the placement rule for the page failed: ${messageOf(error)}`)
        return void res.writeHead(500).end()
      }
      return void res.writeHead(200, { 'content-type': TYPES['.mjs']!, 'cache-control': 'no-store' }).end(script)
    }
    const at = normalize(join(PUBLIC, named))
    if (!at.startsWith(PUBLIC + sep)) return void res.writeHead(404).end()
    const content = await readFile(at).catch(() => null)
    if (content === null) return void res.writeHead(404).end()
    res.writeHead(200, { 'content-type': TYPES[extname(at)] ?? 'application/octet-stream', 'cache-control': 'no-store' })
    res.end(content)
  }

  function events(res: ServerResponse, url: URL): void {
    const id = url.searchParams.get('id') ?? 'page'
    res.writeHead(200, { 'content-type': 'text/event-stream', 'cache-control': 'no-store', connection: 'keep-alive' })
    streams.add(res)
    // A frame still being laid out when `close` ended the stream is dropped, not written after the end.
    const write = (text: string) => void (res.writableEnded || res.destroyed || res.write(text))
    const send = (event: PageEvent) => write(`data: ${JSON.stringify(event)}\n\n`)
    // An event, not a comment: a page sees only events, and takes a stream that stays silent for a while as lost.
    // The first goes at once, so a page knows from its first moment that this server sends them: a stream whose
    // server is suspended in its first seconds is noticed too.
    send({ type: 'alive' })
    const alive = setInterval(() => send({ type: 'alive' }), o.aliveMs ?? ALIVE_MS)
    res.on('close', () => {
      clearInterval(alive)
      streams.delete(res)
      hub.disconnect(id, send)
    })
    hub.connect(id, sizeOf(url.searchParams.get('cols'), url.searchParams.get('rows')), send, themeOf(url.searchParams.get('theme')))
    follow()
  }

  let origin = ''
  const server = createServer((req, res) => {
    const url = new URL(req.url ?? '/', 'http://127.0.0.1')
    // The URLs carry the token: no page they lead to is told where it came from.
    res.setHeader('referrer-policy', 'no-referrer')
    // What tells a probe that a pane server answers here, and not another program on a port used again.
    res.setHeader(PANE_HEADER, '1')
    if (!tokenMatches(tokenOf(req, url), token)) return void refuse(req, res, port, 'pane')
    if (req.method === 'GET' && url.pathname === '/') return void file(res, 'index.html')
    if (req.method === 'GET' && url.pathname === '/manifest.webmanifest') {
      return void res.writeHead(200, { 'content-type': 'application/manifest+json', 'cache-control': 'no-store' }).end(manifestOf(origin, token))
    }
    if (req.method === 'GET' && url.pathname.startsWith('/static/')) return void file(res, url.pathname.slice('/static/'.length))
    if (req.method === 'GET' && url.pathname === '/events') return events(res, url)
    if (req.method === 'POST' && url.pathname === '/input') {
      const refused = refusal(req, origin)
      if (refused !== null) return void res.writeHead(refused).end()
      return void bodyOf(req).then(async body => {
        if (body === null) return void res.writeHead(400).end()
        const done = await input(body).catch((error: unknown) => {
          io.ui.log(`knossos web: an input (${String(body.kind)}) failed: ${messageOf(error)}`)
          return null
        })
        if (done === null) return void res.writeHead(500).end()
        if (typeof done === 'object') return void res.writeHead(200, { 'content-type': 'application/json' }).end(JSON.stringify(done))
        res.writeHead(done ? 204 : 400).end()
      })
    }
    res.writeHead(404).end()
  })
  await new Promise<void>((resolve, reject) => {
    server.once('error', reject)
    server.listen(wanted, '127.0.0.1', () => resolve())
  }).catch((error: unknown) => {
    // Nothing listens: nothing is left polling either.
    clearInterval(headPoll)
    throw error
  })
  const address = server.address()
  const port = typeof address === 'object' && address !== null ? address.port : 0
  listening = true
  origin = `http://127.0.0.1:${port}`

  /**
   * Listens on the former holder's port with {@link directions}, once it is free, tried on each tick: while that
   * holder still runs (stopped, or stepping down, it still holds its port), and for {@link FORMER_GRACE_MS} after
   * it ended. A port that something else holds is never taken from it: a failed try looks once at what listens
   * there, and anything but the former holder's own silence or pane ends the trying for good, as does the grace.
   */
  function pointFrom(): void {
    const former = o.former ?? null
    if (!listening || former === null || former.port === port || pointer !== null || pointing || pointDone || closing !== null) return
    if (!alive(former.pid)) {
      formerGone ??= performance.now()
      if (performance.now() - formerGone > (o.formerGraceMs ?? FORMER_GRACE_MS)) return void (pointDone = true)
    }
    pointing = true
    const there = createServer((req, res) => directions(req, res))
    there.once('error', () => {
      void heldBy(former).then(other => {
        if (other) pointDone = true
        pointing = false
      })
    })
    there.listen(former.port, '127.0.0.1', () => {
      pointing = false
      if (closing !== null) return void there.close()
      pointer = there
    })
  }
  /**
   * Whether the former port, which a try could not have, is held by another program: something accepts there, and
   * it is not the former holder, which has ended, or answers neither in silence (stopped) nor as a pane server.
   * Nothing accepting is a moment of a port being let go, and is tried again.
   */
  async function heldBy(former: Replaced): Promise<boolean> {
    const accepts = () =>
      new Promise<boolean>(resolve => {
        const socket = connect(former.port, '127.0.0.1')
        socket.setTimeout(1_000, () => socket.destroy())
        socket.once('connect', () => {
          socket.destroy()
          resolve(true)
        })
        socket.once('error', () => resolve(false))
        socket.once('close', () => resolve(false))
      })
    if (!(await accepts())) return false
    if (!alive(former.pid)) return true
    // Another project's pane server there holds it as surely as any other program.
    const answer = await probe(former.port, token, 1_000)
    if (answer !== 'other' && answer !== 'foreign') return false
    // An answer that is not a pane's, or a connection cut while the holder let its port go: only the first is
    // another program's, and it still accepts.
    return accepts()
  }
  /**
   * The former port's only answers, under the same token rule: a page's event stream is told where the pane is
   * now, as a connected page is when a server steps down, and anything else is sent there. Nothing here says it is
   * a pane server, so no start takes it for one.
   */
  function directions(req: IncomingMessage, res: ServerResponse): void {
    const url = new URL(req.url ?? '/', 'http://127.0.0.1')
    res.setHeader('referrer-policy', 'no-referrer')
    if (!tokenMatches(tokenOf(req, url), token)) return void refuse(req, res, o.former!.port, 'directions')
    if (url.pathname === '/events') {
      return void res.writeHead(200, { 'content-type': 'text/event-stream', 'cache-control': 'no-store' }).end(`data: ${JSON.stringify({ type: 'moved', port })}\n\n`)
    }
    res.writeHead(307, { location: `http://127.0.0.1:${port}${url.pathname}${url.search}`, 'cache-control': 'no-store' }).end()
  }

  /**
   * The answer to a request without the token: refused, with the proof of a probe's challenge when it brings one
   * (see `proofOf`), for the port it reached. A probe never sends the token; this is how it knows a pane server of
   * this project, and nothing in the answer tells anyone the token.
   */
  function refuse(req: IncomingMessage, res: ServerResponse, on: number, kind: 'pane' | 'directions'): void {
    // A page of this pane on another port (one about to move here) may ask the challenge across origins; a page of
    // any other site may not, and learns nothing, not even that a pane server listens here.
    const asker = loopbackOrigin(req.headers.origin)
    if (asker !== null) {
      res.setHeader('access-control-allow-origin', asker)
      res.setHeader('access-control-expose-headers', PROOF_HEADER)
      res.setHeader('vary', 'origin')
      if (req.method === 'OPTIONS') {
        res.setHeader('access-control-allow-headers', CHALLENGE_HEADER)
        res.setHeader('access-control-allow-methods', 'GET')
        return void res.writeHead(204).end()
      }
    }
    const challenge = req.headers[CHALLENGE_HEADER]
    if (typeof challenge === 'string' && /^[0-9a-f]{16,128}$/.test(challenge)) res.setHeader(PROOF_HEADER, proofOf(token, challenge, on, kind))
    res.writeHead(403, { 'content-type': 'text/plain; charset=utf-8' }).end(REFUSED)
  }

  /** Stops the server; asked again (a step-down, then the host's own stop), it is the same stop. */
  function close(): Promise<void> {
    closing ??= (async () => {
      clearInterval(headPoll)
      pointer?.close()
      pointer?.closeAllConnections()
      stopClocks()
      // A claim of the folder still running would go on to a first scan for a server that is gone.
      endDeliberately()
      await endWatcher(io).catch(() => undefined)
      // A scan or any other run still under way is interrupted, as Ctrl-C would, and none is started after
      // (after the watcher's end, which runs a kill of its own).
      io.end()
      for (const res of streams) res.end()
      await new Promise<void>(resolve => server.close(() => resolve()))
    })()
    return closing
  }

  return { url: addressOf(port, token), token, port, ready, stepped, close }
}
