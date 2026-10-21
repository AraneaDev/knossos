import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { KnossosView } from '../../types'
import type { Row } from '../lib/types'
import { focusLanded, focusMoved, wheelScrolled } from './actions'
import { request } from './loaders'
import type { Port } from './port'
import { mod } from './state'

vi.mock('./loaders', async importOriginal => ({ ...(await importOriginal<typeof import('./loaders')>()), request: vi.fn(async () => {}) }))

const VIEW: KnossosView = { inspect: null, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' }

/** A port holding only the view, in a cell that reads and updates in memory. */
function port(view: Partial<KnossosView> = {}) {
  let value: KnossosView = { ...VIEW, ...view }
  const io = { state: { view: { read: async () => value, update: async (change: (v: KnossosView) => KnossosView) => void (value = change(value)) } } } as unknown as Port
  return { io, view: () => value }
}

beforeEach(() => {
  vi.mocked(request).mockClear()
  mod.focused = null
})

describe('focusMoved', () => {
  it('lets a focus on an ordinary element stand, and notes it', async () => {
    expect(await focusMoved(port().io, 'row:3')).toBeNull()
    expect(mod.focused).toBe('row:3')
  })

  it('redirects a tab key to its tab, and refuses the move backward past the first tab', async () => {
    expect(await focusMoved(port().io, 'tabkey:hubs')).toEqual({ to: 'tab:hubs' })
    expect(mod.focused).toBe('tab:hubs')
    expect(await focusMoved(port().io, 'tabkey:overview')).toEqual({ to: 'tab:overview' })
    expect(await focusMoved(port().io, 'tabkey:overview')).toEqual({ refuse: true })
  })

  it('notes no element for a focus that names none', async () => {
    expect(await focusMoved(port().io, undefined)).toBeNull()
    expect(mod.focused).toBeNull()
  })
})

describe('focusLanded', () => {
  it('moves the marker to a listed row and asks for the couplings and the peek', async () => {
    const { io, view } = port()
    await focusLanded(io, 'row:3')
    expect(view().selected).toBe(3)
    expect(vi.mocked(request).mock.calls.map(c => c[1])).toEqual(['couplings', 'peek'])
  })

  it('leaves the marker alone for any other element', async () => {
    const { io, view } = port()
    await focusLanded(io, 'tab:hubs')
    expect(view().selected).toBe(0)
    expect(request).not.toHaveBeenCalled()
  })
})

describe('wheelScrolled', () => {
  const rows: Row[] = [{ key: 'list', segments: [{ text: 'row' }], lists: [{ key: 'hubs', from: 0, to: 10, start: 0, shown: 5, length: 30 }] } as Row]

  it('keeps the window a list was scrolled to, in the view', async () => {
    const { io, view } = port()
    expect(await wheelScrolled(io, rows, 0, { row: 0, column: 1 }, 3)).toBe(true)
    expect(view().scrolls).toEqual({ hubs: 3 })
  })

  it('answers false over no list', async () => {
    const { io, view } = port()
    expect(await wheelScrolled(io, [], 0, { row: 0, column: 0 }, 3)).toBe(false)
    expect(view().scrolls).toBeUndefined()
  })
})
