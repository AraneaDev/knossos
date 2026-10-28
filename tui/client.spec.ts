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
  const suspended = { count: 0 }
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
    suspend: () => suspended.count++,
    after: (ms, fire) => timers.push({ ms, fire }),
  })
  clients.push(client)
  const text = () => strip(written.join(''))
  const sent = (kind: string) => posts.filter(p => p.kind === kind).map(p => Object.fromEntries(Object.entries(p).filter(([key]) => key !== 'id')))
  const settle = () => new Promise(resolve => setTimeout(resolve, 20))
  return { client, written, posts, pending, timers, copied, quit, suspended, hold, text, sent, settle, emit: (event: object) => push(event) }
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

  it("presses a press the frame gives q to rather than quit, and Ctrl-C still quits", async () => {
    const h = harness(frame({ body: [row('empty', press('scan', 'q'))], footer: [] }))
    await h.settle()
    h.client.input('q')
    expect(h.sent('press')).toEqual([{ kind: 'press', press: 'scan' }])
    expect(h.quit.count).toBe(0)
    h.client.input('\x03')
    expect(h.quit.count).toBe(1)
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
    const flush = h.timers.filter(t => t.ms === 30).at(-1)!
    flush.fire()
    expect(h.sent('submit')).toEqual([])
    expect(h.sent('focus').at(-1)).toEqual({ kind: 'focus', element: 'tab:overview' })
    h.client.input('q')
    expect(h.quit.count).toBe(1)
  })

  it('suspends on Ctrl-Z, in a field too, and types nothing for it', async () => {
    const h = harness()
    await h.settle()
    h.client.input('\x1a')
    expect(h.suspended.count).toBe(1)
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    h.client.input('\x1a')
    expect(h.suspended.count).toBe(2)
    expect(h.sent('type')).toEqual([])
  })

  it('keeps escape sequences and control characters of a paste out of a field', async () => {
    const h = harness()
    await h.settle()
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    h.client.input('\x1b[200~a\x1b[2Jb\tc\x07\x9bd\r\ne\x1b[201~')
    expect(h.sent('type').map(t => t.value)).toEqual(['a[2Jb cd e'])
    // A paste of nothing but control characters types nothing.
    h.client.input('\x1b[200~\x01\x02\x1b[201~')
    expect(h.sent('type')).toHaveLength(1)
  })

  it('ends a paste whose end marker never comes after a quiet second, so that Ctrl-C quits again', async () => {
    const h = harness()
    await h.settle()
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    h.client.input('\x1b[200~xy')
    h.client.input('\x03')
    expect(h.quit.count).toBe(0)
    // The first chunk's timer is stale: a later chunk came.
    for (const t of h.timers.filter(t => t.ms === 1000).slice(0, -1)) t.fire()
    expect(h.sent('type')).toEqual([])
    h.timers.filter(t => t.ms === 1000).at(-1)!.fire()
    expect(h.sent('type').map(t => t.value)).toEqual(['xy'])
    h.client.input('\x03')
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
    // The hubs tab starts at cell 16 (0-based) of the first line, after "1: tab:overview" and a space; column 18 (1-based) is inside it.
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

  it("shows the host's toast for 4000 ms without touching a persistent status", async () => {
    const h = harness()
    await h.settle()
    h.client.status('Reconnecting')
    h.client.toast('from the host')
    expect(h.text()).toContain('from the host')
    h.written.length = 0
    h.timers.find(t => t.ms === 4000)!.fire()
    expect(h.text()).not.toContain('from the host')
    expect(h.text()).toContain('Reconnecting')
  })

  it('copies on a copy event and shows the text on a toast, and shows a persistent status until cleared', async () => {
    const h = harness()
    await h.settle()
    h.emit({ type: 'copy', text: 'to the clipboard' })
    await h.settle()
    expect(h.copied).toEqual(['to the clipboard'])
    expect(h.text()).toContain('Copy it from here: to the clipboard')
    h.written.length = 0
    h.timers.find(t => t.ms === 4000)!.fire()
    expect(h.text()).not.toContain('Copy it from here')
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

describe('startClient in a body taller than the window', () => {
  const tall = (): Frame => frame({ body: Array.from({ length: 12 }, (_, i) => row(`b${i}`, press(`item:${i}`))) })
  const small = { columns: 20, rows: 5 }

  it('scrolls to an element the server reveals, then focuses', async () => {
    const h = harness(tall(), small)
    await h.settle()
    expect(h.text()).not.toContain('item:9')
    h.emit({ type: 'scroll', key: 'item:9' })
    h.emit({ type: 'focus', key: 'item:9' })
    await h.settle()
    const last = strip(h.written.at(-1)!)
    expect(last).toContain('item:9')
    expect(h.written.at(-1)).toContain(`${ESC}[7m`)
  })

  it('scrolls back up to an element above the window, and leaves the scroll for one outside the body', async () => {
    const h = harness(tall(), small)
    await h.settle()
    h.emit({ type: 'scroll', key: 'item:9' })
    await h.settle()
    const shown = strip(h.written.at(-1)!)
    h.written.length = 0
    h.emit({ type: 'scroll', key: 'rescan' })
    h.emit({ type: 'scroll', key: null })
    await h.settle()
    // Nothing moved: no line of the body is painted again.
    expect(shown).toContain('item:9')
    expect(h.text()).not.toContain('item:')
    h.emit({ type: 'scroll', key: 'item:1' })
    await h.settle()
    expect(strip(h.written.at(-1)!)).toContain('item:1')
    expect(strip(h.written.at(-1)!)).not.toContain('item:9')
  })

  it('sends the wheel over the footer with its own row', async () => {
    const h = harness(tall(), small)
    await h.settle()
    h.client.input('\x1b[<65;1;5M')
    expect(h.sent('wheel')).toEqual([{ kind: 'wheel', row: 4, column: 0, by: 1 }])
  })

  it('scrolls when the ring steps past the window', async () => {
    const h = harness(tall(), small)
    await h.settle()
    for (let i = 0; i < 6; i++) h.client.input('\t')
    expect(strip(h.written.at(-1)!)).toContain('item:5')
    expect(strip(h.written.at(-1)!)).not.toContain('item:0')
  })

  it('shows a field the server focuses below the window with its cursor', async () => {
    const body = [...Array.from({ length: 12 }, (_, i) => row(`b${i}`, seg(`line${i}`))), row('find', seg('', { field: { id: 'find', value: 'v', placeholder: '', width: 6 } }))]
    const h = harness(frame({ body }), small)
    await h.settle()
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    expect(h.written.at(-1)!.endsWith(`${ESC}[4;2H${ESC}[?25h`)).toBe(true)
  })

  it('sends a wheel row counted from the first body line, and does nothing when the post fails', async () => {
    const h = harness(tall(), small)
    await h.settle()
    h.client.input('\x1b[<65;1;1M')
    await h.settle()
    h.client.input('\x1b[<65;1;3M')
    await h.settle()
    expect(h.sent('wheel').map(w => w.row)).toEqual([0, 2])
    // When the server answers "not scrolled", a pinned body moves here and the next row is counted from it.
    const failing = harness(tall(), small)
    await failing.settle()
    failing.hold.answers = true
    failing.client.input('\x1b[<65;1;1M')
    failing.pending.shift()!(new Response('{"scrolled":false}'))
    await failing.settle()
    failing.client.input('\x1b[<65;1;1M')
    failing.pending.shift()!(new Response('{"scrolled":false}'))
    await failing.settle()
    // Two unscrolled rows moved the body; the next wheel is read against that.
    failing.hold.answers = false
    failing.client.input('\x1b[<65;1;1M')
    await failing.settle()
    expect(failing.sent('wheel').map(w => w.row)).toEqual([0, 1, 2])
  })
})

describe('startClient leaving a field', () => {
  it('leaves the field on a click elsewhere, focusing the press, so a hotkey presses', async () => {
    const h = harness()
    await h.settle()
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    h.client.input('\x1b[<0;18;1M')
    h.client.input('\x1b[<0;18;1m')
    expect(h.sent('focus').at(-1)).toEqual({ kind: 'focus', element: 'tab:hubs' })
    h.client.input('2')
    expect(h.sent('press').at(-1)).toEqual({ kind: 'press', press: 'tab:hubs' })
    expect(h.sent('type')).toEqual([])
  })
})

describe('startClient when a post fails', () => {
  it('drops it without an unhandled rejection or a local scroll', async () => {
    const written: string[] = []
    const body = Array.from({ length: 12 }, (_, i) => row(`b${i}`, seg(`line${i}`)))
    const fetchFake = (async (input: string | URL | Request): Promise<Response> => {
      if (String(input).includes('/events')) return new Response(`data: ${JSON.stringify(frame({ body }))}\n\n`, { headers: { 'content-type': 'text/event-stream' } })
      throw new TypeError('network')
    }) as typeof fetch
    const client = startClient({ url: 'http://127.0.0.1:1/?t=x', size: () => ({ columns: 20, rows: 5 }), theme: 'dark', write: bytes => written.push(bytes), fetch: fetchFake, copy: () => undefined, quit: () => undefined, suspend: () => undefined, after: () => undefined })
    await new Promise(resolve => setTimeout(resolve, 30))
    written.length = 0
    client.input('\x1b[<65;1;1M')
    await new Promise(resolve => setTimeout(resolve, 30))
    expect(written).toEqual([])
    client.close()
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
    const client = startClient({ url: 'http://127.0.0.1:1/?t=x', size: () => ({ columns: 40, rows: 10 }), theme: 'light', write: bytes => written.push(bytes), fetch: fetchFake, copy: () => undefined, quit: () => undefined, suspend: () => undefined, after: (ms, fire) => timers.push({ ms, fire }) })
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

describe('startClient keys in a field', () => {
  it('moves over a character beyond the basic plane whole, and ignores keys with nothing to do', async () => {
    const h = harness()
    await h.settle()
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    h.client.input('\u{1f600}a')
    // Left twice is before the emoji; Right is after it, where Delete takes the "a".
    h.client.input('\x1b[D')
    h.client.input('\x1b[D')
    h.client.input('\x1b[C')
    h.client.input('\x1b[3~')
    // At the end Delete, and at the start Backspace, do nothing; Alt and Ctrl keys type nothing.
    h.client.input('\x1b[F')
    h.client.input('\x1b[3~')
    h.client.input('\x1b[H')
    h.client.input('\x7f')
    h.client.input('\x1bx')
    h.client.input('\x02')
    expect(h.sent('type').map(t => t.value)).toEqual(['\u{1f600}', '\u{1f600}a', '\u{1f600}'])
    h.client.input('\x1b[F')
    h.client.input('\x7f')
    expect(h.sent('type').at(-1)!.value).toBe('')
  })

  it('leaves a field with Up, Tab and Shift-Tab, so that q quits after each', async () => {
    const h = harness()
    await h.settle()
    for (const leave of ['\x1b[A', '\t', '\x1b[Z']) {
      h.emit({ type: 'focus', key: 'find' })
      await h.settle()
      h.client.input(leave)
      h.client.input('q')
    }
    expect(h.quit.count).toBe(3)
    expect(h.sent('type')).toEqual([])
  })

  it('stops editing a field that leaves the frame, or when the focus goes to nothing', async () => {
    const h = harness()
    await h.settle()
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    h.emit(frame({ body: [row('tabs', press('tab:overview', '1'))] }))
    await h.settle()
    h.client.input('q')
    expect(h.quit.count).toBe(1)
    h.emit(frame())
    h.emit({ type: 'focus', key: 'find' })
    await h.settle()
    h.emit({ type: 'focus', key: null })
    await h.settle()
    h.client.input('q')
    expect(h.quit.count).toBe(2)
  })
})

describe('startClient pointer', () => {
  it('edits a field the mouse goes down on, keeping the cursor on a second press, and ignores other buttons', async () => {
    const h = harness()
    await h.settle()
    // The field starts at cell 5 of the second line; column 8 (1-based) is inside it.
    h.client.input('\x1b[<0;8;2M')
    h.client.input('\x1b[<0;8;2m')
    expect(h.sent('focus').at(-1)).toEqual({ kind: 'focus', element: 'find' })
    h.client.input('ab')
    h.client.input('\x1b[D')
    h.client.input('\x1b[<0;8;2M')
    h.client.input('\x1b[<0;8;2m')
    h.client.input('x')
    expect(h.sent('type').map(t => t.value)).toEqual(['a', 'ab', 'axb'])
    // The right button neither leaves the field nor presses.
    h.client.input('\x1b[<2;18;1M')
    h.client.input('\x1b[<2;18;1m')
    h.client.input('q')
    expect(h.quit.count).toBe(0)
    expect(h.sent('press')).toEqual([])
  })

  it('presses the focused press on Space, and moving over nothing paints nothing', async () => {
    const h = harness()
    await h.settle()
    h.client.input('\t')
    h.client.input(' ')
    expect(h.sent('press')).toEqual([{ kind: 'press', press: 'tab:overview' }])
    h.written.length = 0
    h.client.input('\x1b[<35;30;5M')
    h.client.input('\x1b[<35;31;5M')
    expect(h.written).toEqual([])
  })

  it('leaves the scroll of a body that is not pinned to the server', async () => {
    const h = harness(frame({ pinned: false }))
    await h.settle()
    h.hold.answers = true
    h.client.input('\x1b[<65;1;1M')
    h.written.length = 0
    h.pending.shift()!(new Response('{"scrolled":false}'))
    await h.settle()
    expect(h.written).toEqual([])
  })
})

describe('startClient before the first frame', () => {
  it('keeps what is typed until the first frame is drawn, then hands it on', async () => {
    const h = harness()
    h.client.input('2')
    expect(h.sent('press')).toEqual([])
    await h.settle()
    expect(h.sent('press')).toEqual([{ kind: 'press', press: 'tab:hubs' }])
  })

  it('keeps what is typed on a loading frame with nothing to press, up to a limit, until one has', async () => {
    const h = harness(frame({ body: [row('loading', seg('Reading the graph'))], footer: [] }))
    await h.settle()
    h.client.input('2')
    h.client.input('x'.repeat(100))
    await h.settle()
    expect(h.posts).toEqual([])
    h.emit(frame())
    await h.settle()
    expect(h.sent('press')).toEqual([{ kind: 'press', press: 'tab:hubs' }])
  })

  it('drops a click made while it holds keys rather than replay it on the frame that comes', async () => {
    const h = harness(frame({ body: [row('loading', seg('Reading the graph'))], footer: [] }))
    await h.settle()
    // Where the hubs tab will be once the tabs are drawn.
    h.client.input('\x1b[<0;18;1M')
    h.client.input('\x1b[<0;18;1m')
    h.emit(frame())
    await h.settle()
    expect(h.sent('press')).toEqual([])
    expect(h.sent('focus')).toEqual([])
  })

  it('holds nothing once a frame has had something to press: a key on a later frame without one is handled at once', async () => {
    const h = harness()
    await h.settle()
    h.emit(frame({ body: [row('scanning', seg('Scanning the project'))], footer: [] }))
    await h.settle()
    // Nothing to press here, so the hotkey does nothing, now or later.
    h.client.input('2')
    expect(h.sent('press')).toEqual([])
    h.emit(frame())
    await h.settle()
    expect(h.sent('press')).toEqual([])
    h.client.input('q')
    expect(h.quit.count).toBe(1)
  })

  it('quits on q or Ctrl-C and suspends on Ctrl-Z at once', () => {
    const h = harness()
    h.client.input('q')
    h.client.input('\x03')
    h.client.input('\x1a')
    expect([h.quit.count, h.suspended.count]).toEqual([2, 1])
  })
})

describe('startClient odds and ends', () => {
  it('keeps a newer toast when the older one times out', async () => {
    const h = harness()
    await h.settle()
    h.client.toast('one')
    h.client.toast('two')
    const [first, second] = h.timers.filter(t => t.ms === 4000)
    first!.fire()
    expect(strip(h.written.at(-1)!)).toContain('two')
    second!.fire()
    expect(strip(h.written.at(-1)!)).not.toContain('two')
  })

  it('passes over a late background reply, and does nothing once closed', async () => {
    const h = harness()
    await h.settle()
    h.client.input('\x1b]11;rgb:ffff/ffff/ffff\x07')
    expect(h.posts).toEqual([])
    h.client.input('\x1b')
    h.client.close()
    const count = h.written.length
    for (const t of h.timers) t.fire()
    h.client.toast('late')
    h.client.resize()
    h.client.input('2')
    expect(h.written).toHaveLength(count)
    expect(h.sent('press')).toEqual([])
  })

  it('works with an address that carries no token', async () => {
    const urls: string[] = []
    const client = startClient({
      url: 'http://127.0.0.1:1/',
      size: () => ({ columns: 40, rows: 10 }),
      theme: 'dark',
      write: () => undefined,
      fetch: (async (input: string | URL | Request) => {
        urls.push(String(input))
        return new Response('', { headers: { 'content-type': 'text/event-stream' } })
      }) as typeof fetch,
      copy: () => undefined,
      quit: () => undefined,
      suspend: () => undefined,
      after: () => undefined,
    })
    await new Promise(resolve => setTimeout(resolve, 20))
    client.close()
    expect(urls[0]).toMatch(/^http:\/\/127\.0\.0\.1:1\/events\?/)
  })
})
