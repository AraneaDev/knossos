import { mkdirSync, readdirSync, readFileSync, utimesSync, writeFileSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { basename, join } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { scratch } from './fixtures/helpers'
import { claim, lockFile } from './lock'

/** Whatever runs as the lock's removal guard is taken, once: the moment between a start's read of a lock and its removal. */
let onGuard: ((lock: string) => void) | null = null

vi.mock('node:fs', async importOriginal => {
  const fs = await importOriginal<typeof import('node:fs')>()
  return {
    ...fs,
    // A file system without hard links.
    linkSync: () => {
      throw Object.assign(new Error('operation not permitted'), { code: 'EPERM' })
    },
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

const TOKEN = 'ab'.repeat(24)

describe('claim on a file system without hard links', () => {
  it('creates the lock exclusively in one write, and still refuses a second start', async () => {
    const data = scratch()
    const root = scratch()
    const file = lockFile(data, root)
    const first = await claim(data, root, TOKEN)
    expect(first.kind).toBe('mine')
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual({ pid: process.pid, port: null })
    if (first.kind !== 'mine') throw new Error('not mine')
    // The first start, as another process would hold it: the exclusive write of the second start finds it and leaves it.
    const held = { pid: process.ppid, port: null }
    writeFileSync(file, JSON.stringify(held))
    expect((await claim(data, root, TOKEN)).kind).toBe('running')
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual(held)
    expect(readdirSync(join(data, 'web'))).toEqual([basename(file)])
  })

  it('waits on an empty lock that was just made, as another start still writing it, rather than take it', async () => {
    const data = scratch()
    const root = scratch()
    const file = lockFile(data, root)
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(file, '')
    const live = { pid: process.ppid, port: null }
    setTimeout(() => writeFileSync(file, JSON.stringify(live)), 100)
    expect((await claim(data, root, TOKEN)).kind).toBe('running')
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual(live)
  })

  it('takes an empty lock that is old: nobody is writing it any more', async () => {
    const data = scratch()
    const root = scratch()
    const file = lockFile(data, root)
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(file, '')
    const old = (Date.now() - 60_000) / 1000
    utimesSync(file, old, old)
    expect((await claim(data, root, TOKEN)).kind).toBe('mine')
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual({ pid: process.pid, port: null })
  })

  it('takes an unreadable lock whose time is in the future without waiting for the clock to reach it', async () => {
    const data = scratch()
    const root = scratch()
    const file = lockFile(data, root)
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(file, '')
    const future = (Date.now() + 3_600_000) / 1000
    utimesSync(file, future, future)
    const started = Date.now()
    expect((await claim(data, root, TOKEN)).kind).toBe('mine')
    expect(Date.now() - started).toBeLessThan(1000)
  })

  it('waits on an unreadable lock kept fresh for a bounded time only', { timeout: 10_000 }, async () => {
    const data = scratch()
    const root = scratch()
    const file = lockFile(data, root)
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(file, '')
    // Something keeps touching it, so its time never says it is old.
    const touch = setInterval(() => {
      const now = Date.now() / 1000
      try {
        utimesSync(file, now, now)
      } catch {
        // Taken already.
      }
    }, 50)
    const started = Date.now()
    try {
      expect((await claim(data, root, TOKEN)).kind).toBe('mine')
    } finally {
      clearInterval(touch)
    }
    expect(Date.now() - started).toBeLessThan(4000)
  })

  it('leaves a lock that replaced the stale one it read, without a hard link', async () => {
    const data = scratch()
    const root = scratch()
    const file = lockFile(data, root)
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(file, JSON.stringify({ pid: spawnSync(process.execPath, ['-e', '']).pid, port: 4321 }))
    const live = { pid: process.ppid, port: null }
    onGuard = lock => writeFileSync(lock, JSON.stringify(live))
    expect((await claim(data, root, TOKEN)).kind).toBe('running')
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual(live)
    expect(readdirSync(join(data, 'web'))).toEqual([basename(file)])
  })
})
