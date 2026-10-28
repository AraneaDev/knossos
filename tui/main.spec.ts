import { spawn } from 'node:child_process'
import type { ChildProcessWithoutNullStreams } from 'node:child_process'
import { chmodSync, existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, describe, expect, it } from 'vitest'
import { lockFile } from '../web/lock'
import { markedPluginRoot, scratch } from '../web/fixtures/helpers'
import { ENTER, LEAVE } from './terminal'

const HERE = dirname(fileURLToPath(import.meta.url))
const running: ChildProcessWithoutNullStreams[] = []

afterEach(() => {
  for (const child of running.splice(0)) if (child.exitCode === null) child.kill('SIGKILL')
})

const wait = (ms: number) => new Promise(resolve => setTimeout(resolve, ms))

/** What a spawned process has printed so far, and ways to wait on it. */
function watch(child: ChildProcessWithoutNullStreams) {
  let out = ''
  child.stdout.setEncoding('utf8').on('data', (text: string) => (out += text))
  child.stderr.setEncoding('utf8').on('data', (text: string) => (out += text))
  const exited = new Promise<number | null>(resolve => child.on('exit', code => resolve(code)))
  const until = async (test: (text: string) => boolean, what: string): Promise<void> => {
    for (let i = 0; i < 300; i++) {
      if (test(out)) return
      await wait(50)
    }
    throw new Error(`no ${what} in: ${JSON.stringify(out)}`)
  }
  return { out: () => out, exited, until }
}

/** `node tui/main.ts <dir>` on a pseudo-terminal of 100x30 (python's pty module is what a CI image has), its exit status passed through. */
function tui(dir: string, env: Record<string, string>) {
  const script = 'import pty,sys,os; sys.exit(os.waitstatus_to_exitcode(pty.spawn(sys.argv[1:])))'
  const child = spawn('python3', ['-c', script, 'sh', '-c', 'stty rows 30 cols 100; exec "$@"', 'sh', process.execPath, join(HERE, 'main.ts'), dir], { env: { ...process.env, ...env } })
  running.push(child)
  return { child, ...watch(child) }
}

/** `node web/main.ts <dir> --no-open` with the same environment. */
function web(dir: string, env: Record<string, string>) {
  const child = spawn(process.execPath, [join(HERE, '../web/main.ts'), dir, '--no-open'], { env: { ...process.env, ...env } })
  running.push(child)
  return { child, ...watch(child) }
}

const envOf = (data: string, plugin: string) => ({ KNOSSOS_DATA_DIR: data, KNOSSOS_WEB_PLUGIN_ROOT: plugin })
const mark = () => String(20_000 + Math.floor(Math.random() * 9_999))
/** The pid in the project's lock. */
const pidOf = (data: string, dir: string): number => (JSON.parse(readFileSync(lockFile(data, dir), 'utf8')) as { pid: number }).pid

describe('knossos (the terminal pane)', () => {
  it('opens the pane, switches tab by hotkey and restores the terminal on q', { timeout: 30_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const run = tui(dir, envOf(data, markedPluginRoot(mark())))
    await run.until(text => text.includes(ENTER), 'ENTER')
    await run.until(text => text.includes('Overview'), 'the first tab')
    expect(existsSync(lockFile(data, dir))).toBe(true)
    const before = run.out().length
    run.child.stdin.write('2')
    await run.until(text => text.slice(before).includes('Component0'), 'the second tab')
    run.child.stdin.write('q')
    expect(await run.exited).toBe(0)
    expect(run.out().endsWith(LEAVE)).toBe(true)
    expect(existsSync(lockFile(data, dir))).toBe(false)
  })

  it('restores the terminal when killed with SIGTERM', { timeout: 30_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const run = tui(dir, envOf(data, markedPluginRoot(mark())))
    await run.until(text => text.includes('Overview'), 'the first tab')
    process.kill(pidOf(data, dir), 'SIGTERM')
    await run.exited
    expect(run.out().endsWith(LEAVE)).toBe(true)
    expect(existsSync(lockFile(data, dir))).toBe(false)
  })

  it('attaches to a running pane and leaves it running on quit', { timeout: 30_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const env = envOf(data, markedPluginRoot(mark()))
    const server = web(dir, env)
    await server.until(text => text.includes('Press Ctrl-C'), 'the web pane')
    const run = tui(dir, env)
    await run.until(text => text.includes('Overview'), 'the first tab')
    run.child.stdin.write('q')
    expect(await run.exited).toBe(0)
    expect(existsSync(lockFile(data, dir))).toBe(true)
    expect(server.child.exitCode).toBeNull()
  })

  it('takes over when the pane it attached to stops', { timeout: 40_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const env = envOf(data, markedPluginRoot(mark()))
    const server = web(dir, env)
    await server.until(text => text.includes('Press Ctrl-C'), 'the web pane')
    const first = tui(dir, env)
    const second = tui(dir, env)
    await first.until(text => text.includes('Overview'), 'the first pane drawing')
    await second.until(text => text.includes('Overview'), 'the second pane drawing')
    const drawn = [first.out().length, second.out().length]
    server.child.kill('SIGTERM')
    await server.exited
    // One of them hosts now, the other attached to it: one lock, and both still draw.
    for (let i = 0; i < 200 && !existsSync(lockFile(data, dir)); i++) await wait(50)
    expect(existsSync(lockFile(data, dir))).toBe(true)
    first.child.stdin.write('2')
    second.child.stdin.write('2')
    await first.until(text => text.slice(drawn[0]).includes('Component0'), 'the first pane drawing again')
    await second.until(text => text.slice(drawn[1]).includes('Component0'), 'the second pane drawing again')
    expect(pidOf(data, dir)).not.toBe(server.child.pid)
    first.child.stdin.write('q')
    second.child.stdin.write('q')
    await Promise.all([first.exited, second.exited])
    expect(existsSync(lockFile(data, dir))).toBe(false)
  })

  it('keeps the server log off the screen', { timeout: 30_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    // A wrapper that finds no knossos binary: the mod logs that to the server's log.
    const plugin = markedPluginRoot(mark())
    const shim = join(plugin, 'hooks/scripts/knossos-run.sh')
    const body = readFileSync(shim, 'utf8')
    writeFileSync(shim, body.replace('[ "$1" = watch ]', '[ "$1" = dashboard ] && { echo \'{"status":"no-binary"}\'; exit 0; }\n[ "$1" = watch ]'))
    chmodSync(shim, 0o755)
    const run = tui(dir, envOf(data, plugin))
    await run.until(text => text.includes(ENTER), 'ENTER')
    await run.until(text => text.includes('Knossos'), 'a first paint')
    await wait(1500)
    run.child.stdin.write('q')
    expect(await run.exited).toBe(0)
    const log = join(data, 'web', `${lockFile(data, dir).split('/').pop()!.replace(/\.json$/, '')}.log`)
    expect(existsSync(log)).toBe(true)
    expect(readFileSync(log, 'utf8')).toContain('no knossos binary found')
    const screen = run.out().slice(0, run.out().lastIndexOf(LEAVE))
    expect(screen).not.toContain('no knossos binary found')
    expect(run.out().slice(run.out().lastIndexOf(LEAVE))).toContain(`knossos: the pane's log is in ${log}`)
  })
})
