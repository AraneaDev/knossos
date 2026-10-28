import { afterEach, describe, expect, it } from 'vitest'
import type { Frame } from '../web/hub'
import type { PageRow, PageSegment } from '../web/page-rows'
import { startClient } from './client'
import type { Client } from './client'

const ESC = String.fromCharCode(27)
const strip = (text: string): string => text.replace(new RegExp(`${ESC}\\[[0-9;?]*[A-Za-z]`, 'g'), '')
const seg = (text: string, more: Partial<PageSegment> = {}): PageSegment => ({ text, look: {}, columns: [...text].length, ...more })
const press = (id: string, hotkey?: string, more: Partial<PageSegment> = {}): PageSegment => seg(hotkey === undefined ? id : `${hotkey}: ${id}`, { press: { id, label: id, title: id, ...(hotkey === undefined ? {} : { hotkey }) }, ...more })
const row = (key: string, ...segments: PageSegment[]): PageRow => ({ key, segments })
const colours = { ground: '#101020', text: '#c0c0d0', card: '#303050', suggestion: '#ffaa00' }
const frame = (over: Partial<Frame> = {}): Frame => ({
  type: 'frame',
  body: [row('tabs', press('tab:overview', '1'), seg(' '), press('tab:hubs', '2')), row('find', seg('Find ', { columns: 5 }), seg('', { field: { id: 'find', value: '', placeholder: 'search', width: 10 } }))],
  footer: [row('foot', press('rescan', 'r'))],
  pinned: true,
  theme: 'dark',
  colours,
  ...over,
})

type Posted = Record<string, unknown>

/** A client over a fake server: it serves `first` on /events and keeps the stream open, records /input bodies, and can hold their answers. */
function harness(first: Frame = frame(), size = { columns: 40, rows: 10 }) {
  const written: string[] = []
  const posts: Posted[] = []
  const pending: Array<(r: Response) => void> = []
  const timers: Array<{ ms: number; fire: () => void }> = []
  const copied: string[] = []
  const quit = { count: 0 }
  const hold = { answers: false }
  let push: (event: object) => void = () => undefined
  const fetchFake = (async (input: string | URL | Request, init?: RequestInit): Promise<Response> => {
    const url = String(input)
    if (url.includes('/events')) {
      const stream = new ReadableStream<Uint8Array>({
        start(controller) {
          const encoder = new TextEncoder()
          push = event => controller.enqueue(encoder.encode(`data: ${JSON.stringify(event)}\n\n`))
          push(first)
        },
      })
      return new Response(stream, { headers: { 'content-type': 'text/event-stream' } })
    }
    posts.push(JSON.parse(String(init?.body)) as Posted)
    if (hold.answers) return new Promise<Response>(resolve => pending.push(resolve))
    return new Response('{"scrolled":true}')
  }) as typeof fetch
  const client: Client = startClient({
    url: 'http://127.0.0.1:4321/?t=secret',
    size: () => size,
    theme: 'dark',
    write: bytes => written.push(bytes),
    fetch: fetchFake,
    copy: text => copied.push(text),
    quit: () => quit.count++,
    now: () => 0,
    after: (ms, fire) => timers.push({ ms, fire }),
  })
  clients.push(client)
  const text = () => strip(written.join(''))
  const sent = (kind: string) => posts.filter(p => p.kind === kind).map(p => Object.fromEntries(Object.entries(p).filter(([key]) => key !== 'id')))
  const settle = () => new Promise(resolve => setTimeout(resolve, 20))
  return { client, written, posts, pending, timers, copied, quit, hold, text, sent, settle, emit: (event: object) => push(event) }
}

const clients: Client[] = []
afterEach(() => {
  for (const client of clients.splice(0)) client.close()
})

