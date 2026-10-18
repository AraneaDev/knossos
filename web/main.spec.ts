import { execFileSync, spawn } from 'node:child_process'
import type { ChildProcessWithoutNullStreams } from 'node:child_process'
import { chmodSync, existsSync, mkdirSync, readdirSync, readFileSync, realpathSync, writeFileSync } from 'node:fs'
import { createServer } from 'node:http'
import type { AddressInfo } from 'node:net'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, describe, expect, it } from 'vitest'
import { scratch } from './fixtures/helpers'
import { browserCommands, openBrowser } from './browser'
import { lockFile } from './lock'

const HERE = dirname(fileURLToPath(import.meta.url))
const running: ChildProcessWithoutNullStreams[] = []

afterEach(() => {
  for (const child of running.splice(0)) if (child.exitCode === null) child.kill('SIGKILL')
})

/**
 * A plugin root whose wrapper answers from web/fixtures, except `watch`,
 * which becomes a long sleep marked `mark`: a watcher that only ends when
 * it is ended.
 */
function pluginRoot(mark: string): string {
  const root = scratch('knossos-web-plugin-')
  mkdirSync(join(root, 'hooks/scripts'), { recursive: true })
  const shim = join(root, 'hooks/scripts/knossos-run.sh')
  writeFileSync(shim, `#!/bin/sh\n[ "$1" = watch ] && exec sleep ${mark}\nexec sh '${join(HERE, 'fixtures/knossos-run.sh')}' "$@"\n`)
  chmodSync(shim, 0o755)
  return root
}

/** `node web/main.ts` for `dir`, not opening a browser, and its output as it comes. */
function main(dir: string, env: Record<string, string>, extra: string[] = []) {
  const child = spawn(process.execPath, [join(HERE, 'main.ts'), dir, '--no-open', ...extra], { env: { ...process.env, ...env } })
  running.push(child)
  let out = ''
  child.stdout.setEncoding('utf8').on('data', (text: string) => (out += text))
  child.stderr.setEncoding('utf8').on('data', (text: string) => (out += text))
  const exited = new Promise<number | null>(resolve => child.on('exit', code => resolve(code)))
  const line = async (match: RegExp): Promise<RegExpMatchArray> => {
    for (let i = 0; i < 200; i++) {
      const found = out.match(match)
      if (found !== null) return found
      await new Promise(resolve => setTimeout(resolve, 50))
    }
    throw new Error(`no ${match} in: ${out}`)
  }
  return { child, line, exited, out: () => out }
}

/** Whether a process whose command line holds `mark` runs. The quality image has no pgrep, so /proc is read where it exists. */
function alive(mark: string): boolean {
  if (existsSync('/proc/self/cmdline')) {
    for (const pid of readdirSync('/proc')) {
      if (!/^\d+$/.test(pid)) continue
      try {
        if (readFileSync(`/proc/${pid}/cmdline`, 'utf8').split('\0').join(' ').includes(`sleep ${mark}`)) return true
      } catch {
        // The process ended between the listing and the read.
      }
    }
    return false
  }
  try {
    execFileSync('pgrep', ['-f', `sleep ${mark}`])
    return true
  } catch {
    return false
  }
}

