import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'
import { scratch } from './fixtures/helpers'
import { claim, lockFile } from './lock'

vi.mock('node:fs', async importOriginal => ({
  ...(await importOriginal<typeof import('node:fs')>()),
  // A file system without hard links.
  linkSync: () => {
    throw Object.assign(new Error('operation not permitted'), { code: 'EPERM' })
  },
}))

describe('claim on a file system without hard links', () => {
  it('creates the lock exclusively in one write, and still refuses a second start', async () => {
    const data = scratch()
    const root = scratch()
    const first = await claim(data, root, 'ab'.repeat(24))
    expect(first.kind).toBe('mine')
    expect(JSON.parse(readFileSync(lockFile(data, root), 'utf8'))).toEqual({ pid: process.pid, port: null })
    if (first.kind !== 'mine') throw new Error('not mine')
    first.release()
  })
})
