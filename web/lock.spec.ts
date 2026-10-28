import { spawnSync } from 'node:child_process'
import { chmodSync, existsSync, mkdirSync, readdirSync, readFileSync, realpathSync, statSync, symlinkSync, utimesSync, writeFileSync } from 'node:fs'
import { createServer } from 'node:http'
import type { AddressInfo } from 'node:net'
import { basename, join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { scratch } from './fixtures/helpers'
import { addressOf, claim, identity, lockFile } from './lock'

const TOKEN = 'ab'.repeat(24)

/** A pid no process has: one that just ended. */
function deadPid(): number {
  const ended = spawnSync(process.execPath, ['-e', ''])
  return ended.pid!
}

describe('claim', () => {
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

  it('takes over a lock it cannot read', async () => {
    const data = scratch()
    const root = scratch()
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(lockFile(data, root), '{not json')
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
