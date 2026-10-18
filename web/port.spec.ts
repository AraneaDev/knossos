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

  it('ends a timed-out run whose own child still holds its output open, and that child too', async () => {
    const io = portOver(fakeHub())
    const pidFile = join(scratch(), 'pid')
    const started = Date.now()
    // Not exec'd: the shell waits on a sleep of its own, which keeps the pipe open after the shell is killed.
    await expect(io.process.run(['sh', '-c', `sleep 30 & echo $! > ${pidFile}; wait`], { timeoutMs: 300 })).rejects.toThrow(/timed out after 300 ms/)
    expect(Date.now() - started).toBeLessThan(5_000)
    const pid = Number(readFileSync(pidFile, 'utf8'))
    await vi.waitFor(() => expect(() => process.kill(pid, 0)).toThrow())
  })

  it('says a run that printed too much did, not that it timed out', async () => {
    const io = webPort({ root: '/work/app', pluginRoot: '/nowhere', storeFile: join(scratch(), 'store.json'), hub: fakeHub(), maxOutput: 1_000 })
    await expect(io.process.run(['sh', '-c', 'head -c 5000 /dev/zero; sleep 30'], { timeoutMs: 10_000 })).rejects.toThrow(/printed more than 1000 bytes/)
  })

  it('rejects a run that cannot start, and ends a spawned read that cannot either', async () => {
    const io = portOver(fakeHub())
    await expect(io.process.run(['/no/such/program'], { timeoutMs: 1_000 })).rejects.toThrow(/ENOENT/)
    const stream = io.process.spawn({ argv: ['/no/such/program'] })
    await expect(stream.next()).rejects.toThrow(/ENOENT/)
  })

  it('ends a spawned child when the read is thrown into or disposed of', async () => {
    const io = portOver(fakeHub())
    for (const stop of ['throw', 'dispose'] as const) {
      const pidFile = join(scratch(), 'pid')
      const stream = io.process.spawn({ argv: ['sh', '-c', `echo $$ > ${pidFile}; echo one; exec sleep 30`] })
      await stream.next()
      if (stop === 'throw') await expect(stream.throw(new Error('stop'))).rejects.toThrow('stop')
      else await (stream as unknown as AsyncDisposable)[Symbol.asyncDispose]()
      const pid = Number(readFileSync(pidFile, 'utf8'))
      await vi.waitFor(() => expect(() => process.kill(pid, 0)).toThrow())
    }
  })

  it('passes a scroll on to the pages, and does nothing for what only Claude Code can do', async () => {
    const hub = fakeHub()
    const io = portOver(hub)
    await io.ui.scroll({ requestId: 'knossos', to: { key: 'row:3' } } as never)
    expect(hub.send).toHaveBeenCalledWith({ type: 'scroll', key: 'row:3' })
    await expect(io.ui.open({} as never)).resolves.toBeUndefined()
    await expect(io.ui.close({} as never)).resolves.toBeUndefined()
    await expect(io.command.register({} as never)).resolves.toBeUndefined()
    await expect(io.prompt.submit({} as never)).resolves.toBeUndefined()
    await expect(io.session.append({} as never)).resolves.toBeUndefined()
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
    // With no page to write it, nothing is copied.
    expect(await io.ui.copy({ text: 'knossos scan' })).toEqual({ isCopied: false, reason: 'no page is open' })
    expect(hub.send).not.toHaveBeenCalled()
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
