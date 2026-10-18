/**
 * The engine's port built from Node, for the pane served in a browser: the
 * state cells in memory, Node's timers for the clock, child processes for
 * the wrapper and the watcher, and a JSON file for the store. Whatever
 * changes the state tells the hub, which draws the pages again. What only
 * Claude Code can do (a prompt, a session note, a slash command) does
 * nothing here: the pane hides what would ask for it on this host.
 */
import { spawn } from 'node:child_process'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { realpath, stat } from 'node:fs/promises'
import { dirname } from 'node:path'

import { cellChange, INITIAL } from '../hooks/mod/cells'
import type { CellValues } from '../hooks/mod/cells'
import type { Port } from '../hooks/mod/port'
import { PANE } from '../hooks/mod/state'

/** What the port needs of the hub: to be told of a change, to pass an event on, the pages' theme and whether a page is there. */
export type PortHub = { changed(): void; send(event: { type: string; [field: string]: unknown }): void; theme: string; connected: boolean }

/** The most a run may print before it is cut off, by default. */
const MAX_OUTPUT = 64 * 1024 * 1024

type Piece = { stream: 'stdout' | 'stderr'; text: string }

/** A timer on Node's clock that never keeps the process up by itself: the server does that. */
function timer(handle: ReturnType<typeof setTimeout>, clear: (h: ReturnType<typeof setTimeout>) => void) {
  handle.unref()
  return { cancel: () => clear(handle) }
}

/** The process groups of the runs under way: ended with this process, as nothing else would end them. */
const groups = new Set<number>()
let groupsEnded = false

/** Ends the process group `pid` leads, whatever is left of it. */
function endGroup(pid: number): void {
  try {
    process.kill(-pid, 'SIGKILL')
  } catch {
    // Gone already.
  }
}

/**
 * `argv` run to its end, or killed at `timeoutMs`: its exit code and output.
 * It leads a process group of its own, and a run that times out or prints
 * more than `maxOutput` bytes is ended with everything it started, so a
 * hung grandchild (PHP under the wrapper's shell) cannot keep its output
 * open. A run that could not start, timed out, printed too much or was
 * killed rejects, saying which.
 */
function run(argv: string[], timeoutMs: number, env: NodeJS.ProcessEnv, maxOutput: number): Promise<{ exitCode: number; stdout: string; stderr: string }> {
  if (!groupsEnded) {
    groupsEnded = true
    process.once('exit', () => groups.forEach(endGroup))
  }
  return new Promise((resolve, reject) => {
    const child = spawn(argv[0]!, argv.slice(1), { env, detached: true, stdio: ['ignore', 'pipe', 'pipe'] })
    const pid = child.pid
    if (pid !== undefined) groups.add(pid)
    const out = { stdout: '', stderr: '' }
    let size = 0
    let why: string | null = null
    const end = (reason: string) => {
      why ??= reason
      if (pid !== undefined) endGroup(pid)
    }
    const timer = timeoutMs > 0 ? setTimeout(() => end(`${argv[0]} timed out after ${timeoutMs} ms`), timeoutMs) : null
    for (const name of ['stdout', 'stderr'] as const) {
      child[name].setEncoding('utf8')
      child[name].on('data', (text: string) => {
        size += Buffer.byteLength(text)
        if (size > maxOutput) end(`${argv[0]} printed more than ${maxOutput} bytes`)
        else out[name] += text
      })
    }
    const settle = () => {
      if (timer !== null) clearTimeout(timer)
      if (pid !== undefined) groups.delete(pid)
    }
    child.on('error', error => {
      settle()
      reject(error)
    })
    child.on('close', (code, signal) => {
      settle()
      if (why !== null) return reject(new Error(why))
      if (signal !== null) return reject(new Error(`${argv[0]} was killed (${signal})`))
      resolve({ exitCode: code ?? 1, ...out })
    })
  })
}

/**
 * A child read piece by piece as it prints, as the watcher reads one: the
 * child starts at once, and stopping the read (`return()`) ends it, even
 * while a read waits on it.
 */
