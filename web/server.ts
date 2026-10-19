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
import { createServer as createNetServer } from 'node:net'
import { dirname, extname, join, normalize, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

import type { WebTheme } from '../hooks/lib/themes'
import { focusLanded, focusMoved, handlersOf, wheelScrolled } from '../hooks/mod/actions'
import { endWatcher } from '../hooks/mod/port'
import { startUp } from '../hooks/mod/session'
import { mod, reset } from '../hooks/mod/state'
import { followHead } from '../hooks/mod/watcher'
import { Hub } from './hub'
import type { PageEvent, Size } from './hub'
import { addressOf } from './lock'
import { webPort } from './port'

/** How often the checkout's commit is read again while a page is connected, beside every word from the watcher. */
const HEAD_POLL_MS = 5_000
/** How often an idle event stream says it is still there, so a proxy or a sleeping laptop notices a dead one. */
const KEEPALIVE_MS = 20_000
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
  /** How often the checkout's commit is read while a page is connected ({@link HEAD_POLL_MS} by default). */
  headPollMs?: number
}): Promise<{ url: string; token: string; port: number; ready: Promise<void>; close(): Promise<void> }> {
  // Before anything starts: a port it cannot have stops it here, with nothing to undo.
  const wanted = await portFor(o.port ?? 0, o.movable === true)
  reset({ watch: true, ...(o.editor == null ? {} : { editor: o.editor }) })
  mod.host = 'web'
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
  const io = webPort({ root: o.root, pluginRoot: o.pluginRoot, storeFile, hub, env: { ...process.env, KNOSSOS_DATA_DIR: o.dataDir }, heard: follow })
  hub.attach(io)
  const act = handlersOf(io)
  const ready = startUp(io, false).catch((error: unknown) => io.ui.log(`knossos web: start-up failed: ${messageOf(error)}`))
  const headPoll = setInterval(follow, o.headPollMs ?? HEAD_POLL_MS)
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
    const send = (event: PageEvent) => void res.write(`data: ${JSON.stringify(event)}\n\n`)
    const alive = setInterval(() => res.write(': alive\n\n'), KEEPALIVE_MS)
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
    if (!tokenMatches(tokenOf(req, url), token)) return void res.writeHead(403, { 'content-type': 'text/plain; charset=utf-8' }).end(REFUSED)
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
  })
  const address = server.address()
  const port = typeof address === 'object' && address !== null ? address.port : 0
  origin = `http://127.0.0.1:${port}`

  return {
    url: addressOf(port, token),
    token,
    port,
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
