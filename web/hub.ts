/**
 * The pages connected to one pane: each is sent a frame, the pane laid out
 * at that page's own size from the one state every page shares. A burst of
 * changes draws a page at most once per {@link FRAME_MS}, and the last
 * change is always drawn. Frames are laid out one at a time, as the pane's
 * module state (its width, the rows the wheel reads) holds one layout.
 */
import { paneLayout } from '../hooks/lib/layout'
import type { Row } from '../hooks/lib/layout'
import { spun } from '../hooks/lib/motion'
import { HEAT_KEYS, rasterTheme } from '../hooks/lib/raster'
import { themeColour } from '../hooks/lib/themes'
import type { WebTheme } from '../hooks/lib/themes'
import { noteSpinners, paneSource } from '../hooks/mod/frame'
import type { Port } from '../hooks/mod/port'
import { mod } from '../hooks/mod/state'

/** The least time between two frames to one page. */
export const FRAME_MS = 50

export type Size = { columns: number; rows: number }
/** An event for a page: a frame, or what the port passes on (a toast, a copy, a focus or scroll request). */
export type PageEvent = { type: string; [field: string]: unknown }
/** What a page draws: the pane's rows, whether the footer stays pinned, and the colour of every theme key the rows use. */
export type Frame = { type: 'frame'; body: Row[]; footer: Row[]; pinned: boolean; theme: WebTheme; colours: Record<string, string> }

/** Keys every frame resolves, whether its rows name them or not: the default ink, dim text, and the diff's two grounds. */
const ALWAYS = ['text', 'inactive', 'diffAdded', 'diffRemoved']

type Page = { size: Size; theme: WebTheme; send: (event: PageEvent) => void; rows: Row[]; last: number; timer: ReturnType<typeof setTimeout> | null; drawing: boolean; dirty: boolean }

/** Every theme key `rows` colour something with, their hover cards' included. */
function keysOf(rows: readonly Row[], into = new Set<string>(ALWAYS)): Set<string> {
  for (const row of rows) {
    if (row.tint !== undefined) into.add(row.tint)
    for (const s of row.segments) {
      for (const key of [s.color, s.bg, s.cell?.fg, s.cell?.bg]) if (key !== undefined) into.add(key)
      if (s.preview !== undefined) keysOf(s.preview.rows, into)
    }
  }
  return into
}

/** The colour of each key under `theme`: the shared table, or the raster's heat steps. */
function coloursOf(keys: Set<string>, theme: WebTheme): Record<string, string> {
  const grid = rasterTheme(theme)
  const colours: Record<string, string> = {}
  for (const key of keys) {
    const heat = (HEAT_KEYS as readonly string[]).includes(key) ? `#${grid[key]!.toString(16).padStart(6, '0')}` : undefined
    const colour = themeColour(key, theme) ?? heat
    if (colour !== undefined) colours[key] = colour
  }
  return colours
}

/** `rows` with every line that says something is on its way at the motion's current frame. */
const spunRows = (rows: Row[]): Row[] => rows.map(row => (row.segments.some(s => spun(s.text, mod.spinFrame) !== s.text) ? { ...row, segments: row.segments.map(s => ({ ...s, text: spun(s.text, mod.spinFrame) })) } : row))

export class Hub {
  /** The theme the pages last asked for: what the port's config reports. */
  theme: WebTheme = 'dark'
  private io: Port | null = null
  private pages = new Map<string, Page>()
  /** The layouts in turn: one runs at a time. */
  private queue: Promise<unknown> = Promise.resolve()

  /** The port the frames are drawn through (made after the hub, as it tells the hub of its changes). */
  attach(io: Port): void {
    this.io = io
  }

  get connected(): boolean {
    return this.pages.size > 0
  }

  /** A page arrives (or comes back) as `id`, `size` cells big: it is sent the current frame at once. */
  connect(id: string, size: Size, send: (event: PageEvent) => void, theme: WebTheme = this.theme): void {
    const old = this.pages.get(id)
    if (old?.timer) clearTimeout(old.timer)
    this.theme = theme
    this.pages.set(id, { size, theme, send, rows: [], last: 0, timer: null, drawing: false, dirty: false })
    this.schedule(id)
  }

  /** The page `id` went away; with `send`, only when that is still how it is reached (a reload may have connected it again). */
  disconnect(id: string, send?: (event: PageEvent) => void): void {
    const page = this.pages.get(id)
    if (page === undefined || (send !== undefined && page.send !== send)) return
    if (page.timer !== null) clearTimeout(page.timer)
    this.pages.delete(id)
  }

  /** The page `id` is now `size` cells big. */
  resize(id: string, size: Size): void {
    const page = this.pages.get(id)
    if (page === undefined) return
    page.size = size
    this.schedule(id)
  }

  /** The page `id` follows `theme` now. */
  setTheme(id: string, theme: WebTheme): void {
    const page = this.pages.get(id)
    this.theme = theme
    if (page === undefined) return
    page.theme = theme
    this.schedule(id)
  }

  /** The body rows last sent to `id`: what its wheel scrolls. */
  rowsOf(id: string): Row[] {
    return this.pages.get(id)?.rows ?? []
  }

  /** The state changed: every page is drawn again. */
  changed(): void {
    for (const id of this.pages.keys()) this.schedule(id)
  }

  /** `event` to every page. */
  send(event: PageEvent): void {
    for (const page of this.pages.values()) page.send(event)
  }

  private schedule(id: string): void {
    const page = this.pages.get(id)
    if (page === undefined) return
    if (page.timer !== null || page.drawing) {
      page.dirty = true
      return
    }
    const wait = page.last + FRAME_MS - Date.now()
    if (wait <= 0) return void this.draw(id, page)
    page.timer = setTimeout(() => {
      page.timer = null
      void this.draw(id, page)
    }, wait)
  }

  private async draw(id: string, page: Page): Promise<void> {
    page.drawing = true
    page.dirty = false
    page.last = Date.now()
    try {
      const frame = await (this.queue = this.queue.then(() => this.frameOf(page)).catch(() => null))
      if (frame !== null && this.pages.get(id) === page) {
        page.rows = frame.body
        page.send(frame)
      }
    } finally {
      page.drawing = false
      if (page.dirty) this.schedule(id)
    }
  }

  /** The pane for `page`, as drawPane lays it out for Claude Code: its source, its rows at the page's size, the spinners at their frame. */
  private async frameOf(page: Page): Promise<Frame> {
    const io = this.io
    if (io === null) throw new Error('the hub has no port')
    const { columns, rows } = page.size
    const source = await paneSource(io, columns, false)
    const laid = source.kind === 'pane' ? paneLayout(source.input, columns, rows, 0) : { body: source.kind === 'empty' ? source.rows : [], footer: [], pinned: false }
    await noteSpinners(io, [...laid.body, ...laid.footer])
    const body = spunRows(laid.body)
    const footer = spunRows(laid.footer)
    return { type: 'frame', body, footer, pinned: laid.pinned, theme: page.theme, colours: coloursOf(keysOf([...body, ...footer]), page.theme) }
  }
}
