import { existsSync, writeFileSync } from 'node:fs'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { fixturePluginRoot, scratch } from './fixtures/helpers'
import { hostProject } from './host'
import { addressOf, lockFile } from './lock'

// A page that connects makes the server read the checkout's commit; here that read fails, which the server logs.
vi.mock('../hooks/mod/watcher', async importOriginal => ({
  ...(await importOriginal<typeof import('../hooks/mod/watcher')>()),
  followHead: async () => {
    throw new Error('no commit')
  },
}))

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

const hostFor = async (root: string, dataDir: string, pluginDir: string, log?: (text: string) => void): Promise<Hosted> => {
  const hosted = await hostProject({ root, dataDir, pluginRoot: pluginDir, editor: null, port: null, ...(log === undefined ? {} : { log }) })
  started.push(hosted)
  return hosted
}

describe('hostProject', () => {
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
    const page = await fetch(hosted.url.replace('/?', '/events?'))
    void page.body?.cancel().catch(() => undefined)
    await vi.waitFor(() => expect(lines.some(line => line.startsWith('knossos web:'))).toBe(true))
    expect(spy).not.toHaveBeenCalled()
  })
})
