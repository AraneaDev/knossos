import { describe, expect, it } from 'vitest'
import { cardPlace } from '../hooks/lib/place'
import { cells } from '../hooks/lib/text'
import type { Frame } from '../web/hub'
import type { PageRow, PageSegment } from '../web/page-rows'
import { bytesFor, hitsOf, linesOf, screenOf } from './paint'
import type { Overlay } from './paint'

const colours = { ground: '#101020', text: '#c0c0d0', card: '#303050', suggestion: '#ffaa00' }
const none: Overlay = { focused: null, field: null, toast: null, card: null, scroll: 0 }
const ESC = String.fromCharCode(27)
const strip = (line: string): string => line.replace(new RegExp(`${ESC}\\[[0-9;?]*[A-Za-z]`, 'g'), '')
const seg = (text: string, more: Partial<PageSegment> = {}): PageSegment => ({ text, look: {}, ...more })
const row = (key: string, ...segments: PageSegment[]): PageRow => ({ key, segments })
const frame = (body: PageRow[], footer: PageRow[] = [], pinned = false): Frame => ({ type: 'frame', body, footer, pinned, theme: 'dark', colours })

describe('screenOf', () => {
  it('fills every line to the terminal width on the ground', () => {
    const screen = screenOf(frame([row('a', seg('hello'))]), { columns: 10, rows: 2 }, none)
    expect(strip(screen.lines[0]!)).toBe('hello     ')
    expect(screen.lines[0]).toContain('48;2;16;16;32')
    expect(screen.lines[0]).toContain('38;2;192;192;208')
    expect(strip(screen.lines[1]!)).toBe(' '.repeat(10))
  })

  it("places segments by the frame's columns, not their length", () => {
    const screen = screenOf(frame([row('a', seg('界', { columns: 2 }), seg('x', { columns: 1 }))]), { columns: 6, rows: 1 }, none)
    const text = strip(screen.lines[0]!)
    expect(text.startsWith('界x')).toBe(true)
    expect(cells(text)).toBe(6)
  })

  it('cuts a row at the terminal width and never wraps', () => {
    const screen = screenOf(frame([row('a', seg('x'.repeat(30)))]), { columns: 20, rows: 3 }, none)
    expect(screen.lines).toHaveLength(3)
    expect(cells(strip(screen.lines[0]!))).toBe(20)
  })

  it('paints a segment look as 24-bit colour and bold', () => {
    const screen = screenOf(frame([row('a', seg('hi', { look: { color: '#ff0000', background: '#00ff00', bold: true } }))]), { columns: 4, rows: 1 }, none)
    expect(screen.lines[0]).toContain('\x1b[0m\x1b[1m\x1b[38;2;255;0;0;48;2;0;255;0mhi')
  })

  it('draws a press with its hotkey in the suggestion colour, and without one as its label', () => {
    const hubs = seg('Hubs', { columns: 7, press: { id: 'tab:hubs', hotkey: '2', label: 'Hubs', title: 'Hubs' } })
    const plain = seg('Back', { columns: 4, press: { id: 'back', label: 'Back', title: 'Back' } })
    const hidden = seg('zz', { columns: 2, hidden: true, press: { id: 'z', hotkey: 'z', label: 'z', title: 'z' } })
    const screen = screenOf(frame([row('a', hubs, hidden, plain)]), { columns: 12, rows: 1 }, none)
    expect(strip(screen.lines[0]!)).toBe('2: HubsBack ')
    expect(screen.lines[0]).toContain('38;2;255;170;0;48;2;16;16;32m2')
    expect(screen.lines[0]).not.toContain('\x1b[7m')
  })

  it('draws the focused press in reverse', () => {
    const hubs = seg('Hubs', { columns: 7, press: { id: 'tab:hubs', hotkey: '2', label: 'Hubs', title: 'Hubs' } })
    const screen = screenOf(frame([row('a', hubs)]), { columns: 8, rows: 1 }, { ...none, focused: 'tab:hubs' })
    expect(strip(screen.lines[0]!)).toBe('2: Hubs ')
    expect(screen.lines[0]).toContain('\x1b[7m')
  })

  it("shows the focused field's own value and puts the cursor in it", () => {
    const find = seg('', { field: { id: 'find', value: 'old', placeholder: 'search', width: 8 } })
    const lead = seg('> ', { columns: 2 })
    const plain = screenOf(frame([row('a', lead, find)]), { columns: 12, rows: 1 }, none)
    expect(strip(plain.lines[0]!)).toBe('> old       ')
    expect(plain.cursor).toBeNull()
    const edited = screenOf(frame([row('a', lead, find)]), { columns: 12, rows: 1 }, { ...none, focused: 'find', field: { id: 'find', value: 'abc', cursor: 2 } })
    expect(strip(edited.lines[0]!)).toBe('> abc       ')
    expect(edited.cursor).toEqual({ row: 0, column: 4 })
  })

  it('shows the placeholder, faint, for an empty field and keeps the cursor in a long value', () => {
    const find = seg('', { field: { id: 'find', value: '', placeholder: 'search', width: 8 } })
    const empty = screenOf(frame([row('a', find)]), { columns: 8, rows: 1 }, none)
    expect(strip(empty.lines[0]!)).toBe('search  ')
    expect(empty.lines[0]).toContain('\x1b[2m')
    const long = screenOf(frame([row('a', find)]), { columns: 8, rows: 1 }, { ...none, field: { id: 'find', value: 'abcdefghijkl', cursor: 12 } })
    expect(strip(long.lines[0]!)).toBe('fghijkl ')
    expect(long.cursor).toEqual({ row: 0, column: 7 })
  })

  it('draws a diff row as gutter, a space and the text of each line in its look', () => {
    const diff: PageRow = { key: 'd', segments: [], gutter: { color: '#888888' }, lines: [{ gutter: '1', text: '+ a', look: { background: '#003300' } }, { gutter: '2', text: '- b', look: {} }] }
    const screen = screenOf(frame([diff]), { columns: 8, rows: 2 }, none)
    expect(strip(screen.lines[0]!)).toBe('1 + a   ')
    expect(strip(screen.lines[1]!)).toBe('2 - b   ')
    expect(screen.lines[0]).toContain('48;2;0;51;0')
    expect(linesOf(frame([diff, row('x')], [row('f')]))).toEqual({ body: 3, footer: 1 })
  })

  it('keeps the footer at the bottom and scrolls the body above it when pinned', () => {
    const body = Array.from({ length: 10 }, (_, i) => row(`b${i}`, seg(`body${i}`)))
    const screen = screenOf(frame(body, [row('f', seg('footer'))], true), { columns: 8, rows: 5 }, { ...none, scroll: 3 })
    expect(screen.lines.map(l => strip(l).trim())).toEqual(['body3', 'body4', 'body5', 'body6', 'footer'])
    const past = screenOf(frame(body, [row('f', seg('footer'))], true), { columns: 8, rows: 5 }, { ...none, scroll: 99 })
    expect(strip(past.lines[0]!).trim()).toBe('body6')
  })

  it('lays a toast over the last body line', () => {
    const body = Array.from({ length: 10 }, (_, i) => row(`b${i}`, seg(`body${i}`)))
    const screen = screenOf(frame(body, [row('f', seg('footer'))], true), { columns: 10, rows: 4 }, { ...none, toast: 'copied' })
    expect(strip(screen.lines[2]!)).toBe(' copied   ')
    expect(screen.lines[2]).toContain('\x1b[7m')
    expect(strip(screen.lines[3]!).trim()).toBe('footer')
  })

  it('hangs the card where cardPlace puts it', () => {
    const card = { key: 'k', width: 6, lines: ['one', 'two'] }
    const rows = [row('a', seg('x')), row('b', seg('ab', { columns: 2, card })), row('c', seg('c'))]
    const size = { columns: 10, rows: 6 }
    const screen = screenOf(frame(rows), size, { ...none, card: 'k' })
    const at = cardPlace(1, 0, { rows: 2, columns: 6 }, size)
    expect(strip(screen.lines[at.row]!).slice(at.column, at.column + 6)).toBe('one   ')
    expect(strip(screen.lines[at.row + 1]!).slice(at.column, at.column + 6)).toBe('two   ')
    expect(screen.lines[at.row]).toContain('48;2;48;48;80')
    expect(strip(screenOf(frame(rows), size, none).lines[at.row]!).trim()).toBe('c')
  })
})

