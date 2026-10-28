import { createServer } from 'node:http'
import type { Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, describe, expect, it } from 'vitest'
import { FetchEventSource } from './stream'

const servers: Server[] = []
afterEach(() => {
  for (const server of servers.splice(0)) server.closeAllConnections()
})

/** A server that answers every request by running `serve` on the response; its address. */
async function serving(serve: (write: (text: string) => void, end: () => void) => void): Promise<string> {
  const server = createServer((_, response) => {
    response.writeHead(200, { 'content-type': 'text/event-stream' })
    serve(text => response.write(text), () => response.end())
  })
  servers.push(server)
  await new Promise<void>(resolve => server.listen(0, '127.0.0.1', resolve))
  return `http://127.0.0.1:${(server.address() as AddressInfo).port}/events`
}

describe('FetchEventSource', () => {
  it('calls onmessage per event, joins multi-line data, then onerror once when the stream ends', async () => {
    const url = await serving((write, end) => {
      write('data: {"a":1}\n\n')
      write(': a comment\n\n')
      write('data: x\ndata: y\n\n')
      end()
    })
    const messages: string[] = []
    let errors = 0
    const source = new FetchEventSource(url)
    source.onmessage = m => messages.push(m.data)
    source.onerror = () => errors++
    await new Promise<void>(resolve => {
      const done = source.onerror
      source.onerror = () => {
        done?.()
        resolve()
      }
    })
    expect(messages).toEqual(['{"a":1}', 'x\ny'])
    expect(errors).toBe(1)
  })

  it('reads an event split across chunks', async () => {
    const url = await serving((write, end) => {
      write('data: par')
      setTimeout(() => {
        write('tial\r\n\r\n')
        end()
      }, 20)
    })
    const messages: string[] = []
    const source = new FetchEventSource(url)
    source.onmessage = m => messages.push(m.data)
    await new Promise<void>(resolve => (source.onerror = resolve))
    expect(messages).toEqual(['partial'])
  })

  it('tells onerror when the request fails or is refused', async () => {
    for (const get of [(async () => new Response('no', { status: 403 })) as typeof fetch, (async () => Promise.reject(new Error('down'))) as typeof fetch]) {
      const source = new FetchEventSource('http://127.0.0.1:1/events', { fetch: get })
      await new Promise<void>(resolve => (source.onerror = resolve))
    }
  })

  it('does not call onerror after close', async () => {
    const url = await serving(write => write('data: first\n\n'))
    const messages: string[] = []
    let errors = 0
    const source = new FetchEventSource(url)
    source.onerror = () => errors++
    await new Promise<void>(resolve => {
      source.onmessage = m => {
        messages.push(m.data)
        resolve()
      }
    })
    source.close()
    await new Promise(resolve => setTimeout(resolve, 50))
    expect(messages).toEqual(['first'])
    expect(errors).toBe(0)
  })
})
