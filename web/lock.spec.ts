import { spawn, spawnSync } from 'node:child_process'
import { chmodSync, existsSync, mkdirSync, readdirSync, readFileSync, realpathSync, statSync, symlinkSync, utimesSync, writeFileSync } from 'node:fs'
import { createServer } from 'node:http'
import type { AddressInfo } from 'node:net'
import { basename, dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it, vi } from 'vitest'
import { scratch } from './fixtures/helpers'
import { addressOf, claim, identity, lockFile } from './lock'

const LOCK = join(dirname(fileURLToPath(import.meta.url)), 'lock.ts')
const TOKEN = 'ab'.repeat(24)

/** A pid no process has: one that just ended. */
function deadPid(): number {
  const ended = spawnSync(process.execPath, ['-e', ''])
  return ended.pid!
}

/** Whatever runs as the lock's removal guard is taken, once: the moment between a start's read of a lock and its removal. */
let onGuard: ((lock: string) => void) | null = null

vi.mock('node:fs', async importOriginal => {
  const fs = await importOriginal<typeof import('node:fs')>()
  return {
    ...fs,
    writeFileSync: (...args: Parameters<typeof fs.writeFileSync>) => {
      const [path] = args
      if (typeof path === 'string' && path.endsWith('.removing') && onGuard !== null) {
        const hook = onGuard
        onGuard = null
        hook(path.slice(0, -'.removing'.length))
      }
      fs.writeFileSync(...args)
    },
  }
})

/**
 * A process that claims the project at `at` (epoch ms, so that several start together) and prints `mine` or
 * `running`. It stays alive, holding what it claimed, until `done`: a claimer that ended early would leave a
 * stale lock for a slower one to take.
 */
function claimer(data: string, root: string, at: number): { kind: Promise<string>; done(): void } {
  const code = `import(${JSON.stringify(LOCK)}).then(async m => { while (Date.now() < ${at}); const c = await m.claim(${JSON.stringify(data)}, ${JSON.stringify(root)}, ${JSON.stringify(TOKEN)}); console.log(c.kind); process.stdin.resume().on('end', () => process.exit(0)) })`
  const child = spawn(process.execPath, ['-e', code])
  let out = ''
  const kind = new Promise<string>(resolve => {
    child.stdout.on('data', (chunk: Buffer) => {
      out += chunk
      if (out.includes('\n')) resolve(out.trim())
    })
    child.on('close', () => resolve(out.trim()))
  })
  return { kind, done: () => child.stdin.end() }
}

