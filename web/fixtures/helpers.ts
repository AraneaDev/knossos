/**
 * What the web host's specs share: a plugin root whose wrapper is the
 * fixture one, the fixture dashboard for a project, and a frame read as text.
 */
import { spawn } from 'node:child_process'
import type { ChildProcess, ChildProcessWithoutNullStreams, SpawnOptionsWithoutStdio } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterAll, onTestFinished } from 'vitest'
import type { Dashboard } from '../../types'
import type { Frame } from '../hub'

const HERE = dirname(fileURLToPath(import.meta.url))

/** What a spec made that outlives it unless removed: scratch directories, the processes it spawned, and the tags of their trees. */
type Leftovers = { dirs: string[]; children: ChildProcess[]; tags: string[] }
/** What was made outside a test (a describe body, a module): removed when the file's tests are done. */
const unowned: Leftovers = { dirs: [], children: [], tags: [] }

/** The environment variable every process a spec starts carries, and hands on to everything it starts in turn. */
const TAG = 'KNOSSOS_SPEC_PROCESS'

/** Whether this system lists its processes in /proc, where a process's environment can be read: Linux, as CI is. */
export const HAS_PROC = existsSync('/proc/self/environ')

/** Whether process `pid` started with one of `marks` in its environment. */
function carries(pid: number, marks: string[]): boolean {
  try {
    const environ = readFileSync(`/proc/${pid}/environ`, 'latin1')
    return marks.some(mark => environ.includes(mark))
  } catch {
    return false
  }
}

/**
 * Kills every process tagged with one of `tags`, read from /proc, so a child orphaned by a killed parent is found
 * too. Each is read again right before its kill: a process that ended since the listing may have lent its pid to
 * another by then, and that one is left alone. True when one was found.
 */
function killTagged(tags: string[]): boolean {
  const marks = tags.map(tag => `${TAG}=${tag}\0`)
  const pids = readdirSync('/proc')
    .filter(name => /^\d+$/.test(name))
    .map(Number)
    .filter(pid => carries(pid, marks))
  for (const pid of pids) {
    if (!carries(pid, marks)) continue
    try {
      process.kill(pid, 'SIGKILL')
    } catch {
      // Gone already.
    }
  }
  return pids.length > 0
}

/**
 * Ends what `left` holds, then removes its directories: every tagged process where /proc can tell them, and the
 * spawned processes themselves everywhere (without /proc, what they started in turn is not reached).
 */
function clear(left: Leftovers): void {
  for (const child of left.children.splice(0)) if (child.exitCode === null && child.signalCode === null) child.kill('SIGKILL')
  const tags = left.tags.splice(0)
  if (HAS_PROC) {
    for (let round = 0; round < 50 && tags.length > 0 && killTagged(tags); round++) Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 20)
  }
  for (const dir of left.dirs.splice(0)) rmSync(dir, { recursive: true, force: true, maxRetries: 5 })
}

afterAll(() => clear(unowned))

/** The leftovers of the test that runs now, cleared when it ends however it ends (passed, failed or timed out); the file's when none runs. */
function leftovers(): Leftovers {
  const own: Leftovers = { dirs: [], children: [], tags: [] }
  try {
    onTestFinished(() => clear(own))
    return own
  } catch {
    return unowned
  }
}

/** A fresh directory under the system's temp dir, removed when the test that made it ends. */
export const scratch = (prefix = 'knossos-web-'): string => {
  const dir = mkdtempSync(join(tmpdir(), prefix))
  leftovers().dirs.push(dir)
  return dir
}

/**
 * `spawn` for a spec: the process and everything it starts carry a tag in their environment, and when the
 * test ends all of them are killed, the ones a killed parent left behind too (a server's watcher).
 */
export function spawnFixture(command: string, args: string[], options: SpawnOptionsWithoutStdio = {}): ChildProcessWithoutNullStreams {
  const tag = randomUUID()
  const child = spawn(command, args, { ...options, env: { ...(options.env ?? process.env), [TAG]: tag } })
  const left = leftovers()
  left.tags.push(tag)
  left.children.push(child)
  return child
}

/** A plugin root whose hooks/scripts/knossos-run.sh runs the fixture wrapper, which answers from web/fixtures. */
export function fixturePluginRoot(): string {
  const root = scratch('knossos-web-plugin-')
  mkdirSync(join(root, 'hooks/scripts'), { recursive: true })
  const shim = join(root, 'hooks/scripts/knossos-run.sh')
  writeFileSync(shim, `#!/bin/sh\nexec sh '${join(HERE, 'knossos-run.sh')}' "$@"\n`)
  chmodSync(shim, 0o755)
  return root
}

/**
 * A plugin root whose wrapper answers from web/fixtures, except `watch`,
 * which becomes a long sleep marked `mark`: a watcher that only ends when
 * it is ended.
 */
export function markedPluginRoot(mark: string): string {
  const root = scratch('knossos-web-plugin-')
  mkdirSync(join(root, 'hooks/scripts'), { recursive: true })
  const shim = join(root, 'hooks/scripts/knossos-run.sh')
  writeFileSync(shim, `#!/bin/sh\n[ "$1" = watch ] && exec sleep ${mark}\nexec sh '${join(HERE, 'knossos-run.sh')}' "$@"\n`)
  chmodSync(shim, 0o755)
  return root
}

/** The fixture dashboard, as the wrapper prints it for `root`. */
export const fixtureDashboard = (root: string): Dashboard => JSON.parse(readFileSync(join(HERE, 'dashboard.json'), 'utf8').replaceAll('@ROOT@', root)) as Dashboard

/** A frame's rows as the text they show, one line each. */
export const frameText = (frame: Frame): string => [...frame.body, ...frame.footer].map(row => row.segments.map(s => s.text).join('')).join('\n')

/** The tab the frame shows as open: the tab button laid on a ground. */
export const activeTab = (frame: Frame): string | undefined =>
  frame.body
    .flatMap(row => row.segments)
    .find(s => s.press?.id.startsWith('tab:') === true && s.look.background !== undefined)
    ?.press?.id.slice('tab:'.length)
