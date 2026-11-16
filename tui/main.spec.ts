import type { ChildProcessWithoutNullStreams } from 'node:child_process'
import { chmodSync, existsSync, mkdirSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs'
import { basename, dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { lockFile } from '../web/lock'
import { HAS_PROC, markedPluginRoot, scratch, spawnFixture } from '../web/fixtures/helpers'
import { ASK_BACKGROUND, ENTER, LEAVE } from './terminal'

const HERE = dirname(fileURLToPath(import.meta.url))
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
    throw new Error(`no ${what} in: ...${JSON.stringify(out.slice(-300))}`)
  }
  return { out: () => out, exited, until }
}

/** `node tui/main.ts <dir>` on a pseudo-terminal of 100x30 (python's pty module is what a CI image has), its exit status passed through. */
function tui(dir: string, env: Record<string, string>, via: 'node' | 'php' = 'node', more: string[] = []) {
  // `php` goes through bin/knossos, which runs node as a child in its own process group, as an installed `knossos` does.
  const entry = via === 'php' ? ['php', join(HERE, '../bin/knossos'), dir, ...more] : [process.execPath, join(HERE, 'main.ts'), dir, ...more]
  // A session of its own with no shell in it: the process group is orphaned, as in `exec knossos` or a tmux pane started as the command.
  const child = spawnFixture('python3', ['-c', PTY, 'sh', '-c', 'stty rows 30 cols 130; exec "$@"', 'sh', ...entry], { env: { ...process.env, ...env } })
  return { child, ...watch(child) }
}

/** The pseudo-terminal: python's pty module, which is what a CI image has. */
const PTY = 'import pty,sys,os; sys.exit(os.waitstatus_to_exitcode(pty.spawn(sys.argv[1:])))'

/** `knossos <dir> <more>` through bin/knossos as a job of a shell with job control, which runs `then` once the job stops. */
function job(dir: string, env: Record<string, string>, more: string[], then: string) {
  const line = `stty rows 30 cols 130; set -m; php ${JSON.stringify(join(HERE, '../bin/knossos'))} ${JSON.stringify(dir)} ${more.join(' ')}; ${then}`
  const child = spawnFixture('python3', ['-c', PTY, 'bash', '--norc', '--noprofile', '-c', line], { env: { ...process.env, ...env } })
  return { child, ...watch(child) }
}

/** `node web/main.ts <dir> --no-open` with the same environment. */
function web(dir: string, env: Record<string, string>) {
  const child = spawnFixture(process.execPath, [join(HERE, '../web/main.ts'), dir, '--no-open'], { env: { ...process.env, ...env } })
  return { child, ...watch(child) }
}