describe('claim', () => {
  it('lets exactly one of several starts at once take a stale lock, whatever order they read and remove it in', { timeout: 60_000 }, async () => {
    for (let round = 0; round < 3; round++) {
      const data = scratch()
      const root = scratch()
      mkdirSync(join(data, 'web'), { recursive: true })
      writeFileSync(lockFile(data, root), JSON.stringify({ pid: deadPid(), port: null }))
      const at = Date.now() + 700
      const claimers = [claimer(data, root, at), claimer(data, root, at), claimer(data, root, at)]
      const kinds = await Promise.all(claimers.map(c => c.kind))
      for (const c of claimers) c.done()
      expect(kinds.filter(kind => kind === 'mine'), kinds.join()).toHaveLength(1)
      expect(kinds.filter(kind => kind === 'running')).toHaveLength(2)
    }
  })

  it('removes only the lock it read: one that replaced it in the meantime stays', async () => {
    const data = scratch()
    const root = scratch()
    const file = lockFile(data, root)
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(file, JSON.stringify({ pid: deadPid(), port: 4321 }))
    // Between this start's read of the stale lock and its removal, another start (this one's parent) replaces it with a live one.
    const live = { pid: process.ppid, port: null }
    onGuard = lock => writeFileSync(lock, JSON.stringify(live))
    expect((await claim(data, root, TOKEN)).kind).toBe('running')
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual(live)
    expect(readdirSync(join(data, 'web'))).toEqual([basename(file)])
  })

  it('clears a removal guard left by a start that died holding it, once it is old', async () => {
    const data = scratch()
    const root = scratch()
    const file = lockFile(data, root)
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(file, JSON.stringify({ pid: deadPid(), port: 4321 }))
    writeFileSync(`${file}.removing`, '1')
    const old = (Date.now() - 60_000) / 1000
    utimesSync(`${file}.removing`, old, old)
    expect((await claim(data, root, TOKEN)).kind).toBe('mine')
    expect(readdirSync(join(data, 'web'))).toEqual([basename(file)])
  })

  it('removes a stale lock it read and leaves no side file', async () => {
    const data = scratch()
    const root = scratch()
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(lockFile(data, root), JSON.stringify({ pid: deadPid(), port: 4321 }))
    expect((await claim(data, root, TOKEN)).kind).toBe('mine')
    expect(readdirSync(join(data, 'web'))).toEqual([basename(lockFile(data, root))])
  })

  it('records the port only once a removal under way is done, and keeps the lock', async () => {
    const data = scratch()
    const root = scratch()
    const file = lockFile(data, root)
    const got = await claim(data, root, TOKEN)
    if (got.kind !== 'mine') throw new Error('not mine')
    // Another start holds the removal guard for a moment, as it would while deciding whether the lock is stale.
    const guard = `${file}.removing`
    writeFileSync(guard, '1')
    const remover = spawn('sh', ['-c', `sleep 0.2; rm -f '${guard}'`])
    const started = Date.now()
    got.record(1234)
    expect(Date.now() - started).toBeGreaterThanOrEqual(150)
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual({ pid: process.pid, port: 1234 })
    expect(readdirSync(join(data, 'web'))).toEqual([basename(file)])
    await new Promise(done => remover.on('close', done))
  })

  it('refuses to record over a lock another start took over, and leaves that one', async () => {
    const data = scratch()
    const root = scratch()
    const file = lockFile(data, root)
    const got = await claim(data, root, TOKEN)
    if (got.kind !== 'mine') throw new Error('not mine')
    const other = { pid: process.ppid, port: null }
    writeFileSync(file, JSON.stringify(other))
    expect(() => got.record(1234)).toThrow(/took the lock .* over before this server listened/)
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual(other)
    got.release()
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual(other)
  })

  it("releases only its own lock: one another start took over in the meantime stays", async () => {
    const data = scratch()
    const root = scratch()
    const file = lockFile(data, root)
    const got = await claim(data, root, TOKEN)
    if (got.kind !== 'mine') throw new Error('not mine')
    const other = { pid: process.ppid, port: 4321 }
    writeFileSync(file, JSON.stringify(other))
    got.release()
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual(other)
    expect(readdirSync(join(data, 'web'))).toEqual([basename(file)])
  })

  it('leaves no staging file beside the lock, whether it takes it, records the port or loses it', async () => {
    const data = scratch()
    const root = scratch()
    const got = await claim(data, root, TOKEN)
    if (got.kind !== 'mine') throw new Error('not mine')
    got.record(1234)
    // Held by another live process (this one's parent): the second start loses, and stages nothing that stays.
    writeFileSync(lockFile(data, root), JSON.stringify({ pid: process.ppid, port: null }))
    expect((await claim(data, root, TOKEN)).kind).toBe('running')
    expect(readdirSync(join(data, 'web'))).toEqual([basename(lockFile(data, root))])
  })

  it('takes a free project, and gives it up on release', async () => {
    const data = scratch()
    const root = scratch()
    const got = await claim(data, root, TOKEN)
    expect(got.kind).toBe('mine')
    const file = lockFile(data, root)
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual({ pid: process.pid, port: null })
    if (got.kind !== 'mine') throw new Error('not mine')
    got.record(1234)
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual({ pid: process.pid, port: 1234 })
    got.release()
    expect(existsSync(file)).toBe(false)
    // Twice is harmless.
    got.release()
  })

  it('keeps the token out of the lock, and the lock and its directory to their owner', async () => {
    const data = scratch()
    const root = scratch()
    const got = await claim(data, root, TOKEN)
    if (got.kind !== 'mine') throw new Error('not mine')
    got.record(1234)
    const file = lockFile(data, root)
    expect(readFileSync(file, 'utf8')).not.toContain(TOKEN)
    expect(statSync(file).mode & 0o777).toBe(0o600)
    expect(statSync(join(data, 'web')).mode & 0o777).toBe(0o700)
    got.release()
  })

  it('narrows a web directory made before under a looser mode', async () => {
    const data = scratch()
    mkdirSync(join(data, 'web'), { mode: 0o755 })
    chmodSync(join(data, 'web'), 0o755)
    identity(data, scratch())
    expect(statSync(join(data, 'web')).mode & 0o777).toBe(0o700)
  })

  it("finds a live server's lock, and its address from the port and the project's token, when the server there answers", async () => {
    const data = scratch()
    const root = scratch()
    const asked: string[] = []
    const server = createServer((req, res) => {
      asked.push(req.url ?? '')
      res.writeHead(200).end('page')
    })
    await new Promise<void>(resolve => server.listen(0, '127.0.0.1', resolve))
    const port = (server.address() as AddressInfo).port
    try {
      mkdirSync(join(data, 'web'), { recursive: true })
      // The parent of this process is alive for as long as the spec runs.
      writeFileSync(lockFile(data, root), JSON.stringify({ pid: process.ppid, port }))
      expect(await claim(data, root, TOKEN)).toEqual({ kind: 'running', url: addressOf(port, TOKEN) })
      expect(asked).toEqual([`/?t=${TOKEN}`])
    } finally {
      server.close()
    }
  })

  it('takes over a lock whose pid is alive but whose URL nobody answers: a pid used again after a restart', async () => {
    const data = scratch()
    const root = scratch()
    mkdirSync(join(data, 'web'), { recursive: true })
    const server = createServer()
    await new Promise<void>(resolve => server.listen(0, '127.0.0.1', resolve))
    const port = (server.address() as AddressInfo).port
    await new Promise(resolve => server.close(resolve))
    writeFileSync(lockFile(data, root), JSON.stringify({ pid: process.ppid, port }))
    const got = await claim(data, root, TOKEN)
    expect(got.kind).toBe('mine')
    expect(JSON.parse(readFileSync(lockFile(data, root), 'utf8'))).toEqual({ pid: process.pid, port: null })
  })

  it('waits on a lock still starting, and takes over one that never got its URL', async () => {
    const data = scratch()
    const root = scratch()
    mkdirSync(join(data, 'web'), { recursive: true })
    const file = lockFile(data, root)
    writeFileSync(file, JSON.stringify({ pid: process.ppid, port: null }))
    expect(await claim(data, root, TOKEN)).toEqual({ kind: 'running', url: '' })
    const old = new Date(Date.now() - 60_000)
    utimesSync(file, old, old)
    expect((await claim(data, root, TOKEN)).kind).toBe('mine')
  })

  it("takes over a lock whose process is gone, and writes it as its own", async () => {
    const data = scratch()
    const root = scratch()
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(lockFile(data, root), JSON.stringify({ pid: deadPid(), port: 9 }))
    const got = await claim(data, root, TOKEN)
    expect(got.kind).toBe('mine')
    expect(JSON.parse(readFileSync(lockFile(data, root), 'utf8'))).toEqual({ pid: process.pid, port: null })
  })

  it('takes over a lock it cannot read once it is too old to be still being written', async () => {
    const data = scratch()
    const root = scratch()
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(lockFile(data, root), '{not json')
    const old = (Date.now() - 60_000) / 1000
    utimesSync(lockFile(data, root), old, old)
    expect((await claim(data, root, TOKEN)).kind).toBe('mine')
  })

  it('names the project by its real path: a link to it is the same project', () => {
    const data = scratch()
    const root = scratch()
    const link = join(scratch(), 'link')
    symlinkSync(root, link)
    expect(lockFile(data, link)).toBe(lockFile(data, realpathSync(root)))
    expect(lockFile(data, root)).toMatch(/[\\/]web[\\/][0-9a-f]{64}\.json$/)
  })
})

describe('identity', () => {
  it('makes a token for the project once, readable by its owner only, and gives the same one after', () => {
    const data = scratch()
    const root = scratch()
    const first = identity(data, root)
    expect(first.token).toMatch(/^[0-9a-f]{48}$/)
    expect(first.port).toBeNull()
    const file = lockFile(data, root).replace(/\.json$/, '.host.json')
    expect(statSync(file).mode & 0o777).toBe(0o600)
    first.remember(8123)
    const again = identity(data, root)
    expect(again.token).toBe(first.token)
    expect(again.port).toBe(8123)
    expect(statSync(file).mode & 0o777).toBe(0o600)
    // Another project has a token of its own.
    expect(identity(data, scratch()).token).not.toBe(first.token)
  })

  it('makes a new token when the file holds none it can use', () => {
    const data = scratch()
    const root = scratch()
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(lockFile(data, root).replace(/\.json$/, '.host.json'), '{"token": "short"}')
    expect(identity(data, root).token).toMatch(/^[0-9a-f]{48}$/)
  })
})
