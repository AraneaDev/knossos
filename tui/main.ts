/**
 * `knossos [<dir>] [--editor=<command>] [--theme=dark|light]`: the pane of the
 * project in `dir` (the working directory by default), fullscreen in this
 * terminal. It is a client of the project's `knossos web` server: when none
 * runs it starts one here and stops it with the pane, and when one runs it
 * attaches to that one and leaves it running. If the server it attached to
 * stops, the next of the terminals attached to it takes the project over.
 *
 * The terminal is given back whatever ends the process: `q`, Ctrl-C, a
 * signal or a crash. The server's log never reaches the screen; it goes to
 * `web/<lock name>.log` in the data directory, and its path is named at exit
 * when the server wrote anything.
 */
import { spawn } from 'node:child_process'
import { appendFileSync, existsSync, mkdirSync, readFileSync, realpathSync, statSync } from 'node:fs'
import { homedir } from 'node:os'
import { delimiter, dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const NODE_MAJOR = 24
const major = Number(process.versions.node.split('.')[0])
if (major < NODE_MAJOR) {
  process.stderr.write(`knossos needs Node ${NODE_MAJOR} or newer (found v${process.versions.node}).\n`)
  process.exit(2)
}

/** Ends with `message` on stderr and exit code 2: it could not run. */
function fail(message: string): never {
  process.stderr.write(`knossos: ${message}\n`)
  process.exit(2)
}

const messageOf = (error: unknown): string => (error instanceof Error ? error.message : String(error))
const sleep = (ms: number) => new Promise<void>(done => setTimeout(done, ms))

const CHECKOUT = dirname(dirname(fileURLToPath(import.meta.url)))
// The shared modules import each other without an extension; plain Node needs a resolver for that before loading them.
await import(new URL('../tools/ts-resolve.mjs', import.meta.url).href)
const { argsOf, USAGE } = await import('./args')
const asked = argsOf(process.argv.slice(2))
if ('error' in asked) {
  process.stderr.write(`knossos: ${asked.error}\n${USAGE}\n`)
  process.exit(2)
}
if (!process.stdin.isTTY || !process.stdout.isTTY) fail('the pane needs a terminal for both input and output')
const given = resolve(asked.dir ?? '.')
if (!existsSync(given) || !statSync(given).isDirectory()) fail(`${given} is not a directory`)
const root = realpathSync(given)
const dataDir = process.env.KNOSSOS_DATA_DIR || join(homedir(), '.knossos')
const pluginRoot = process.env.KNOSSOS_WEB_PLUGIN_ROOT || CHECKOUT
// The wrapper runs this checkout's knossos unless told otherwise, as an installed plugin runs the checkout it came from.
if (!process.env.KNOSSOS_BIN && existsSync(join(CHECKOUT, 'bin/knossos'))) process.env.KNOSSOS_BIN = join(CHECKOUT, 'bin/knossos')
const editor = asked.editor ?? (process.env.KNOSSOS_EDITOR || null)

const { hostProject } = await import('../web/host')
type Hosted = Awaited<ReturnType<typeof hostProject>>
const { lockFile } = await import('../web/lock')
const { ending } = await import('../web/life')
const { stopWatcher } = await import('../hooks/mod/state')
const { Decoder, themeOfBackground } = await import('./input')
const { startClient } = await import('./client')
const { clipboardCommand, osc52 } = await import('./clipboard')
const { ASK_BACKGROUND, terminalMode } = await import('./terminal')
const { TOAST_MS } = await import('../web/public/app.mjs')

/** How long the terminal is given to tell its background colour. */
const BACKGROUND_MS = 150
/** How long a server that has taken the lock is given to say where it listens. */
const STARTING_MS = 10_000
/** How long after asking its process group to stop the pane waits to learn that it was not stopped. */
const STOPPED_MS = 50

const out = (bytes: string) => void process.stdout.write(bytes)
const terminal = terminalMode({ stdin: process.stdin, write: out })

// The server's log goes to a file beside the project's lock, never to the screen the pane owns.
const logFile = lockFile(dataDir, root).replace(/\.json$/, '.log')
let logged = false
/** Whether the lock names this process: the server that logs is this pane's own. */
const owning = (): boolean => {
  try {
    return (JSON.parse(readFileSync(lockFile(dataDir, root), 'utf8')) as { pid?: unknown }).pid === process.pid
  } catch {
    return false
  }
}
/** When the pane last told of the log: a burst of lines gives one toast. */
let toldAt = 0
/** A line was logged before the client could show it. */
let owed = false
const tell = () => {
  if (client === null) {
    owed = true
    return
  }
  const now = Date.now()
  if (now - toldAt < TOAST_MS) return
  toldAt = now
  client.toast(`Server message logged, see ${logFile}`)
}
const log = (text: string) => {
  try {
    mkdirSync(dirname(logFile), { recursive: true, mode: 0o700 })
    // The server's first line of a run starts the file again, so that it holds one run and does not grow without end.
    appendFileSync(logFile, `${text}\n`, { mode: 0o600, flag: !logged && owning() ? 'w' : 'a' })
    logged = true
  } catch {
    // A log that cannot be written must not take the pane down with it.
    return
  }
  tell()
}

let client: ReturnType<typeof startClient> | null = null
let hosted: Hosted | null = null
const release = () => {
  if (hosted?.kind === 'mine') hosted.release()
}

const { stop, crashed } = ending({
  get close() {
    return hosted?.kind === 'mine' ? hosted.close : null
  },
  stopWatcher: () => void stopWatcher(),
  release,
  exit: code => process.exit(code),
  // Whatever it says is said on the normal screen, where it stays.
  write: text => {
    terminal.leave()
    process.stderr.write(text)
  },
  name: 'knossos',
})
/** Ends the pane: the terminal is given back before anything else. */
const quit = (code = 0) => {
  client?.close()
  terminal.leave()
  // A take-over under way is waited for a moment, so the lock it claims is released with the rest.
  if (hosting === null) return stop(code)
  void Promise.race([hosting.catch(() => undefined), sleep(3000)]).then(() => stop(code))
}
for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP'] as const) process.on(signal, () => quit(0))
process.on('uncaughtException', crashed)
process.on('unhandledRejection', crashed)
process.on('exit', () => {
  terminal.leave()
  release()
  if (logged) process.stderr.write(`knossos: the pane's log is in ${logFile}\n`)
})

/** Whether the pane gave the terminal back to stop, and has not taken it again. */
let suspended = false
/** Takes the terminal again after a stop, or after a stop that did not happen. */
const resume = () => {
  if (!suspended) return
  suspended = false
  process.on('SIGTSTP', suspend)
  terminal.enter()
  client?.resize()
}
/**
 * Suspends the pane as a shell's job control would: the terminal back first, then
 * the whole process group is asked to stop, `knossos`'s PHP parent with it, so the
 * shell sees the job stop and takes the terminal. The pane's own SIGTSTP listener
 * is taken off first, so the signal stops this process as it does any other.
 *
 * A process group no shell controls (`exec knossos`, a tmux pane started as the
 * command) is orphaned: the kernel drops the signal, as nothing could start the
 * group again, and the pane takes the terminal back and draws itself again.
 */
function suspend(): void {
  if (suspended) return
  suspended = true
  terminal.leave()
  process.off('SIGTSTP', suspend)
  process.kill(0, 'SIGTSTP')
  // A stop happens before the call returns, so this runs once the group goes on, or at once when it was not stopped.
  setTimeout(resume, STOPPED_MS)
}
process.on('SIGTSTP', suspend)
process.on('SIGCONT', () => {
  if (suspended) return resume()
  // Stopped by something else: what is on the screen may not be the pane's any more.
  client?.resize()
})

/** The background colour reply in input, ended by BEL or ST. */
// eslint-disable-next-line no-control-regex -- the reply is made of control characters
const REPLY = /\x1b\]11;[^\x07\x1b]*(?:\x07|\x1b\\)/