describe('knossos web', () => {
  it('prints its URL, and on SIGINT ends its watcher, removes its lock and exits 0', { timeout: 20_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const mark = String(30_000 + Math.floor(Math.random() * 9_999))
    const run = main(dir, { KNOSSOS_DATA_DIR: data, KNOSSOS_WEB_PLUGIN_ROOT: pluginRoot(mark) })
    const [, url] = await run.line(/Knossos web pane for (?:.*): (http:\/\/127\.0\.0\.1:\d+\/\?t=[0-9a-f]+)\n/)
    expect(run.out()).toContain(`Knossos web pane for ${realpathSync(dir)}: ${url}`)
    expect(existsSync(lockFile(data, dir))).toBe(true)
    // The address is the server's key: the lock, which any process may come to read, holds only the pid and port.
    expect(Object.keys(JSON.parse(readFileSync(lockFile(data, dir), 'utf8')))).toEqual(['pid', 'port'])
    expect(readFileSync(lockFile(data, dir), 'utf8')).not.toContain(new URL(url!).searchParams.get('t'))
    const page = await fetch(url!)
    expect(page.status).toBe(200)
    for (let i = 0; i < 100 && !alive(mark); i++) await new Promise(resolve => setTimeout(resolve, 50))
    expect(alive(mark)).toBe(true)

    run.child.kill('SIGINT')
    expect(await run.exited).toBe(0)
    expect(existsSync(lockFile(data, dir))).toBe(false)
    for (let i = 0; i < 40 && alive(mark); i++) await new Promise(resolve => setTimeout(resolve, 50))
    expect(alive(mark)).toBe(false)
  })

  it('finds the server already running for the project, says where, and exits 0', { timeout: 20_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const env = { KNOSSOS_DATA_DIR: data, KNOSSOS_WEB_PLUGIN_ROOT: pluginRoot(String(40_000 + Math.floor(Math.random() * 9_999))) }
    const first = main(dir, env)
    const [, url] = await first.line(/: (http:\/\/127\.0\.0\.1:\d+\/\?t=[0-9a-f]+)\n/)
    const second = main(dir, env)
    expect(await second.exited).toBe(0)
    expect(second.out()).toContain(`Knossos web pane for ${realpathSync(dir)} is already running: ${url}`)
    first.child.kill('SIGTERM')
    expect(await first.exited).toBe(0)
  })

  it('keeps its address across starts: the same token, and the same port while it is free', { timeout: 30_000 }, async () => {
    const dir = scratch()
    const data = scratch()
    const env = { KNOSSOS_DATA_DIR: data, KNOSSOS_WEB_PLUGIN_ROOT: pluginRoot(String(50_000 + Math.floor(Math.random() * 9_999))) }
    const address = /: (http:\/\/127\.0\.0\.1:(\d+)\/\?t=([0-9a-f]+))\n/
    const first = main(dir, env)
    const [, url, port, token] = await first.line(address)
    first.child.kill('SIGTERM')
    expect(await first.exited).toBe(0)

    const second = main(dir, env)
    expect((await second.line(address))[1]).toBe(url)
    second.child.kill('SIGTERM')
    expect(await second.exited).toBe(0)

    // Its port taken by something else, it moves to a free one, keeps the token, and says the address changed.
    const squatter = createServer()
    await new Promise<void>(resolve => squatter.listen(Number(port), '127.0.0.1', resolve))
    try {
      const third = main(dir, env)
      const [, moved, newPort, sameToken] = await third.line(address)
      expect(newPort).not.toBe(port)
      expect(sameToken).toBe(token)
      expect(third.out()).toContain(`Port ${port} is taken, so the address changed: ${moved}`)
      third.child.kill('SIGTERM')
      expect(await third.exited).toBe(0)
      // The new port is the one remembered now.
      const fourth = main(dir, env)
      expect((await fourth.line(address))[1]).toBe(moved)
      fourth.child.kill('SIGTERM')
      expect(await fourth.exited).toBe(0)
    } finally {
      squatter.close()
    }
  })

  it('refuses an option it does not know with its usage line, and takes a port after a space', { timeout: 20_000 }, async () => {
    const refused = main(scratch(), { KNOSSOS_DATA_DIR: scratch() }, ['--prot=8123'])
    expect(await refused.exited).toBe(2)
    expect(refused.out()).toBe('knossos web: unknown option --prot=8123\nusage: knossos web [path] [--port=N] [--no-open] [--editor=<command>]\n')
    const free = createServer()
    await new Promise<void>(resolve => free.listen(0, '127.0.0.1', resolve))
    const port = (free.address() as AddressInfo).port
    await new Promise(resolve => free.close(resolve))
    const run = main(scratch(), { KNOSSOS_DATA_DIR: scratch(), KNOSSOS_WEB_PLUGIN_ROOT: pluginRoot(String(60_000 + Math.floor(Math.random() * 9_999))) }, ['--port', String(port)])
    const [, at] = await run.line(/: http:\/\/127\.0\.0\.1:(\d+)\//)
    expect(Number(at)).toBe(port)
    run.child.kill('SIGTERM')
    expect(await run.exited).toBe(0)
  })

  it('refuses a directory that is not there', async () => {
    const run = main(join(scratch(), 'nowhere'), { KNOSSOS_DATA_DIR: scratch() })
    expect(await run.exited).toBe(2)
    expect(run.out()).toMatch(/knossos web: .*nowhere.* is not a directory/)
  })
})

describe('openBrowser', () => {
  it('opens with the first command that works, and says when none did', async () => {
    const bin = scratch()
    const path = process.env.PATH
    // No wslview here; xdg-open answers, and notes the address it was given.
    writeFileSync(join(bin, 'xdg-open'), `#!/bin/sh\necho "$1" > '${join(bin, 'opened')}'\n`)
    chmodSync(join(bin, 'xdg-open'), 0o755)
    process.env.PATH = bin
    try {
      expect(await openBrowser('http://127.0.0.1:1/?t=x', 'linux')).toBe(true)
      expect(readFileSync(join(bin, 'opened'), 'utf8')).toBe('http://127.0.0.1:1/?t=x\n')
      expect(await openBrowser('http://127.0.0.1:1/?t=x', 'darwin')).toBe(false)
    } finally {
      process.env.PATH = path
    }
  })
})

describe('browserCommands', () => {
  it('tries wslview, then xdg-open, then Windows itself on Linux; open on macOS; start on Windows', () => {
    expect(browserCommands('linux', 'U')).toEqual([['wslview', 'U'], ['xdg-open', 'U'], ['cmd.exe', '/c', 'start', '""', 'U']])
    expect(browserCommands('darwin', 'U')).toEqual([['open', 'U']])
    expect(browserCommands('win32', 'U')).toEqual([['cmd.exe', '/c', 'start', '""', 'U']])
  })
})
