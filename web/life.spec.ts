import { describe, expect, it, vi } from 'vitest'
import { ending } from './life'
import type { Ending } from './life'

/** An ending whose every step is noted, in order, with a close that settles when told to. */
function steps(o: { started?: boolean; fails?: boolean } = {}) {
  const done: string[] = []
  let settle: () => void = () => undefined
  const ends: Ending = {
    close:
      o.started === false
        ? null
        : () =>
            new Promise<void>((resolve, reject) => {
              done.push('close')
              settle = () => (o.fails === true ? reject(new Error('stuck')) : resolve())
            }),
    stopWatcher: () => void done.push('stopWatcher'),
    release: () => void done.push('release'),
    exit: code => void done.push(`exit ${code}`),
    write: text => void done.push(`write ${text}`),
  }
  return { done, settle: () => settle(), ...ending(ends) }
}

const settled = () => new Promise(resolve => setTimeout(resolve, 0))

describe('ending', () => {
  it('prefixes what it says with the name it is given', () => {
    const written: string[] = []
    const { crashed } = ending({ close: null, stopWatcher: () => undefined, release: () => undefined, exit: () => undefined, write: text => void written.push(text), name: 'knossos' })
    crashed(new Error('boom'))
    expect(written).toEqual(['knossos: boom\n'])
  })

  it('closes the server, then removes the lock, then exits with the code', async () => {
    const end = steps()
    end.stop(0)
    expect(end.done).toEqual(['close'])
    end.settle()
    await settled()
    expect(end.done).toEqual(['close', 'release', 'exit 0'])
  })

  it('waits no longer on a second stop: the watcher ended, the lock removed, exit 130', () => {
    const end = steps()
    end.stop(0)
    end.stop(0)
    expect(end.done).toEqual(['close', 'stopWatcher', 'release', 'exit 130'])
  })

  it('ends the watcher and the lock at once before the server listens', () => {
    const end = steps({ started: false })
    end.stop(0)
    expect(end.done).toEqual(['stopWatcher', 'release', 'exit 0'])
  })

  it('says why a failure stopped it, and exits 1 once the watcher and the lock are gone', async () => {
    const end = steps()
    end.crashed(new Error('boom'))
    end.settle()
    await settled()
    expect(end.done).toEqual(['write knossos web: boom\n', 'close', 'release', 'exit 1'])
  })

  it('exits 1 at once for a failure while it stops, and says why a close failed', async () => {
    const stuck = steps()
    stuck.stop(0)
    stuck.crashed('again')
    expect(stuck.done).toEqual(['close', 'write knossos web: again\n', 'stopWatcher', 'release', 'exit 1'])
    const failing = steps({ fails: true })
    failing.stop(0)
    failing.settle()
    await vi.waitFor(() => expect(failing.done).toEqual(['close', 'write knossos web: stopping: stuck\n', 'release', 'exit 0']))
  })
})
