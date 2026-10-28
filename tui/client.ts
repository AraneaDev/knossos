/**
 * The terminal's client of the pane: the web page's own `createPage` (the
 * event stream, the focus ring, the hotkeys, the inputs it posts) with a
 * terminal where the document would be. Terminal input is decoded into the
 * events the page already understands, frames are painted as lines, and what
 * only a terminal has to decide (the field being edited, the wheel's scroll
 * of a pinned body, the card in view, the toast) is kept here.
 */
import { TOAST_MS, createPage, pressOfKey, pressOrder, releasedOn, segmentsOf, wheelQueue } from '../web/public/app.mjs'
import type { Frame } from '../web/hub'
import type { PageSegment } from '../web/page-rows'
import { Decoder } from './input'
import type { KeyEvent, MouseEvent, TermEvent } from './input'
import { bodyLineOf, bytesFor, hitsOf, linesOf, pinnedFor, roomOf, screenOf } from './paint'
import type { Hit, Overlay, Screen } from './paint'
import { FetchEventSource } from './stream'

/** How long input stays quiet before an unfinished escape is the Escape key. */
const ESCAPE_MS = 30
/** How long a paste stays quiet before it is taken to have ended without its end marker. */
const PASTE_MS = 1000
/** What a lost event stream says while the page waits to open it again. */
const RECONNECTING = 'Reconnecting to the server...'

/** What the client needs from its host. `url` is the server's address with `?t=<token>`. */
export type ClientOptions = {
  url: string
  size(): { columns: number; rows: number }
  theme: 'dark' | 'light'
  write(bytes: string): void
  fetch: typeof fetch
  copy(text: string): void
  quit(): void
  /** Ctrl-Z: give the terminal back as a stopped job would. */
  suspend(): void
  after(ms: number, fire: () => void): void
}

/** What the host calls: input as it arrives, the terminal resized, the end, a persistent line to show (null clears it), and a toast that goes by itself. */
export type Client = { input(chunk: string): void; resize(): void; close(): void; status(text: string | null): void; toast(text: string): void }

/** The page as far as the client touches it. */
type Page = {
  frame: Frame | null
  focused: string | null
  post(input: Record<string, unknown>): Promise<Response>
  focusedOn(element: string): void
  key(event: { key: string; ctrlKey: boolean; metaKey: boolean; altKey: boolean; inField: boolean }): { focus?: string; press?: string } | null
  connect(): void
}

/** The field being edited: its id, its text and the cursor's place in it (a string index). */
type Editing = { id: string; value: string; cursor: number }

/** The start of the character before `at` in `text`. */
const before = (text: string, at: number): number => (at >= 2 && /[\udc00-\udfff]/.test(text[at - 1]!) ? at - 2 : Math.max(0, at - 1))
/** The end of the character at `at` in `text`. */
const after = (text: string, at: number): number => (at + 2 <= text.length && /[\ud800-\udbff]/.test(text[at]!) ? at + 2 : Math.min(text.length, at + 1))

/** `text` as a field takes it: line breaks and tabs are spaces, and no other control character gets in. */
// eslint-disable-next-line no-control-regex -- finding control characters is what it is for
const printable = (text: string): string => text.replace(/\r\n?|\n|\t/g, ' ').replace(/[\u0000-\u001f\u007f-\u009f]/g, '')

/** Whether `frame` has a press or a field: something a key typed while the pane starts can act on. */
const actionable = (frame: Frame): boolean => (segmentsOf(frame) as PageSegment[]).some(s => s.press !== undefined || s.field !== undefined)

/** The most events kept for a frame with something to act on: typing on a loading pane is not stored without end. */
const EARLY_MAX = 64

/** The value of every field of `frame`, by its id. */
const fieldsOf = (frame: Frame): Map<string, string> => new Map((segmentsOf(frame) as PageSegment[]).flatMap(s => (s.field === undefined ? [] : [[s.field.id, s.field.value] as const])))