describe('startClient', () => {
  it('draws the first frame', async () => {
    const h = harness()
    await h.settle()
    expect(h.text()).toContain('1: tab:overview')
    expect(h.text()).toContain('r: rescan')
  })

  it('presses a tab by its hotkey', async () => {
    const h = harness()
    await h.settle()
    h.client.input('2')
    expect(h.sent('press')).toEqual([{ kind: 'press', press: 'tab:hubs' }])
  })

  it('quits on q outside a field and Ctrl-C anywhere, and types q inside a field', async () => {
    const h = harness()
    await h.settle()
    h.client.input('q')
    expect(h.quit.count).toBe(1)
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    h.client.input('q')
    expect(h.quit.count).toBe(1)
    expect(h.sent('type')).toEqual([{ kind: 'type', field: 'find', value: 'q' }])
    h.client.input('\x03')
    expect(h.quit.count).toBe(2)
  })

  it('steps the focus ring with Tab and Shift-Tab, and presses the focused one on Enter', async () => {
    const h = harness()
    await h.settle()
    h.client.input('\t')
    h.client.input('\t')
    expect(h.sent('focus').map(f => f.element)).toEqual(['tab:overview', 'tab:hubs'])
    h.client.input('\x1b[Z')
    expect(h.sent('focus').map(f => f.element)).toEqual(['tab:overview', 'tab:hubs', 'tab:overview'])
    h.client.input('\r')
    expect(h.sent('press')).toEqual([{ kind: 'press', press: 'tab:overview' }])
  })

  it('edits a focused field and submits it on Enter', async () => {
    const h = harness()
    await h.settle()
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    h.client.input('ab')
    expect(h.sent('type')).toEqual([
      { kind: 'type', field: 'find', value: 'a' },
      { kind: 'type', field: 'find', value: 'ab' },
    ])
    h.client.input('\r')
    expect(h.sent('submit')).toEqual([{ kind: 'submit', field: 'find', value: 'ab' }])
    expect(h.written.at(-1)!.endsWith(`${ESC}[2;8H${ESC}[?25h`)).toBe(true)
  })

  it('moves and deletes inside a field, and takes a paste', async () => {
    const h = harness()
    await h.settle()
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    h.client.input('abc')
    h.client.input('\x1b[D')
    h.client.input('\x7f')
    h.client.input('\x1b[3~')
    h.client.input('\x1b[H')
    h.client.input('\x1b[200~x\ny\x1b[201~')
    expect(h.sent('type').map(t => t.value)).toEqual(['a', 'ab', 'abc', 'ac', 'a', 'x ya'])
  })

  it('leaves a field on Escape without submitting, once input has been quiet', async () => {
    const h = harness()
    await h.settle()
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    h.client.input('\x1b')
    const flush = h.timers.at(-1)!
    expect(flush.ms).toBe(30)
    flush.fire()
    expect(h.sent('submit')).toEqual([])
    expect(h.sent('focus').at(-1)).toEqual({ kind: 'focus', element: 'tab:overview' })
    h.client.input('q')
    expect(h.quit.count).toBe(1)
  })

  it('leaves a field with the arrows and steps the ring', async () => {
    const h = harness()
    await h.settle()
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    h.client.input('\x1b[B')
    expect(h.sent('focus').at(-1)).toEqual({ kind: 'focus', element: 'tab:overview' })
  })

  it('presses on mouse release over the press it went down on', async () => {
    const h = harness()
    await h.settle()
    // The hubs tab is at column 15 of the first line (after "1: tab:overview" and a space).
    h.client.input('\x1b[<0;18;1M')
    h.client.input('\x1b[<0;18;1m')
    expect(h.sent('press')).toEqual([{ kind: 'press', press: 'tab:hubs' }])
    h.client.input('\x1b[<0;18;1M')
    h.client.input('\x1b[<0;3;1m')
    expect(h.sent('press')).toHaveLength(1)
  })

  it('sends one wheel input at a time and folds the rest', async () => {
    const h = harness()
    await h.settle()
    h.hold.answers = true
    for (let i = 0; i < 3; i++) h.client.input('\x1b[<65;5;2M')
    expect(h.sent('wheel')).toHaveLength(1)
    h.hold.answers = false
    h.pending.shift()!(new Response('{"scrolled":true}'))
    await h.settle()
    expect(h.sent('wheel')).toEqual([
      { kind: 'wheel', row: 1, column: 4, by: 1 },
      { kind: 'wheel', row: 1, column: 4, by: 2 },
    ])
  })

  it('scrolls a pinned body itself when the server did not', async () => {
    const body = Array.from({ length: 12 }, (_, i) => row(`b${i}`, seg(`line${i}`)))
    const h = harness(frame({ body }), { columns: 20, rows: 5 })
    await h.settle()
    expect(h.text()).toContain('line0')
    h.hold.answers = true
    h.client.input('\x1b[<65;1;1M')
    h.hold.answers = false
    h.pending.shift()!(new Response('{"scrolled":false}'))
    await h.settle()
    const last = strip(h.written.at(-1)!)
    expect(last).toContain('line1')
    expect(last).not.toContain('line0')
  })

  it('shows the card of the segment under the pointer', async () => {
    const card = { key: 'k', width: 8, lines: ['the card'] }
    const h = harness(frame({ body: [row('a', seg('hover', { columns: 5, card })), row('b', seg('below'))] }))
    await h.settle()
    h.client.input('\x1b[<35;2;1M')
    expect(h.text()).toContain('the card')
  })

  it('shows a toast and clears it after 4000 ms', async () => {
    const h = harness()
    await h.settle()
    h.emit({ type: 'toast', text: 'saved it' })
    await h.settle()
    expect(h.text()).toContain('saved it')
    const timer = h.timers.find(t => t.ms === 4000)!
    h.written.length = 0
    timer.fire()
    expect(h.text()).not.toContain('saved it')
    expect(h.written.join('')).not.toBe('')
  })

  it('copies on a copy event, and shows a persistent status until cleared', async () => {
    const h = harness()
    await h.settle()
    h.emit({ type: 'copy', text: 'to the clipboard' })
    await h.settle()
    expect(h.copied).toEqual(['to the clipboard'])
    h.client.status('Reconnecting')
    expect(h.text()).toContain('Reconnecting')
    h.written.length = 0
    h.client.status(null)
    expect(h.text()).not.toContain('Reconnecting')
  })

  it('tells the server the new size on resize and paints every line again', async () => {
    const h = harness()
    await h.settle()
    h.written.length = 0
    h.client.resize()
    expect(h.sent('resize')).toEqual([{ kind: 'resize', cols: 40, rows: 10 }])
    expect(h.written.join('')).toContain('\x1b[10;1H')
  })
})

