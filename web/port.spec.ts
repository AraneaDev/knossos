import { mkdtempSync, readFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { webPort } from './port'
import type { PortHub } from './port'

const scratch = () => mkdtempSync(join(tmpdir(), 'knossos-web-port-'))

/** A hub that only counts what the port tells it. */
function fakeHub() {
  const hub = { changed: vi.fn<() => void>(), send: vi.fn<PortHub['send']>(), theme: 'dark', connected: false }
  return hub satisfies PortHub
}

const portOver = (hub: PortHub, storeFile = join(scratch(), 'store.json')) => webPort({ root: '/work/app', pluginRoot: '/nowhere', storeFile, hub })

describe('webPort', () => {
  it('notifies the hub once for a cell update, and the view drops its scrolls via cellChange', async () => {
    const hub = fakeHub()
    const io = portOver(hub)
    await io.state.view.update(v => ({ ...v, scrolls: { hubs: 4 } }))
    expect(hub.changed).toHaveBeenCalledTimes(1)
    expect((await io.state.view.read()).scrolls).toEqual({ hubs: 4 })
    await io.state.view.update(v => ({ ...v, tab: 'hubs' }))
    expect(hub.changed).toHaveBeenCalledTimes(2)
    expect((await io.state.view.read()).scrolls).toBeUndefined()
    // A change that leaves the value as it was draws nothing new.
    await io.state.feedback.update(f => f)
    expect(hub.changed).toHaveBeenCalledTimes(2)
  })

  it('reports the exit code and stdout of a run', async () => {
    const io = portOver(fakeHub())
    expect(await io.process.run(['sh', '-c', 'printf hello; printf oops >&2; exit 3'], { timeoutMs: 5_000 })).toEqual({ exitCode: 3, stdout: 'hello', stderr: 'oops' })
    expect((await io.process.run(['true'], { timeoutMs: 5_000 })).exitCode).toBe(0)
  })

  it('kills a run that outlives its timeout', async () => {
    const io = portOver(fakeHub())
    const pidFile = join(scratch(), 'pid')
    const started = Date.now()
    await expect(io.process.run(['sh', '-c', `echo $$ > ${pidFile}; exec sleep 30`], { timeoutMs: 300 })).rejects.toThrow(/timed out/)
    expect(Date.now() - started).toBeLessThan(5_000)
    const pid = Number(readFileSync(pidFile, 'utf8'))
    expect(() => process.kill(pid, 0)).toThrow()
  })

  it('streams a spawned child and ends it when the reader stops', async () => {
    const io = portOver(fakeHub())
    const pidFile = join(scratch(), 'pid')
    const stream = io.process.spawn({ argv: ['sh', '-c', `echo $$ > ${pidFile}; echo one; exec sleep 30`] })
    const first = await stream.next()
    expect(first.value).toEqual({ stream: 'stdout', text: 'one\n' })
    await stream.return(undefined)
    const pid = Number(readFileSync(pidFile, 'utf8'))
    await vi.waitFor(() => expect(() => process.kill(pid, 0)).toThrow())
  })

  it('ends a quiet child at once when the reader stops while it waits', async () => {
    const io = portOver(fakeHub())
    const pidFile = join(scratch(), 'pid')
    const stream = io.process.spawn({ argv: ['sh', '-c', `echo $$ > ${pidFile}; echo one; exec sleep 30`] })
    expect((await stream.next()).value).toEqual({ stream: 'stdout', text: 'one\n' })
    // The reader waits for a line that never comes, as the watcher's loop does between events.
    const pending = stream.next()
    const pid = Number(readFileSync(pidFile, 'utf8'))
    const stopped = Date.now()
    await stream.return(undefined)
    expect(Date.now() - stopped).toBeLessThan(1_000)
    expect(() => process.kill(pid, 0)).toThrow()
    expect((await pending).done).toBe(true)
  })

  it('keeps the store in its file, for the next port on it', async () => {
    const file = join(scratch(), 'deep', 'store.json')
    await portOver(fakeHub(), file).store.set('baselines', { web: { since: 's1' } })
    expect(await portOver(fakeHub(), file).store.get('baselines')).toEqual({ web: { since: 's1' } })
    expect(await portOver(fakeHub(), file).store.get('missing')).toBeUndefined()
  })

  it('follows links in a stat, and answers for the host', async () => {
    const hub = fakeHub()
    const io = portOver(hub)
    const dir = scratch()
    expect((await io.fs.stat(dir, { resolve: true })).realPath).toBe(dir)
    await expect(io.fs.stat(join(dir, 'gone'), { resolve: true })).rejects.toThrow()
    expect(await io.session.root()).toBe('/work/app')
    expect(await io.session.id()).toBe('web')
    expect(await io.config.list()).toEqual([{ key: 'theme', value: 'dark' }])
    expect(await io.ui.panes()).toEqual([])
    hub.connected = true
    expect(await io.ui.panes()).toEqual([{ id: 'knossos' }])
    expect(await io.ui.copy({ text: 'knossos scan' })).toEqual({ isCopied: true })
    io.ui.toast('hello')
    await io.ui.focus({ requestId: 'knossos', key: 'field:finder' })
    expect(hub.send.mock.calls.map(c => c[0])).toEqual([
      { type: 'copy', text: 'knossos scan' },
      { type: 'toast', text: 'hello' },
      { type: 'focus', key: 'field:finder' },
    ])
    io.ui.invalidate('ui.render')
    expect(hub.changed).toHaveBeenCalledTimes(1)
  })
})