const envOf = (data: string, plugin: string) => ({ KNOSSOS_DATA_DIR: data, KNOSSOS_WEB_PLUGIN_ROOT: plugin })
// Short, so that a watcher orphaned by a killed server soon ends on its own.
const mark = () => String(100 + Math.floor(Math.random() * 800))
/** The pid in the project's lock, read again if the file is missing for a moment (a take-over removing a stale lock before writing its own). */
const pidOf = (data: string, dir: string): number => {
  for (let i = 0; ; i++) {
    try {
      return (JSON.parse(readFileSync(lockFile(data, dir), 'utf8')) as { pid: number }).pid
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code !== 'ENOENT' || i >= 50) throw error
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 10)
    }
  }
}
/** A process's state letter and parent, from /proc. */
const procOf = (pid: number): { state: string; parent: number } => {
  const stat = readFileSync(`/proc/${pid}/stat`, 'utf8')
  const [state, parent] = stat.slice(stat.lastIndexOf(')') + 2).split(' ')
  return { state: state!, parent: Number(parent) }
}

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

  it.each(['SIGTERM', 'SIGKILL'] as const)('takes over when the pane it attached to is ended with %s, and both end up on one server', { timeout: 40_000 }, async signal => {
    const dir = scratch()
    const data = scratch()
    const env = envOf(data, markedPluginRoot(mark()))
    const server = web(dir, env)
    await server.until(text => text.includes('Press Ctrl-C'), 'the web pane')
    const first = tui(dir, env)
    const second = tui(dir, env)
    await first.until(text => text.includes('Overview'), 'the first pane drawing')
    await second.until(text => text.includes('Overview'), 'the second pane drawing')
    // SIGKILL leaves the dead server's lock behind, for the two to find stale at once.
    server.child.kill(signal)
    await server.exited
    for (let i = 0; i < 200 && (!existsSync(lockFile(data, dir)) || pidOf(data, dir) === server.child.pid); i++) await wait(50)
    expect(pidOf(data, dir)).not.toBe(server.child.pid)
    // One server: a press in the first pane moves the second, which two servers would not do.
    // Panes still reconnecting miss a press (a page does not resend what it pressed while away), so it is pressed until it shows.
    const seen = second.out().length
    for (let i = 0; i < 60 && !second.out().slice(seen).includes('Component0'); i++) {
      first.child.stdin.write('2')
      await wait(300)
    }
    expect(second.out().slice(seen)).toContain('Component0')
    // And it is the lock's server that answers: the port in the lock is the only one either pane reaches.
    const lock = JSON.parse(readFileSync(lockFile(data, dir), 'utf8')) as { pid: number; port: number }
    expect((await fetch(`http://127.0.0.1:${lock.port}/`)).status).toBe(403)
    first.child.stdin.write('q')
    second.child.stdin.write('q')
    await Promise.all([first.exited, second.exited])
    expect(existsSync(lockFile(data, dir))).toBe(false)
  })

  it('leaves its own server for the lock\'s when another server took the project over, and both panes end up there', { timeout: 40_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const env = envOf(data, markedPluginRoot(mark()))
    const first = tui(dir, env)
    await first.until(text => text.includes('Overview'), 'the first pane drawing')
    const own = JSON.parse(readFileSync(lockFile(data, dir), 'utf8')) as { pid: number; port: number }
    const second = tui(dir, env)
    await second.until(text => text.includes('Overview'), 'the second pane drawing')
    // Two servers of one project: the lock is set aside while another starts, as a take-over that found the first one stale would.
    const aside = `${lockFile(data, dir)}.aside`
    renameSync(lockFile(data, dir), aside)
    const other = web(dir, env)
    await other.until(text => text.includes('Press Ctrl-C'), 'the other server')
    rmSync(aside)
    const lock = JSON.parse(readFileSync(lockFile(data, dir), 'utf8')) as { pid: number; port: number }
    expect(lock.port).not.toBe(own.port)
    // The first pane's own server sees the lock name another and closes; its address answers no one after that.
    for (let i = 0; i < 200; i++) {
      if (!(await fetch(`http://127.0.0.1:${own.port}/`).then(() => true, () => false))) break
      await wait(50)
    }
    await expect(fetch(`http://127.0.0.1:${own.port}/`)).rejects.toThrow()
    // Both panes then reach the lock's server, the only one there is: a press in the first moves the second.
    const seen = second.out().length
    for (let i = 0; i < 60 && !second.out().slice(seen).includes('Component0'); i++) {
      first.child.stdin.write('2')
      await wait(300)
    }
    expect(second.out().slice(seen)).toContain('Component0')
    // Neither pane takes the lock it no longer holds with it.
    first.child.stdin.write('q')
    second.child.stdin.write('q')
    await Promise.all([first.exited, second.exited])
    expect(JSON.parse(readFileSync(lockFile(data, dir), 'utf8'))).toEqual(lock)
    expect(other.child.exitCode).toBeNull()
  })

  it("follows the project to its new server when its token is made anew while it runs, and both panes end up there", { timeout: 40_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const env = envOf(data, markedPluginRoot(mark()))
    const first = tui(dir, env)
    await first.until(text => text.includes('Overview'), 'the first pane drawing')
    const own = JSON.parse(readFileSync(lockFile(data, dir), 'utf8')) as { pid: number; port: number }
    // The identity file remade: the next start makes a new token, and takes the lock while the first server runs.
    const aside = `${lockFile(data, dir)}.aside`
    renameSync(lockFile(data, dir), aside)
    rmSync(lockFile(data, dir).replace(/\.json$/, '.host.json'))
    const other = web(dir, env)
    await other.until(text => text.includes('Press Ctrl-C'), 'the server with the new token')
    rmSync(aside)
    const second = tui(dir, env)
    await second.until(text => text.includes('Overview'), 'the second pane drawing')
    // The first server's clients cannot reach the new one with the old token: it gives way, and its pane follows.
    for (let i = 0; i < 200 && (await fetch(`http://127.0.0.1:${own.port}/`).then(() => true, () => false)); i++) await wait(50)
    await expect(fetch(`http://127.0.0.1:${own.port}/`)).rejects.toThrow()
    const seen = second.out().length
    for (let i = 0; i < 60 && !second.out().slice(seen).includes('Component0'); i++) {
      first.child.stdin.write('2')
      await wait(300)
    }
    expect(second.out().slice(seen)).toContain('Component0')
    first.child.stdin.write('q')
    second.child.stdin.write('q')
    await Promise.all([first.exited, second.exited])
    expect(other.child.exitCode).toBeNull()
  })

  it.skipIf(!HAS_PROC)('says that the pane holding the project is suspended, takes it over, and the suspended one joins once resumed', { timeout: 40_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const env = envOf(data, markedPluginRoot(mark()))
    const first = tui(dir, env)
    await first.until(text => text.includes('Overview'), 'the first pane drawing')
    const own = JSON.parse(readFileSync(lockFile(data, dir), 'utf8')) as { pid: number; port: number }
    // Stopped as Ctrl-Z stops it: its server is still there to connect to, and answers nothing.
    process.kill(own.pid, 'SIGSTOP')
    let resumed = false
    try {
      const second = tui(dir, env)
      await second.until(text => text.includes(`Knossos in another terminal (pid ${own.pid}) is suspended`), 'the second pane saying why it waits')
      await second.until(text => text.includes('Overview'), 'the second pane drawing, once it took over')
      expect(pidOf(data, dir)).not.toBe(own.pid)
      process.kill(own.pid, 'SIGCONT')
      resumed = true
      // Resumed, the first pane's server sees the lock name the other and closes; the first pane joins the other's server,
      // which then takes the port the first let go, so a tab that was away from it is told where the pane went.
      const { token } = JSON.parse(readFileSync(lockFile(data, dir).replace(/\.json$/, '.host.json'), 'utf8')) as { token: string }
      const now = JSON.parse(readFileSync(lockFile(data, dir), 'utf8')) as { pid: number; port: number }
      const moved = `data: ${JSON.stringify({ type: 'moved', port: now.port })}`
      const told = () => fetch(`http://127.0.0.1:${own.port}/events?t=${token}&id=away`).then(r => r.text(), () => '')
      for (let i = 0; i < 300 && !(await told()).startsWith(moved); i++) await wait(50)
      expect(await told()).toContain(moved)
      const seen = second.out().length
      for (let i = 0; i < 60 && !second.out().slice(seen).includes('Component0'); i++) {
        first.child.stdin.write('2')
        await wait(300)
      }
      expect(second.out().slice(seen)).toContain('Component0')
      first.child.stdin.write('q')
      second.child.stdin.write('q')
      await Promise.all([first.exited, second.exited])
      expect(existsSync(lockFile(data, dir))).toBe(false)
    } finally {
      if (!resumed) process.kill(own.pid, 'SIGCONT')
    }
  })

  it('stops the whole job on Ctrl-Z under a shell with job control, and takes the terminal again when the shell resumes it', { timeout: 40_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    // --theme with a terminal that never answers the background question: Ctrl-Z must still be read.
    const run = job(dir, envOf(data, markedPluginRoot(mark())), ['--theme=light'], 'echo job-stopped; read line; fg')
    await run.until(text => text.includes('Overview'), 'the first tab')
    const node = pidOf(data, dir)
    const php = procOf(node).parent
    run.child.stdin.write('\x1a')
    await run.until(text => text.includes('job-stopped'), 'the shell going on')
    // Both stopped, as the shell saw; the pane gave the terminal back first.
    expect([procOf(node).state, procOf(php).state]).toEqual(['T', 'T'])
    expect(run.out().slice(0, run.out().indexOf('job-stopped'))).toContain(LEAVE)
    const entered = run.out().split(ENTER).length
    run.child.stdin.write('\n')
    await run.until(text => text.split(ENTER).length > entered, 'ENTER again')
    run.child.stdin.write('q')
    expect(await run.exited).toBe(0)
    expect(run.out().endsWith(LEAVE)).toBe(true)
  })

  it('draws itself again on Ctrl-Z when no shell could resume it, rather than freeze', { timeout: 40_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const run = tui(dir, envOf(data, markedPluginRoot(mark())), 'php')
    await run.until(text => text.includes('Overview'), 'the first tab')
    const node = pidOf(data, dir)
    const php = procOf(node).parent
    const entered = run.out().split(ENTER).length
    run.child.stdin.write('\x1a')
    await run.until(text => text.split(ENTER).length > entered, 'ENTER again')
    const after = run.out().slice(run.out().lastIndexOf(ENTER))
    await run.until(text => text.slice(text.lastIndexOf(ENTER)).includes('Overview'), 'the pane drawn again')
    expect(after.length).toBeGreaterThan(0)
    expect([procOf(node).state, procOf(php).state]).not.toContain('T')
    // And it still takes keys.
    const before = run.out().length
    run.child.stdin.write('2')
    await run.until(text => text.slice(before).includes('Component0'), 'the second tab')
    run.child.stdin.write('q')
    expect(await run.exited).toBe(0)
  })

  it('hands keys typed while it starts, in the same chunk as the background reply too, to the pane once it draws', { timeout: 30_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const run = tui(dir, envOf(data, markedPluginRoot(mark())))
    await run.until(text => text.includes(ENTER), 'ENTER')
    // Waited for, not read off what has arrived so far: the line is a write of its own after ENTER,
    // and the two can reach this process in separate chunks.
    await run.until(text => text.includes('Starting the pane...'), 'the starting line')
    run.child.stdin.write('\x1b]11;rgb:ffff/ffff/ffff\x072')
    // Pressed once, before the first frame: the second tab shows without another key.
    await run.until(text => text.includes('Component0'), 'the second tab')
    run.child.stdin.write('q')
    expect(await run.exited).toBe(0)
  })

  it("asks for the background again when the terminal said nothing, and lays the pane on the late reply's colour", { timeout: 30_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const run = tui(dir, envOf(data, markedPluginRoot(mark())))
    await run.until(text => text.includes('Overview'), 'the first tab')
    // Unanswered, as tmux leaves a pane that asks before its own terminal has told it: the theme's ground is painted.
    expect(run.out()).toContain('48;2;30;30;30')
    await run.until(text => text.split(ASK_BACKGROUND).length === 3, 'a second question')
    const before = run.out().length
    run.child.stdin.write('\x1b]11;rgb:0d0d/1111/1717\x1b\\')
    // Frame by frame, each ended by the cursor's state: the status line's clock repaints row 30 every second,
    // and a tick that lands before the reply is read still lays the theme's ground.
    // eslint-disable-next-line no-control-regex -- a frame ends in a control sequence
    const frames = (text: string) => text.slice(before).split(/\x1b\[\?25[lh]/)
    const whole = (frame: string) => frame.includes('\x1b[2;1H') && frame.includes('\x1b[30;1H')
    const themed = (frame: string) => frame.includes('48;2;30;30;30')
    await run.until(text => frames(text).some(f => whole(f) && !themed(f)), 'the pane painted again')
    const all = frames(run.out())
    expect(all.slice(all.findIndex(f => whole(f) && !themed(f))).filter(themed)).toEqual([])
    run.child.stdin.write('q')
    expect(await run.exited).toBe(0)
  })

  it('says why it cannot start on the normal screen and exits 2', { timeout: 30_000 }, async () => {
    const dir = scratch()
    // A data directory that is a file: the project's lock cannot be taken.
    const data = join(scratch(), 'file')
    writeFileSync(data, '')
    const run = tui(dir, envOf(data, markedPluginRoot(mark())))
    expect(await run.exited).toBe(2)
    const shown = run.out().slice(run.out().lastIndexOf(LEAVE))
    expect(shown).toContain("knossos: could not take the project's lock")
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
    const log = join(data, 'web', `${basename(lockFile(data, dir)).replace(/\.json$/, '')}.log`)
    // What an earlier run logged: the file starts again with this run's first line.
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(log, 'from an earlier run\n')
    const run = tui(dir, envOf(data, plugin))
    await run.until(text => text.includes(ENTER), 'ENTER')
    for (let i = 0; i < 200 && !(existsSync(log) && readFileSync(log, 'utf8').includes('no knossos binary found')); i++) await wait(50)
    // The server's message is a toast naming the file, and nothing else of it is on the screen.
    await run.until(text => text.includes(`Server message logged, see ${log}`), 'the toast naming the log')
    run.child.stdin.write('q')
    expect(await run.exited).toBe(0)
    expect(readFileSync(log, 'utf8')).toContain('no knossos binary found')
    expect(readFileSync(log, 'utf8')).not.toContain('from an earlier run')
    const screen = run.out().slice(0, run.out().lastIndexOf(LEAVE))
    expect(screen).not.toContain('no knossos binary')
    expect(run.out().slice(run.out().lastIndexOf(LEAVE))).toContain(`knossos: the pane's log is in ${log}`)
  })
})
