import { afterEach, describe, expect, it } from 'vitest'
import { SILENT_MS } from '../web/public/app.mjs'
import type { Frame } from '../web/hub'
import { startClient } from './client'
import type { Client, ClientOptions } from './client'

const ESC = String.fromCharCode(27)
const strip = (text: string): string => text.replace(new RegExp(`${ESC}\\[[0-9;?]*[A-Za-z]`, 'g'), '')
const press = { text: '2: tab:hubs', look: {}, columns: 11, press: { id: 'tab:hubs', label: 'tab:hubs', title: 'tab:hubs', hotkey: '2' } }
const frame: Frame = { type: 'frame', body: [{ key: 'tabs', segments: [press] }], footer: [], pinned: true, theme: 'dark', colours: { ground: '#101020', text: '#c0c0d0', card: '#303050', suggestion: '#ffaa00' } }
const RECONNECTING = 'Reconnecting to the server'

/**
 * A client over a fake server whose /events sends `events` and then stays open (or ends, with `ends`), with the
 * host's timers held for the spec to fire. `opened` counts the streams it asked for.
 */
function client(more: Partial<ClientOptions> = {}, events: object[] = [frame], ends = false) {
  const written: string[] = []
  const timers: Array<{ ms: number; fire: () => void }> = []
  const opened = { count: 0 }
  const fetchFake = (async (input: string | URL | Request): Promise<Response> => {
    if (!String(input).includes('/events')) return new Response('{}')
    opened.count++
    const body = events.map(e => `data: ${JSON.stringify(e)}\n\n`).join('')
    const stream = new ReadableStream<Uint8Array>({
      start(controller) {
        controller.enqueue(new TextEncoder().encode(body))
        if (ends) controller.close()
      },
    })
    return new Response(stream, { headers: { 'content-type': 'text/event-stream' } })
  }) as typeof fetch
  const made = startClient({ url: 'http://127.0.0.1:1/?t=x', size: () => ({ columns: 40, rows: 10 }), theme: 'light', write: bytes => written.push(bytes), fetch: fetchFake, copy: () => undefined, quit: () => undefined, suspend: () => undefined, after: (ms, fire) => timers.push({ ms, fire }), ...more })
  clients.push(made)
  const last = (ms: number) => timers.filter(t => t.ms === ms).at(-1)!
  return { client: made, written, timers, opened, last, text: () => strip(written.join('')) }
}

const clients: Client[] = []
afterEach(() => {
  for (const made of clients.splice(0)) made.close()
})
const settle = () => new Promise(resolve => setTimeout(resolve, 30))

describe('startClient when the stream is lost', () => {
  it('takes a stream that went silent as lost: it says it is reconnecting and opens it again', async () => {
    const h = client({}, [frame, { type: 'alive' }])
    await settle()
    // The first look finds the frame heard; the next finds nothing since, as when the server was suspended.
    h.last(SILENT_MS).fire()
    expect(h.text()).not.toContain(RECONNECTING)
    h.last(SILENT_MS).fire()
    expect(h.text()).toContain(RECONNECTING)
    h.last(500).fire()
    await settle()
    expect(h.opened.count).toBe(2)
    // Open again: the next frame clears the line.
    expect(strip(h.written.at(-1)!)).not.toContain(RECONNECTING)
  })

  it('never takes a stream for lost when its server sends no keep-alive events, as one from before them does', async () => {
    const h = client()
    await settle()
    for (let i = 0; i < 5; i++) h.last(SILENT_MS).fire()
    expect(h.text()).not.toContain(RECONNECTING)
    expect(h.opened.count).toBe(1)
  })

  it('tells its host when the server says the pane moved', async () => {
    let moved = 0
    client({ moved: () => moved++ }, [frame, { type: 'moved', port: 41234 }])
    await settle()
    expect(moved).toBe(1)
  })

  it('says it is reconnecting, waits with the host, and stops on close', async () => {
    const h = client({}, [frame], true)
    await settle()
    expect(h.text()).toContain(RECONNECTING)
    expect(h.timers.map(t => t.ms)).toContain(500)
    h.last(500).fire()
    await settle()
    expect(h.opened.count).toBe(2)
    h.client.close()
    h.written.length = 0
    h.client.input('2')
    h.client.status('late')
    expect(h.written).toEqual([])
  })
})