/**
 * The terminal's background as a theme: asked for and waited on for a moment, `dark`
 * when it does not answer. `rest` is whatever else was typed meanwhile, for the client.
 */
function themeOfTerminal(): Promise<{ theme: 'dark' | 'light'; rest: string }> {
  return new Promise(done => {
    const decoder = new Decoder()
    let finished = false
    let held = ''
    const finish = (theme: 'dark' | 'light') => {
      if (finished) return
      finished = true
      process.stdin.off('data', listen)
      // Keys typed while the server starts wait in the terminal's buffer for the client.
      process.stdin.pause()
      done({ theme, rest: held.replace(REPLY, '') })
    }
    const listen = (chunk: string) => {
      held += chunk
      for (const e of decoder.push(chunk)) if (e.kind === 'background') finish(themeOfBackground(e.rgb))
    }
    process.stdin.on('data', listen)
    setTimeout(() => finish('dark'), BACKGROUND_MS)
    out(ASK_BACKGROUND)
  })
}

/** The project's server: started here, or the one that runs, waited for until it says where it listens. */
async function host(): Promise<Hosted> {
  const deadline = Date.now() + STARTING_MS
  for (;;) {
    const found = await hostProject({ root, dataDir, pluginRoot, editor, port: null, log })
    // Held from this moment, so that whatever ends the process releases it; a server that has not said where it listens replaces nothing.
    if (found.kind === 'mine' || found.url !== '') {
      hosted = found
      return found
    }
    if (Date.now() > deadline) throw new Error('the running server did not say where it listens')
    await sleep(100)
  }
}