describe('hitsOf', () => {
  it('maps the cells of presses and cards to their lines', () => {
    const press = { id: 'p', label: 'Go', title: 'Go' }
    const card = { key: 'k', width: 4, lines: ['c'] }
    const hits = hitsOf(frame([row('a', seg('--', { columns: 2 }), seg('Go', { columns: 2, press, card }))]), { columns: 10, rows: 2 }, 0)
    expect(hits[0]).toEqual([{ column: 2, width: 2, press: 'p', card }])
    expect(hits[1]).toEqual([])
  })
})

describe('bytesFor', () => {
  const size = { columns: 4, rows: 3 }
  const a = screenOf(frame([row('a', seg('one')), row('b', seg('two'))]), size, none)
  it('writes every line first, then hides the cursor', () => {
    const out = bytesFor(null, a)
    expect(out).toContain('\x1b[1;1H')
    expect(out).toContain('\x1b[3;1H')
    expect(out.endsWith('\x1b[?25l')).toBe(true)
  })
  it('writes only the cursor when nothing changed', () => {
    expect(bytesFor(a, a)).toBe('\x1b[?25l')
    expect(bytesFor(a, { ...a, cursor: { row: 1, column: 2 } })).toBe('\x1b[2;3H\x1b[?25h')
  })
  it('writes only the line that changed, at its place', () => {
    const b = screenOf(frame([row('a', seg('one')), row('b', seg('TWO'))]), size, none)
    const out = bytesFor(a, b)
    expect(out.startsWith('\x1b[2;1H')).toBe(true)
    expect(strip(out)).toBe('TWO ')
    expect(out).not.toContain('\x1b[1;1H')
  })
})
