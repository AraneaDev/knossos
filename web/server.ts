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
 * `id` names the page (one per browser tab). The token comes as `t` in the
 * query or, after the page itself was loaded with it, as a cookie, so the
 * page's own files load without it in their URLs.
 */
import { createHash, randomBytes, timingSafeEqual } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { createServer } from 'node:http'
import type { IncomingMessage, ServerResponse } from 'node:http'
import { dirname, extname, join, normalize } from 'node:path'
import { fileURLToPath } from 'node:url'

import type { WebTheme } from '../hooks/lib/themes'
import { focusLanded, focusMoved, handlersOf, wheelScrolled } from '../hooks/mod/actions'
import { endWatcher } from '../hooks/mod/port'
import { startUp } from '../hooks/mod/session'
import { mod, reset } from '../hooks/mod/state'
import { followHead } from '../hooks/mod/watcher'
import { Hub } from './hub'
import type { PageEvent, Size } from './hub'
import { webPort } from './port'

/** How often the checkout's commit is read again, beside every word from the watcher. */
const HEAD_POLL_MS = 5_000
/** How often an idle event stream says it is still there, so a proxy or a sleeping laptop notices a dead one. */
const KEEPALIVE_MS = 20_000
/** The largest input a page sends. */
const MAX_BODY = 64 * 1024
const PUBLIC = join(dirname(fileURLToPath(import.meta.url)), 'public')
const TYPES: Record<string, string> = { '.html': 'text/html', '.mjs': 'text/javascript', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.webmanifest': 'application/manifest+json', '.json': 'application/json', '.png': 'image/png' }

const themeOf = (value: unknown): WebTheme => (value === 'light' ? 'light' : 'dark')
/** A page's size in cells, kept to what the layout can draw. */
const sizeOf = (cols: unknown, rows: unknown): Size => ({ columns: clamp(Number(cols), 20, 500, 100), rows: clamp(Number(rows), 5, 300, 40) })
const clamp = (n: number, low: number, high: number, otherwise: number) => (Number.isFinite(n) ? Math.min(high, Math.max(low, Math.round(n))) : otherwise)

/** Whether `given` is the token, compared in constant time. */
function tokenMatches(given: string | null | undefined, token: string): boolean {
  if (typeof given !== 'string' || given.length !== token.length) return false
  return timingSafeEqual(Buffer.from(given), Buffer.from(token))
}

/** The token a request carries: in its query, else in its cookie. */
function tokenOf(req: IncomingMessage, url: URL): string | null {
  const cookie = /(?:^|;\s*)knossos=([^;]+)/.exec(req.headers.cookie ?? '')
  return url.searchParams.get('t') ?? cookie?.[1] ?? null
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
 * free one. `ready` settles once start-up has read what it reads first.
 */
export async function startServer(o: { root: string; pluginRoot: string; dataDir: string; port?: number }): Promise<{ url: string; token: string; ready: Promise<void>; close(): Promise<void> }> {
  reset({ watch: true })
  mod.host = 'web'
  const token = randomBytes(24).toString('hex')
  const hub = new Hub()
  let following = false
  const follow = () => {
    if (following) return
    following = true
    void followHead(io)
      .catch(() => false)
      .finally(() => (following = false))
  }
  const storeFile = join(o.dataDir, 'web', `${createHash('sha256').update(o.root).digest('hex').slice(0, 16)}.json`)
  const io = webPort({ root: o.root, pluginRoot: o.pluginRoot, storeFile, hub, env: { ...process.env, KNOSSOS_DATA_DIR: o.dataDir }, heard: follow })
  hub.attach(io)
  const act = handlersOf(io)
  const ready = startUp(io, false).catch(() => undefined)
  const headPoll = setInterval(follow, HEAD_POLL_MS)
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
  async function file(res: ServerResponse, path: string, cookie?: string): Promise<void> {
    const at = normalize(join(PUBLIC, path))
    if (!at.startsWith(PUBLIC + '/')) return void res.writeHead(404).end()
    const content = await readFile(at).catch(() => null)
    if (content === null) return void res.writeHead(404).end()
    res.writeHead(200, { 'content-type': TYPES[extname(at)] ?? 'application/octet-stream', 'cache-control': 'no-store', ...(cookie === undefined ? {} : { 'set-cookie': cookie }) })
    res.end(content)
  }

  function events(res: ServerResponse, url: URL): void {
    const id = url.searchParams.get('id') ?? 'page'
    res.writeHead(200, { 'content-type': 'text/event-stream', 'cache-control': 'no-store', connection: 'keep-alive' })
    streams.add(res)
    const send = (event: PageEvent) => void res.write(`data: ${JSON.stringify(event)}\n\n`)
    const alive = setInterval(() => res.write(': alive\n\n'), KEEPALIVE_MS)
    res.on('close', () => {
      clearInterval(alive)
      streams.delete(res)
      hub.disconnect(id, send)
    })
    hub.connect(id, sizeOf(url.searchParams.get('cols'), url.searchParams.get('rows')), send, themeOf(url.searchParams.get('theme')))
  }

  const server = createServer((req, res) => {
    const url = new URL(req.url ?? '/', 'http://127.0.0.1')
    if (!tokenMatches(tokenOf(req, url), token)) return void res.writeHead(403).end()
    if (req.method === 'GET' && url.pathname === '/') return void file(res, 'index.html', `knossos=${token}; HttpOnly; SameSite=Strict; Path=/`)
    if (req.method === 'GET' && url.pathname.startsWith('/static/')) return void file(res, url.pathname.slice('/static/'.length))
    if (req.method === 'GET' && url.pathname === '/events') return events(res, url)
    if (req.method === 'POST' && url.pathname === '/input') {
      return void bodyOf(req).then(async body => {
        const done = body === null ? false : await input(body).catch(() => false)
        if (typeof done === 'object') return void res.writeHead(200, { 'content-type': 'application/json' }).end(JSON.stringify(done))
        res.writeHead(done ? 204 : 400).end()
      })
    }
    res.writeHead(404).end()
  })
  await new Promise<void>((resolve, reject) => {
    server.once('error', reject)
    server.listen(o.port ?? 0, '127.0.0.1', () => resolve())
  })
  const address = server.address()
  const port = typeof address === 'object' && address !== null ? address.port : 0

  return {
    url: `http://127.0.0.1:${port}/?t=${token}`,
    token,
    ready,
    close: async () => {
      clearInterval(headPoll)
      mod.ticker?.cancel()
      mod.ticker = null
      mod.motionTimer?.cancel()
      mod.motionTimer = null
      await endWatcher(io).catch(() => undefined)
      for (const res of streams) res.end()
      await new Promise<void>(resolve => server.close(() => resolve()))
    },
  }
}
