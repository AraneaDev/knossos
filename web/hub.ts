/**
 * The pages connected to one pane: each is sent a frame, the pane laid out
 * at that page's own size from the one state every page shares. A burst of
 * changes draws a page at most once per {@link FRAME_MS}, and the last
 * change is always drawn. Frames are laid out one at a time, as the pane's
 * module state (its width, the rows the wheel reads) holds one layout.
 */
import { paneLayout } from '../hooks/lib/layout'
import type { Row } from '../hooks/lib/layout'
import { CARD_BG } from '../hooks/lib/palette'
import { HEAT_KEYS, rasterTheme } from '../hooks/lib/raster'
import { themeColour } from '../hooks/lib/themes'
import type { WebTheme } from '../hooks/lib/themes'
import { noteSpinners, paneSource, spunRow } from '../hooks/mod/frame'
import type { Port } from '../hooks/mod/port'
import type { PortHub } from './port'
import { mod } from '../hooks/mod/state'
import { pageRow } from './page-rows'
import type { Colour, PageRow } from './page-rows'

/** The least time between two frames to one page. */
export const FRAME_MS = 50

export type Size = { columns: number; rows: number }
/** An event for a page: a frame, or what the port passes on (a toast, a copy, a focus or scroll request). */
export type PageEvent = { type: string; [field: string]: unknown }
/**
 * What a page draws: the pane's rows ready to draw (see {@link pageRow}),
 * whether the footer stays pinned, and the page's own colours: its ground
 * and ink, a hover card's ground and a hotkey's accent, as Claude Code
 * draws a Button's.
 */
export type Frame = { type: 'frame'; body: PageRow[]; footer: PageRow[]; pinned: boolean; theme: WebTheme; colours: Record<keyof typeof PAGE_KEYS, string> }

/** The page's own colours, by what it names them: the theme key of each. */
const PAGE_KEYS = { ground: 'ground', text: 'text', card: CARD_BG, suggestion: 'suggestion' } as const

type Page = { size: Size; theme: WebTheme; send: (event: PageEvent) => void; rows: Row[]; last: number; timer: ReturnType<typeof setTimeout> | null; drawing: boolean; dirty: boolean }

/** The colour of a theme key under `theme`: the shared table, or the raster's heat steps. */
function colourIn(theme: WebTheme): Colour {
  const grid = rasterTheme(theme)
  return key => {
    if (key === undefined) return undefined
    const heat = (HEAT_KEYS as readonly string[]).includes(key) ? `#${grid[key]!.toString(16).padStart(6, '0')}` : undefined
    return themeColour(key, theme) ?? heat
  }
}

export class Hub implements PortHub {
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
      // A layout that throws leaves the page on its last frame; the server's terminal is told why.
      const drawn = await (this.queue = this.queue
        .then(() => this.frameOf(page))
        .catch((error: unknown) => {
          this.io?.ui.log(`knossos web: laying the pane out failed: ${error instanceof Error ? error.message : String(error)}`)
          return null
        }))
      if (drawn !== null && this.pages.get(id) === page) {
        page.rows = drawn.rows
        page.send(drawn.frame)
      }
    } finally {
      page.drawing = false
      if (page.dirty) this.schedule(id)
    }
  }

  /** The pane for `page`, as drawPane lays it out for Claude Code: its source, its rows at the page's size, the spinners at their frame; and the body's rows as laid out, for the wheel. */
  private async frameOf(page: Page): Promise<{ rows: Row[]; frame: Frame }> {
    const io = this.io
    if (io === null) throw new Error('the hub has no port')
    const { columns, rows } = page.size
    const source = await paneSource(io, columns, false)
    const laid = source.kind === 'pane' ? paneLayout(source.input, columns, rows, 0) : { body: source.kind === 'empty' ? source.rows : [], footer: [], pinned: false }
    await noteSpinners(io, [...laid.body, ...laid.footer])
    const colour = colourIn(page.theme)
    const ready = (row: Row) => pageRow(spunRow(row, mod.spinFrame), colour, columns)
    const colours = Object.fromEntries(Object.entries(PAGE_KEYS).map(([name, key]) => [name, colour(key)!])) as Frame['colours']
    return { rows: laid.body, frame: { type: 'frame', body: laid.body.map(ready), footer: laid.footer.map(ready), pinned: laid.pinned, theme: page.theme, colours } }
  }
}