describe('startClient when the stream is lost', () => {
  it('says it is reconnecting, waits with the host, and stops on close', async () => {
    const written: string[] = []
    const timers: Array<{ ms: number; fire: () => void }> = []
    let opened = 0
    const fetchFake = (async (input: string | URL | Request): Promise<Response> => {
      if (!String(input).includes('/events')) return new Response('{}')
      opened++
      return new Response(`data: ${JSON.stringify(frame())}\n\n`, { headers: { 'content-type': 'text/event-stream' } })
    }) as typeof fetch
    const client = startClient({ url: 'http://127.0.0.1:1/?t=x', size: () => ({ columns: 40, rows: 10 }), theme: 'light', write: bytes => written.push(bytes), fetch: fetchFake, copy: () => undefined, quit: () => undefined, now: () => 0, after: (ms, fire) => timers.push({ ms, fire }) })
    await new Promise(resolve => setTimeout(resolve, 30))
    expect(strip(written.join(''))).toContain('Reconnecting to the server')
    expect(timers.map(t => t.ms)).toContain(500)
    timers.find(t => t.ms === 500)!.fire()
    await new Promise(resolve => setTimeout(resolve, 30))
    expect(opened).toBe(2)
    client.close()
    written.length = 0
    client.input('2')
    client.status('late')
    expect(written).toEqual([])
  })
})
