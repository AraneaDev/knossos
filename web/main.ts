/**
 * `knossos web [<dir>] [--port=N] [--no-open] [--editor=<command>]`: serves the pane of the
 * project in `dir` (the working directory by default) in a browser tab, from
 * this checkout, for as long as it runs in the foreground. Ctrl-C, SIGTERM
 * or the terminal closing stop it: the watcher it started ends and its lock
 * is removed. When a server already runs for the project, it says where and
 * opens the browser on that one instead.
 *
 * The address stays the same from one start to the next: the project's
 * token is kept in the data directory, and so is its port, which is used
 * again while it is free (`--port` names one to use, and nothing else).
 * Files open in the editor command `--editor` names, else `KNOSSOS_EDITOR`,
 * else VS Code's `code -g`, as the plugin's `editor` setting does.
 *
 * The pane's data comes through this checkout's plugin wrapper
 * (`hooks/scripts/knossos-run.sh`, or the one under `KNOSSOS_WEB_PLUGIN_ROOT`
 * for the specs) and knossos's data directory (`KNOSSOS_DATA_DIR`, by
 * default `~/.knossos`), as the Claude Code plugin reads them.
 */
import { existsSync, realpathSync, statSync } from 'node:fs'
import { homedir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const NODE_MAJOR = 24
const major = Number(process.versions.node.split('.')[0])
if (major < NODE_MAJOR) {
  process.stderr.write(`knossos web needs Node ${NODE_MAJOR} or newer (found v${process.versions.node}).\n`)
  process.exit(2)
}

/** Ends with `message` on stderr and exit code 2: it could not run. */
function fail(message: string): never {
  process.stderr.write(`knossos web: ${message}\n`)
  process.exit(2)
}

const messageOf = (error: unknown): string => (error instanceof Error ? error.message : String(error))

const CHECKOUT = dirname(dirname(fileURLToPath(import.meta.url)))
// The shared modules import each other without an extension; plain Node needs a resolver for that before loading them.
await import(new URL('../tools/ts-resolve.mjs', import.meta.url).href)
const { argsOf, USAGE } = await import('./args')
const asked = argsOf(process.argv.slice(2))
if ('error' in asked) {
  process.stderr.write(`knossos web: ${asked.error}\n${USAGE}\n`)
  process.exit(2)
}
const given = resolve(asked.dir ?? '.')
if (!existsSync(given) || !statSync(given).isDirectory()) fail(`${given} is not a directory`)
const root = realpathSync(given)
const dataDir = process.env.KNOSSOS_DATA_DIR || join(homedir(), '.knossos')
const pluginRoot = process.env.KNOSSOS_WEB_PLUGIN_ROOT || CHECKOUT
// The wrapper runs this checkout's knossos unless told otherwise, as an installed plugin runs the checkout it came from.
if (!process.env.KNOSSOS_BIN && existsSync(join(CHECKOUT, 'bin/knossos'))) process.env.KNOSSOS_BIN = join(CHECKOUT, 'bin/knossos')
const editor = asked.editor ?? (process.env.KNOSSOS_EDITOR || null)

const { claim, identity } = await import('./lock')
const { openBrowser } = await import('./browser')
const { startServer } = await import('./server')
const { stopWatcher } = await import('../hooks/mod/state')
const { ending } = await import('./life')

let lock: Awaited<ReturnType<typeof claim>> | null = null
let server: Awaited<ReturnType<typeof startServer>> | null = null
const release = () => {
  if (lock?.kind === 'mine') lock.release()
}

const { stop, crashed } = ending({
  get close() {
    return server === null ? null : server.close
  },
  stopWatcher: () => void stopWatcher(),
  release,
  exit: code => process.exit(code),
  write: text => void process.stderr.write(text),
})
for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP'] as const) process.on(signal, () => stop(0))
// Whatever fails past here still ends the watcher and removes the lock, and says why.
process.on('uncaughtException', crashed)
process.on('unhandledRejection', crashed)
// Whatever ends the process, the lock goes with it.
process.on('exit', release)

const show = async (url: string) => {
  if (asked.open && !(await openBrowser(url))) process.stdout.write('No browser could be opened: open the URL above yourself.\n')
}

let kept: ReturnType<typeof identity>
try {
  kept = identity(dataDir, root)
  lock = await claim(dataDir, root, kept.token)
} catch (error) {
  fail(`could not take the project's lock: ${messageOf(error)}`)
}
if (lock.kind === 'running') {
  process.stdout.write(`Knossos web pane for ${root} is already running: ${lock.url}\n`)
  if (lock.url !== '') await show(lock.url)
  process.exit(0)
}

try {
  server = await startServer({ root, pluginRoot, dataDir, port: asked.port ?? kept.port ?? 0, movable: asked.port === null, token: kept.token, editor })
} catch (error) {
  release()
  fail(`could not start: ${messageOf(error)}`)
}
lock.record(server.port)
kept.remember(server.port)
if (asked.port === null && kept.port !== null && server.port !== kept.port) process.stdout.write(`Port ${kept.port} is taken, so the address changed: ${server.url}\n`)
process.stdout.write(`Knossos web pane for ${root}: ${server.url}\nPress Ctrl-C to stop it.\n`)

await show(server.url)
