/**
 * What the web host's specs share: a plugin root whose wrapper is the
 * fixture one, the fixture dashboard for a project, and a frame read as text.
 */
import { spawn } from 'node:child_process'
import type { ChildProcessWithoutNullStreams, SpawnOptionsWithoutStdio } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import { chmodSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterAll, onTestFinished } from 'vitest'
import type { Dashboard } from '../../types'
import type { Frame } from '../hub'

const HERE = dirname(fileURLToPath(import.meta.url))

/** What a spec made that outlives it unless removed: scratch directories, and the tags of the process trees it started. */
type Leftovers = { dirs: string[]; tags: string[] }
/** What was made outside a test (a describe body, a module): removed when the file's tests are done. */
const unowned: Leftovers = { dirs: [], tags: [] }

/** The environment variable every process a spec starts carries, and hands on to everything it starts in turn. */
const TAG = 'KNOSSOS_SPEC_PROCESS'

/** The pids of the processes whose environment holds one of `tags`: read from /proc, so a child orphaned by a killed parent is found too. */
function tagged(tags: string[]): number[] {
  const marks = tags.map(tag => `${TAG}=${tag}\0`)
  let pids: string[]
  try {
    pids = readdirSync('/proc').filter(name => /^\d+$/.test(name))
  } catch {
    // No /proc: only the spawned processes themselves are ended, by `spawnFixture`'s own kill.
    return []
  }
  return pids.map(Number).filter(pid => {
    try {
      const environ = readFileSync(`/proc/${pid}/environ`, 'latin1')
      return marks.some(mark => environ.includes(mark))
    } catch {
      return false
    }
  })
}

/** Ends every process tagged with one of `tags`, waiting a moment for them to go, then removes `dirs`. */
function clear(left: Leftovers): void {
  const tags = left.tags.splice(0)
  for (let round = 0; round < 50 && tags.length > 0; round++) {
    const pids = tagged(tags)
    if (pids.length === 0) break
    for (const pid of pids) {
      try {
        process.kill(pid, 'SIGKILL')
      } catch {
        // Gone already.
      }
    }
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 20)
  }
  for (const dir of left.dirs.splice(0)) rmSync(dir, { recursive: true, force: true, maxRetries: 5 })
}

afterAll(() => clear(unowned))

/** The leftovers of the test that runs now, cleared when it ends however it ends (passed, failed or timed out); the file's when none runs. */
function leftovers(): Leftovers {
  const own: Leftovers = { dirs: [], tags: [] }
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
  leftovers().tags.push(tag)
  try {
    // Without /proc the tag finds nothing: the process itself is still ended.
    onTestFinished(() => void (child.exitCode === null && child.signalCode === null && child.kill('SIGKILL')))
  } catch {
    // Started outside a test: found by its tag when the file's tests are done.
  }
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
