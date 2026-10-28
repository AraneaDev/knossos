import { existsSync, writeFileSync } from 'node:fs'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { fixturePluginRoot, scratch } from './fixtures/helpers'
import { hostProject } from './host'
import { addressOf, claim as realClaim, identity as realIdentity, lockFile } from './lock'

// A page that connects makes the server read the checkout's commit; here that read fails, which the server logs.
vi.mock('../hooks/mod/watcher', async importOriginal => ({
  ...(await importOriginal<typeof import('../hooks/mod/watcher')>()),
  followHead: async () => {
    throw new Error('no commit')
  },
}))

// Set to make the next claim's `record` fail, as a disk that refuses the write would.
const failing = { record: false, remember: false }
vi.mock('./lock', async importOriginal => {
  const real = await importOriginal<typeof import('./lock')>()
  return {
    ...real,
    identity: (...args: Parameters<typeof realIdentity>) => {
      const kept = real.identity(...args)
      if (!failing.remember) return kept
      return {
        ...kept,
        remember: () => {
          throw new Error('read-only')
        },
      }
    },
    claim: async (...args: Parameters<typeof realClaim>) => {
      const claimed = await real.claim(...args)
      if (claimed.kind !== 'mine' || !failing.record) return claimed
      return {
        ...claimed,
        record: () => {
          throw new Error('disk full')
        },
      }
    },
  }
})

type Hosted = Awaited<ReturnType<typeof hostProject>>
const started: Hosted[] = []

afterEach(async () => {
  vi.restoreAllMocks()
  for (const hosted of started.splice(0)) {
    if (hosted.kind !== 'mine') continue
    await hosted.close()
    hosted.release()
  }
})

/** The event stream's address on the server at `url`, its token kept. */
const eventsOf = (url: string): string => {
  const events = new URL('/events', url)
  events.search = new URL(url).search
  return events.href
}

const hostFor = async (root: string, dataDir: string, pluginDir: string, log?: (text: string) => void): Promise<Hosted> => {
  const hosted = await hostProject({ root, dataDir, pluginRoot: pluginDir, editor: null, port: null, ...(log === undefined ? {} : { log }) })
  started.push(hosted)
  return hosted
}

describe('hostProject', () => {
  it('frees the lock when remembering the port fails', async () => {
    const root = scratch()
    const data = scratch()
    failing.remember = true
    try {
      await expect(hostProject({ root, dataDir: data, pluginRoot: fixturePluginRoot(), editor: null, port: null })).rejects.toThrow('read-only')
    } finally {
      failing.remember = false
    }
    expect(existsSync(lockFile(data, root))).toBe(false)
  })

  it('frees the lock when recording the server fails', async () => {
    const root = scratch()
    const data = scratch()
    failing.record = true
    try {
      await expect(hostProject({ root, dataDir: data, pluginRoot: fixturePluginRoot(), editor: null, port: null })).rejects.toThrow('disk full')
    } finally {
      failing.record = false
    }
    expect(existsSync(lockFile(data, root))).toBe(false)
  })

  it('starts the server and holds the lock when none runs', async () => {
    const root = scratch()
    const data = scratch()
    const hosted = await hostFor(root, data, fixturePluginRoot())
    if (hosted.kind !== 'mine') throw new Error('expected to host the project')
    expect(hosted.url).toBe(addressOf(hosted.port, hosted.token))
    expect(existsSync(lockFile(data, root))).toBe(true)
    await hosted.close()
    hosted.release()
    expect(existsSync(lockFile(data, root))).toBe(false)
  })

  it('carries the port the project was last served on, null on the first start', async () => {
    const root = scratch()
    const data = scratch()
    const plugin = fixturePluginRoot()
    const first = await hostFor(root, data, plugin)
    if (first.kind !== 'mine') throw new Error('expected to host the project')
    expect(first.kept).toBeNull()
    await first.close()
    first.release()
    const again = await hostFor(root, data, plugin)
    if (again.kind !== 'mine') throw new Error('expected to host the project')
    expect(again.kept).toBe(first.port)
  })

  it('points at the running server instead of starting another', async () => {
    const root = scratch()
    const data = scratch()
    const plugin = fixturePluginRoot()
    const first = await hostFor(root, data, plugin)
    if (first.kind !== 'mine') throw new Error('expected to host the project')
    // A lock held by another live process (this one's parent), whose server answers: a lock of this process's own is never respected.
    writeFileSync(lockFile(data, root), JSON.stringify({ pid: process.ppid, port: first.port }))
    const second = await hostFor(root, data, plugin)
    expect(second).toEqual({ kind: 'running', url: first.url })
  })

  it('sends the server log to the given writer, never stderr', async () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const lines: string[] = []
    const hosted = await hostFor(scratch(), scratch(), fixturePluginRoot(), text => lines.push(text))
    if (hosted.kind !== 'mine') throw new Error('expected to host the project')
    const page = await fetch(eventsOf(hosted.url))
    expect(page.status).toBe(200)
    void page.body?.cancel().catch(() => undefined)
    await vi.waitFor(() => expect(lines.some(line => line.startsWith('knossos web:'))).toBe(true))
    expect(spy).not.toHaveBeenCalled()
  })

  it('sends the server log to stderr when given no writer', async () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const hosted = await hostFor(scratch(), scratch(), fixturePluginRoot())
    if (hosted.kind !== 'mine') throw new Error('expected to host the project')
    const page = await fetch(eventsOf(hosted.url))
    expect(page.status).toBe(200)
    void page.body?.cancel().catch(() => undefined)
    await vi.waitFor(() => expect(spy.mock.calls.some(([line]) => String(line).startsWith('knossos web:'))).toBe(true))
    spy.mockRestore()
  })
})