/** Whether the server attached to was lost, so that the project may be taken over. */
let lost = false
/** The host() under way, if any: one at a time. */
let hosting: Promise<Hosted> | null = null
/** Takes the project over, or attaches to whoever took it first. */
function acquire(): Promise<Hosted> {
  hosting ??= host().finally(() => {
    hosting = null
  })
  return hosting
}
function retake(): Promise<void> {
  return acquire().then(
    () => {
      lost = false
    },
    (error: unknown) => {
      log(`knossos: taking the project over failed: ${messageOf(error)}`)
    },
  )
}

const origin = (url: string) => new URL(url).origin

try {
  terminal.enter()
  // Text, not bytes: a key is never cut in two between chunks, and Ctrl-Z is a character the decoder knows.
  process.stdin.setEncoding('utf8')
  out('\x1b[H\x1b[2JStarting the pane...')
  const { theme, rest } = asked.theme === null ? await themeOfTerminal() : { theme: asked.theme, rest: '' }
  const started = await acquire()
  const first = started.url
  let current = first
  const copyCommand = clipboardCommand(process.platform, process.env, name => (process.env.PATH ?? '').split(delimiter).some(dir => dir !== '' && existsSync(join(dir, name))))
  // The client's retries ask for the event stream again; before one goes out, a server that is gone is replaced.
  const reach: typeof fetch = async (input, init) => {
    const url = String(input)
    const events = url.includes('/events?')
    if (events && hosted?.kind === 'running' && (lost || !existsSync(lockFile(dataDir, root)))) {
      lost = true
      await retake()
      if (hosted !== null) current = hosted.url
    }
    try {
      return await fetch(origin(first) === origin(current) ? url : url.replace(origin(first), origin(current)), init)
    } catch (error) {
      if (events) lost = true
      throw error
    }
  }
  const size = () => ({ columns: process.stdout.columns || 80, rows: process.stdout.rows || 24 })
  client = startClient({
    url: first,
    size,
    theme,
    write: out,
    fetch: reach,
    copy: text => {
      out(osc52(text))
      if (copyCommand === null) return
      const child = spawn(copyCommand[0]!, copyCommand.slice(1), { stdio: ['pipe', 'ignore', 'ignore'] })
      child.on('error', () => undefined)
      child.stdin.on('error', () => undefined)
      child.stdin.end(text)
    },
    quit: () => quit(0),
    suspend,
    after: (ms, fire) => void setTimeout(fire, ms),
  })
  if (started.kind === 'running' && editor !== null) client.toast("This project's pane already runs; files open in its editor.")
  if (owed) tell()
  if (rest !== '') client.input(rest)
  // Raw mode hands Ctrl-Z over as a key rather than as a signal: the client gives it back through `suspend`.
  process.stdin.on('data', (chunk: string) => client?.input(chunk))
  process.stdin.on('end', () => quit(0))
  process.stdin.resume()
  process.stdout.on('resize', () => client?.resize())
} catch (error) {
  // Said on the normal screen, then stopped as any other end: the watcher ends and the lock goes.
  terminal.leave()
  process.stderr.write(`knossos: ${messageOf(error)}\n`)
  stop(2)
}