/** Starts the client: opens the event stream and paints what the server draws. */
export function startClient(o: ClientOptions): Client {
  const origin = new URL(o.url).origin
  const token = new URL(o.url).searchParams.get('t') ?? ''
  const decoder = new Decoder()

  let closed = false
  let previous: Screen | null = null
  let editing: Editing | null = null
  let scroll = 0
  let hovered: string | null = null
  let toast: string | null = null
  let toastId = 0
  let persistent: string | null = null
  let down: string | null = null
  let lastInput = 0
  /**
   * Whether the pane has yet to draw a frame with anything to press or edit (the first ones say the graph is
   * loading). Until then keys wait in `early` and are handed on once one does; after that nothing waits.
   */
  let holding = true
  const early: TermEvent[] = []
  /** The hits of the screen last painted: what the pointer is over. */
  let hits: Hit[][] = []

  const streams = new Set<FetchEventSource>()
  const size = () => o.size()
  const frameOf = (): Frame | null => page.frame

  /** The card to show: the one under the pointer, else the focused press's. */
  function cardKey(): string | null {
    if (hovered !== null) return hovered
    const focus = page.focused
    if (focus === null) return null
    return hits.flat().find(h => h.press === focus && h.card !== undefined)?.card?.key ?? null
  }

  /** The most the pinned body scrolls: its lines beyond the room above the footer. */
  function maxScroll(frame: Frame): number {
    return Math.max(0, linesOf(frame).body - roomOf(frame, size()))
  }

  /** Moves the scroll of a pinned body just far enough to bring the press or field `id` into the window. */
  function reveal(id: string | null | undefined): void {
    const frame = frameOf()
    if (frame === null || id === null || id === undefined || !pinnedFor(frame, size())) return
    const line = bodyLineOf(frame, id)
    if (line === null) return
    const room = roomOf(frame, size())
    if (line < scroll) scroll = line
    else if (line >= scroll + room) scroll = line - room + 1
    scroll = Math.min(scroll, maxScroll(frame))
  }

  /** Paints the frame again with what changed since the last paint. */
  function paint(): void {
    const frame = frameOf()
    if (closed || frame === null) return
    scroll = Math.min(scroll, maxScroll(frame))
    hits = hitsOf(frame, size(), scroll)
    const overlay: Overlay = { focused: page.focused, field: editing, toast: toast ?? persistent, card: cardKey(), scroll }
    const screen = screenOf(frame, size(), overlay)
    o.write(bytesFor(previous, screen))
    previous = screen
  }

  /** A toast shown for {@link TOAST_MS}, or until another replaces it. */
  function showToast(text: string): void {
    const id = ++toastId
    toast = text
    paint()
    o.after(TOAST_MS, () => {
      if (id !== toastId) return
      toast = null
      paint()
    })
  }

  /** The event stream the page opens, with the server's address put in front and the loss of it told on the toast line. */
  class Stream {
    private readonly inner: FetchEventSource | null
    constructor(path: string) {
      this.inner = closed ? null : new FetchEventSource(`${origin}${path}`, { fetch: o.fetch })
      if (this.inner !== null) streams.add(this.inner)
    }
    set onmessage(handler: (m: { data: string }) => void) {
      if (this.inner === null) return
      this.inner.onmessage = m => {
        if (persistent === RECONNECTING) persistent = null
        handler(m)
      }
    }
    set onerror(handler: () => void) {
      if (this.inner === null) return
      const inner = this.inner
      inner.onerror = () => {
        streams.delete(inner)
        if (closed) return
        persistent = RECONNECTING
        paint()
        handler()
      }
    }
    close(): void {
      if (this.inner === null) return
      streams.delete(this.inner)
      this.inner.close()
    }
  }

  const page = createPage({
    EventSource: Stream,
    fetch: (path: string, init?: RequestInit) => o.fetch(`${origin}${path}`, init),
    token,
    id: crypto.randomUUID(),
    size: () => ({ cols: size().columns, rows: size().rows }),
    theme: () => o.theme,
    after: o.after,
    draw: (frame: Frame) => {
      // A field that left the frame is no longer edited.
      if (editing !== null && !fieldsOf(frame).has(editing.id)) editing = null
      paint()
      if (holding && actionable(frame)) {
        holding = false
        for (const e of early.splice(0)) handle(e)
      }
    },
    on: {
      toast: (e: { text: string }) => showToast(e.text),
      // A terminal cannot tell whether the copy arrived, so the text is shown too, as the page shows it when the copy fails.
      copy: (e: { text: string }) => {
        o.copy(e.text)
        showToast(`Copy it from here: ${e.text}`)
      },
      scroll: (e: { key?: string | null }) => {
        reveal(e.key)
        paint()
      },
      focus: (e: { key?: string | null }) => {
        const frame = frameOf()
        const value = e.key === undefined || e.key === null || frame === null ? undefined : fieldsOf(frame).get(e.key)
        if (value !== undefined && editing?.id !== e.key) editing = { id: e.key!, value, cursor: value.length }
        else if (value === undefined) editing = null
        reveal(e.key)
        paint()
      },
    },
  }) as Page
  // The page's posts are fire and forget: one the network refuses is dropped, as a browser's is.
  const post = page.post.bind(page)
  page.post = input => post(input).catch(() => undefined as unknown as Response)

  /** Posts the edit that made `value` the field's text. */
  function typed(field: Editing): void {
    void page.post({ kind: 'type', field: field.id, value: field.value })
  }

  /** Puts `text` into the field being edited at the cursor. */
  function insert(text: string): void {
    text = printable(text)
    if (editing === null || text === '') return
    editing = { ...editing, value: editing.value.slice(0, editing.cursor) + text + editing.value.slice(editing.cursor), cursor: editing.cursor + text.length }
    typed(editing)
    paint()
  }

  /** Leaves the field and steps the focus ring by `by`. */
  function leaveAndStep(by: 1 | -1): void {
    editing = null
    const done = page.key({ key: by > 0 ? 'ArrowDown' : 'ArrowUp', ctrlKey: false, metaKey: false, altKey: false, inField: false })
    if (done?.focus !== undefined) reveal(done.focus)
    paint()
  }

  /** A key typed in the field being edited. */
  function fieldKey(field: Editing, e: KeyEvent): void {
    if (e.ctrlKey || e.altKey) return
    const { value, cursor } = field
    switch (e.key) {
      case 'Enter':
        void page.post({ kind: 'submit', field: field.id, value })
        return
      case 'Escape': {
        editing = null
        const first = pressOrder(page.frame)[0]
        if (first !== undefined) page.focusedOn(first)
        paint()
        return
      }
      case 'ArrowUp':
      case 'ArrowDown':
        return leaveAndStep(e.key === 'ArrowDown' ? 1 : -1)
      case 'Tab':
        return leaveAndStep(e.shiftKey ? -1 : 1)
      case 'ArrowLeft':
        editing = { ...field, cursor: before(value, cursor) }
        return paint()
      case 'ArrowRight':
        editing = { ...field, cursor: after(value, cursor) }
        return paint()
      case 'Home':
        editing = { ...field, cursor: 0 }
        return paint()
      case 'End':
        editing = { ...field, cursor: value.length }
        return paint()
      case 'Backspace': {
        if (cursor === 0) return
        const from = before(value, cursor)
        editing = { ...field, value: value.slice(0, from) + value.slice(cursor), cursor: from }
        typed(editing)
        return paint()
      }
      case 'Delete': {
        if (cursor >= value.length) return
        editing = { ...field, value: value.slice(0, cursor) + value.slice(after(value, cursor)) }
        typed(editing)
        return paint()
      }
      default:
        if ([...e.key].length === 1) insert(e.key)
    }
  }

  /** A key typed outside a field. */
  function pageKey(e: KeyEvent): void {
    // `q` quits, unless the frame gives it to a press of its own (`q: scan it`): then the press is what was asked for.
    if (e.key === 'q' && !e.ctrlKey && !e.altKey && pressOfKey(page.frame, e) === null) return o.quit()
    const ring = e.key === 'Tab' ? (e.shiftKey ? 'ArrowUp' : 'ArrowDown') : e.key
    const done = page.key({ key: ring, ctrlKey: e.ctrlKey, metaKey: false, altKey: e.altKey, inField: false })
    if (done?.focus !== undefined) reveal(done.focus)
    // Enter or Space on a focused press presses it, as a click with no pointer does in the page.
    if (done === null && (e.key === 'Enter' || e.key === ' ') && !e.ctrlKey && !e.altKey && page.focused !== null && pressOrder(page.frame).includes(page.focused)) {
      void page.post({ kind: 'press', press: page.focused })
    }
    paint()
  }

  /** The press, field or card at a cell of the screen, if any. */
  function hitAt(row: number, column: number) {
    return hits[row]?.find(h => column >= h.column && column < h.column + h.width)
  }

  /** `by` rows of wheel at a cell; the rows the pane did not scroll move a pinned body here. */
  const wheel = wheelQueue(
    (at: Record<string, unknown>) => page.post({ kind: 'wheel', ...at }),
    (by: number) => {
      const frame = frameOf()
      if (frame === null || !pinnedFor(frame, size())) return
      scroll = Math.min(maxScroll(frame), Math.max(0, scroll + by))
      paint()
    },
  ) as (by: number, row: number, column: number) => void

  /** The mouse over the pane. */
  function mouse(e: MouseEvent): void {
    // A row of the body is counted from the body's first line, whatever it is scrolled to.
    if (e.action === 'wheel') {
      const frame = frameOf()
      if (frame !== null) wheel(e.by, e.row < roomOf(frame, size()) ? e.row + scroll : e.row, e.column)
      return
    }
    const hit = hitAt(e.row, e.column)
    if (e.action === 'move') {
      const card = hit?.card?.key ?? null
      if (card !== hovered) {
        hovered = card
        paint()
      }
      return
    }
    if (e.button !== 0) return
    if (e.action === 'down') {
      down = hit?.press ?? null
      // A press elsewhere leaves the field being edited, and focuses what it lands on, as the page's focusin does.
      if (editing !== null && editing.id !== hit?.field) editing = null
      if (hit?.field !== undefined) {
        const value = fieldsOf(frameOf()!).get(hit.field) ?? ''
        page.focusedOn(hit.field)
        if (editing?.id !== hit.field) editing = { id: hit.field, value, cursor: value.length }
      } else if (hit?.press !== undefined) page.focusedOn(hit.press)
      paint()
      return
    }
    const press = releasedOn(down, hit?.press)
    down = null
    if (press !== null) void page.post({ kind: 'press', press })
  }

  /** One decoded event from the terminal. */
  function handle(e: TermEvent): void {
    if (e.kind === 'key' && e.ctrlKey && e.key === 'c') return o.quit()
    if (e.kind === 'key' && e.ctrlKey && e.key === 'z') return o.suspend()
    if (holding) {
      // `q` needs nothing to press: a pane whose server never draws can still be left.
      if (e.kind === 'key' && e.key === 'q' && !e.ctrlKey && !e.altKey) return o.quit()
      // A click is aimed at what is drawn now; replayed on a later layout it could press something else.
      if (e.kind !== 'mouse' && early.length < EARLY_MAX) early.push(e)
      return
    }
    if (e.kind === 'mouse') return mouse(e)
    if (e.kind === 'paste') return insert(e.text)
    if (e.kind !== 'key') return
    if (editing === null) pageKey(e)
    else fieldKey(editing, e)
  }

  page.connect()

  return {
    input(chunk) {
      if (closed) return
      for (const e of decoder.push(chunk)) handle(e)
      // A lone Escape is told from the start of a sequence by what follows it, or the lack of it.
      const stamp = ++lastInput
      o.after(ESCAPE_MS, () => {
        if (closed || stamp !== lastInput) return
        for (const e of decoder.flush()) handle(e)
      })
      // A paste whose end never comes would take every key after it, Ctrl-C too.
      o.after(PASTE_MS, () => {
        if (closed || stamp !== lastInput) return
        for (const e of decoder.endPaste()) handle(e)
      })
    },
    resize() {
      if (closed) return
      previous = null
      const now = size()
      void page.post({ kind: 'resize', cols: now.columns, rows: now.rows })
      paint()
    },
    close() {
      closed = true
      for (const stream of streams) stream.close()
    },
    status(text) {
      persistent = text
      paint()
    },
    toast(text) {
      if (!closed) showToast(text)
    },
  }
}
