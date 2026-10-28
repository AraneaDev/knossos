/**
 * The subset of `EventSource` the page uses, over `fetch`: Node has no
 * `EventSource` that works for every supported version, and a terminal client
 * must be able to hand the page a stream it controls.
 */

/** An event stream read over `fetch`: `onmessage` for each `data:` event, `onerror` once when the stream ends or fails. */
export class FetchEventSource {
  onmessage: ((m: { data: string }) => void) | null = null
  onerror: (() => void) | null = null
  private closed = false
  private readonly abort = new AbortController()

  /** Opens `url`; `init.fetch` replaces the global `fetch`. */
  constructor(url: string, init: { fetch?: typeof fetch } = {}) {
    void this.read(url, init.fetch ?? fetch)
  }

  /** Stops reading; no `onerror` follows. */
  close(): void {
    this.closed = true
    this.abort.abort()
  }

  /** Reads the stream to its end, handing each event to `onmessage`, then tells `onerror` unless closed. */
  private async read(url: string, get: typeof fetch): Promise<void> {
    try {
      const response = await get(url, { headers: { accept: 'text/event-stream' }, signal: this.abort.signal })
      if (!response.ok || response.body === null) throw new Error(`event stream answered ${response.status}`)
      const decoder = new TextDecoder()
      let text = ''
      for await (const chunk of response.body) {
        text += decoder.decode(chunk, { stream: true }).replace(/\r\n?/g, '\n')
        for (let end = text.indexOf('\n\n'); end >= 0; end = text.indexOf('\n\n')) {
          this.dispatch(text.slice(0, end))
          text = text.slice(end + 2)
        }
      }
    } catch {
      // A failed read and a finished stream are told the same way.
    }
    if (!this.closed) {
      this.closed = true
      this.onerror?.()
    }
  }

  /** One event's lines: the `data:` lines joined with a newline; an event with none (a comment) is nothing. */
  private dispatch(block: string): void {
    const data = block
      .split('\n')
      .filter(line => line.startsWith('data:'))
      .map(line => line.slice(5).replace(/^ /, ''))
    if (data.length > 0 && !this.closed) this.onmessage?.({ data: data.join('\n') })
  }
}