function spawned(argv: string[], env: NodeJS.ProcessEnv, heard: () => void): AsyncGenerator<Piece, void> {
  const child = spawn(argv[0]!, argv.slice(1), { env, stdio: ['ignore', 'pipe', 'pipe'] })
  const queue: Piece[] = []
  let done = false
  let failed: Error | null = null
  let wake: (() => void) | null = null
  const nudge = () => {
    wake?.()
    wake = null
  }
  for (const name of ['stdout', 'stderr'] as const) {
    child[name].setEncoding('utf8')
    child[name].on('data', (text: string) => {
      queue.push({ stream: name, text })
      nudge()
    })
  }
  child.on('error', error => {
    failed = error
    done = true
    nudge()
  })
  child.on('close', () => {
    done = true
    nudge()
  })
  const end = () => {
    if (child.exitCode === null && child.signalCode === null) child.kill('SIGTERM')
  }
  const pieces = (async function* () {
    try {
      for (;;) {
        const piece = queue.shift()
        if (piece !== undefined) {
          if (piece.stream === 'stdout') heard()
          yield piece
        } else if (done) break
        else await new Promise<void>(resolve => (wake = resolve))
      }
      if (failed !== null) throw failed
    } finally {
      end()
    }
  })()
  // A generator takes return() only after the read it waits on, which a quiet child never ends: the child is ended first, and its close ends that read.
  return {
    next: () => pieces.next(),
    return: value => {
      end()
      return pieces.return(value)
    },
    throw: error => {
      end()
      return pieces.throw(error)
    },
    [Symbol.asyncIterator]() {
      return this
    },
    [Symbol.asyncDispose]: async () => {
      end()
      await pieces.return()
    },
  }
}

/** The store's file read back: an object of keys, or empty when it is missing or unreadable. */
function storeOf(file: string): Record<string, unknown> {
  try {
    const parsed: unknown = JSON.parse(readFileSync(file, 'utf8'))
    return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as Record<string, unknown>) : {}
  } catch {
    return {}
  }
}

/**
 * The port for one project's pane at `root`, running the plugin's wrapper
 * from `pluginRoot` with `env`, keeping the store in `storeFile`. `heard` is
 * called whenever a spawned child (the watcher) prints; `maxOutput` bounds
 * what a run may print.
 */
export function webPort(o: { root: string; pluginRoot: string; storeFile: string; hub: PortHub; env?: NodeJS.ProcessEnv; heard?: () => void; maxOutput?: number }): Port {
  const env = o.env ?? process.env
  const heard = o.heard ?? (() => undefined)
  const values: Record<string, unknown> = { ...INITIAL }
  const cell = (key: keyof CellValues) => ({
    read: async () => values[key],
    update: async (change: (value: unknown) => unknown) => {
      const before = values[key]
      values[key] = (cellChange(key, change as never) as (value: unknown) => unknown)(before)
      if (!Object.is(before, values[key])) o.hub.changed()
      return values[key]
    },
  })
  const store = storeOf(o.storeFile)
  return {
    clock: {
      now: async () => Date.now(),
      after: (ms: number, fire: () => void) => timer(setTimeout(fire, ms), clearTimeout),
      every: (ms: number, fire: () => void) => timer(setInterval(fire, ms), clearInterval),
    },
    ui: {
      log: (text: string, options?: { to?: string }) => {
        if (options?.to !== 'debug') console.error(text)
      },
      toast: (text: string) => o.hub.send({ type: 'toast', text }),
      // The page writes the clipboard: with none connected nothing is copied, and a page whose browser refuses says so itself.
      copy: async (args: { text: string }) => {
        if (!o.hub.connected) return { isCopied: false, reason: 'no page is open' }
        o.hub.send({ type: 'copy', text: args.text })
        return { isCopied: true }
      },
      focus: async (args: { key?: string }) => o.hub.send({ type: 'focus', key: args.key }),
      scroll: async (args: { to?: { key?: string } }) => o.hub.send({ type: 'scroll', key: args.to?.key }),
      panes: async () => (o.hub.connected ? [{ id: PANE }] : []),
      invalidate: () => o.hub.changed(),
      open: async () => undefined,
      close: async () => undefined,
    },
    session: { root: async () => o.root, id: async () => 'web', append: async () => undefined },
    process: {
      run: (argv: string[], options?: { timeoutMs?: number }) => run(argv, options?.timeoutMs ?? 0, env, o.maxOutput ?? MAX_OUTPUT),
      spawn: (args: { argv: string[] }) => spawned(args.argv, env, heard),
    },
    fs: {
      stat: async (path: string, options?: { resolve?: boolean }) => {
        const found = await stat(path)
        const kind = found.isDirectory() ? 'directory' : found.isFile() ? 'file' : 'other'
        return { kind, size: found.size, mtimeMs: found.mtimeMs, ...(options?.resolve === true ? { realPath: await realpath(path) } : {}) }
      },
    },
    store: {
      get: async (key: string) => store[key],
      set: async (key: string, value: unknown) => {
        store[key] = value
        mkdirSync(dirname(o.storeFile), { recursive: true })
        writeFileSync(o.storeFile, JSON.stringify(store))
      },
    },
    config: { list: async () => [{ key: 'theme', value: o.hub.theme }] },
    command: { register: async () => undefined },
    prompt: { submit: async () => undefined },
    plugin: { root: o.pluginRoot },
    state: Object.fromEntries(Object.keys(INITIAL).map(key => [key, cell(key as keyof CellValues)])) as unknown as Port['state'],
  }
}
