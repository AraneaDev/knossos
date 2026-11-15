import { afterEach, describe, expect, it } from 'vitest'
import type { Frame } from '../web/hub'
import { startClient } from './client'
import type { Client } from './client'

const ESC = String.fromCharCode(27)
const press = { text: '2: tab:hubs', look: {}, columns: 11, press: { id: 'tab:hubs', label: 'tab:hubs', title: 'tab:hubs', hotkey: '2' } }
const frame: Frame = { type: 'frame', body: [{ key: 'tabs', segments: [press] }], footer: [], pinned: true, theme: 'dark', colours: { ground: '#101020', text: '#c0c0d0', card: '#303050', suggestion: '#ffaa00' } }
/** The theme's ground, #101020, as an SGR background. */
const THEME_GROUND = '48;2;16;16;32'

/** A dark client over a fake server whose /events sends one frame and stays open; nothing told it the terminal's background. */
function client() {
  const written: string[] = []
  const posts: string[] = []
  const fetchFake = (async (input: string | URL | Request, init?: RequestInit): Promise<Response> => {
    if (!String(input).includes('/events')) {
      posts.push(String(init?.body))
      return new Response('{}')
    }
    const stream = new ReadableStream<Uint8Array>({
      start(controller) {
        controller.enqueue(new TextEncoder().encode(`data: ${JSON.stringify(frame)}\n\n`))
      },
    })
    return new Response(stream, { headers: { 'content-type': 'text/event-stream' } })
  }) as typeof fetch
  const made = startClient({ url: 'http://127.0.0.1:1/?t=x', size: () => ({ columns: 40, rows: 10 }), theme: 'dark', write: bytes => written.push(bytes), fetch: fetchFake, copy: () => undefined, quit: () => undefined, suspend: () => undefined, after: () => undefined })
  clients.push(made)
  return { client: made, written, posts }
}

const clients: Client[] = []
afterEach(() => {
  for (const made of clients.splice(0)) made.close()
})
const settle = () => new Promise(resolve => setTimeout(resolve, 30))

describe("startClient and the terminal's background", () => {
  it("takes a late reply that fits the theme and paints the whole pane on it, or the theme's ground again when it does not", async () => {
    const h = client()
    await settle()
    // No reply at the start: every line on the theme's ground.
    expect(h.written.join('')).toContain(THEME_GROUND)
    const count = h.written.length
    h.client.input(`${ESC}]11;rgb:0d0d/1111/1717${ESC}\\`)
    const repainted = h.written.slice(count).join('')
    // Every line again, none of them on the theme's ground: the pane lies on the terminal's own colour.
    expect(repainted.match(new RegExp(`${ESC}\\[\\d+;1H`, 'g'))).toHaveLength(10)
    expect(repainted).not.toContain(THEME_GROUND)
    // The same colour again changes nothing.
    h.client.input(`${ESC}]11;rgb:0d0d/1111/1717\x07`)
    expect(h.written.slice(count).join('')).toBe(repainted)
    // A light terminal under the dark theme: its text would vanish there, so the ground is painted again.
    h.client.input(`${ESC}]11;rgb:ffff/ffff/ffff\x07`)
    expect(h.written.at(-1)).toContain(THEME_GROUND)
    // A reply is never input for the pane.
    expect(h.posts).toEqual([])
  })
})
