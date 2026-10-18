import { spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, realpathSync, symlinkSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { scratch } from './fixtures/helpers'
import { claim, lockFile } from './lock'

/** A pid no process has: one that just ended. */
function deadPid(): number {
  const ended = spawnSync(process.execPath, ['-e', ''])
  return ended.pid!
}

describe('claim', () => {
  it('takes a free project, and gives it up on release', () => {
    const data = scratch()
    const root = scratch()
    const got = claim(data, root)
    expect(got.kind).toBe('mine')
    const file = lockFile(data, root)
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual({ pid: process.pid, url: '' })
    if (got.kind !== 'mine') throw new Error('not mine')
    got.record('http://127.0.0.1:1234/?t=x')
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual({ pid: process.pid, url: 'http://127.0.0.1:1234/?t=x' })
    got.release()
    expect(existsSync(file)).toBe(false)
    // Twice is harmless.
    got.release()
  })

  it("finds a live server's lock and its URL", () => {
    const data = scratch()
    const root = scratch()
    mkdirSync(join(data, 'web'), { recursive: true })
    // The parent of this process is alive for as long as the spec runs.
    writeFileSync(lockFile(data, root), JSON.stringify({ pid: process.ppid, url: 'http://127.0.0.1:9/?t=y' }))
    expect(claim(data, root)).toEqual({ kind: 'running', url: 'http://127.0.0.1:9/?t=y' })
  })

  it("takes over a lock whose process is gone, and writes it as its own", () => {
    const data = scratch()
    const root = scratch()
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(lockFile(data, root), JSON.stringify({ pid: deadPid(), url: 'http://127.0.0.1:9/?t=old' }))
    const got = claim(data, root)
    expect(got.kind).toBe('mine')
    expect(JSON.parse(readFileSync(lockFile(data, root), 'utf8'))).toEqual({ pid: process.pid, url: '' })
  })

  it('takes over a lock it cannot read', () => {
    const data = scratch()
    const root = scratch()
    mkdirSync(join(data, 'web'), { recursive: true })
    writeFileSync(lockFile(data, root), '{not json')
    expect(claim(data, root).kind).toBe('mine')
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
