import { describe, expect, it } from 'vitest'
import type { Row } from '../hooks/lib/layout'
import { button } from '../hooks/lib/rows'
import { themeColour } from '../hooks/lib/themes'
import { cardPlace } from '../hooks/mod/frame'
import { pageRow } from './page-rows'
import type { Colour } from './page-rows'

const dark: Colour = key => themeColour(key, 'dark')

const seat = { row: 0, rows: 1 }

describe('pageRow', () => {
  it("resolves each segment's look as textStyle has it: its colour, else inactive when dim, bold, its ground", () => {
    const row: Row = { key: 'a', segments: [{ text: 'dim', dim: true }, { text: 'red', color: 'error', bold: true, bg: 'userMessageBackground' }, { text: 'plain' }] }
    expect(pageRow(row, dark, 80, seat).segments.map(s => s.look)).toEqual([
      { color: themeColour('inactive', 'dark') },
      { color: themeColour('error', 'dark'), background: themeColour('userMessageBackground', 'dark'), bold: true },
      {},
    ])
  })

  it("labels a press as its Button is labelled: the laid-out text less its hotkey, the full name as its title", () => {
    const cut = { ...button('row:0', 'a very long name', 'o'), text: 'o: a very…' }
    const [s] = pageRow({ key: 'r', segments: [cut] }, dark, 80, seat).segments
    expect(s!.press).toEqual({ id: 'row:0', label: 'a very…', title: 'a very long name', hotkey: 'o' })
    const twin = pageRow({ key: 't', segments: [{ text: '', hidden: true, press: { id: 'tabkey:hubs', label: 'Hubs', hotkey: '2' } }] }, dark, 80, seat).segments[0]!
    expect(twin.hidden).toBe(true)
    expect(pageRow({ key: 'd', segments: [button('rescan', 'rescan', 'r', { dim: true })] }, dark, 80, seat).segments[0]!.press!.dim).toBe(true)
  })

  it('gives a field the room Claude Code gives it, the blank cells after it its own', () => {
    const row: Row = { key: 'f', segments: [{ text: 'find ' }, { text: '', field: { id: 'finder', value: 'ro', placeholder: 'a name' } }, { text: '     ' }, { text: '│' }] }
    const drawn = pageRow(row, dark, 40, seat)
    expect(drawn.segments.map(s => s.text)).toEqual(['find ', '', '│'])
    // 40 cells, less the 5 before it and the frame's 1 after it.
    expect(drawn.segments[1]!.field).toEqual({ id: 'finder', value: 'ro', placeholder: 'a name', width: 34 })
  })

  it("hangs a card as its lines' text, never wider than the pane", () => {
    const card = { key: 'card-0', width: 50, rows: [{ key: 'c0', segments: [{ text: 'Router', bold: true }, { text: ' · App' }] }, { key: 'c1', segments: [{ text: '12 dependents' }] }] }
    const [s] = pageRow({ key: 'h', segments: [{ text: 'Router', preview: card }] }, dark, 40, seat).segments
    expect(s!.card).toEqual({ key: 'card-0', width: 40, lines: ['Router · App', '12 dependents'], at: { row: 0, column: 0 }, place: { row: 1, column: 0 } })
  })

  it("carries the cell a card is placed in: where Claude Code's rule puts it for the segment's row and column", () => {
    const card = { key: 'card-0', width: 20, rows: [{ key: 'c0', segments: [{ text: 'a' }] }, { key: 'c1', segments: [{ text: 'b' }] }] }
    const row: Row = { key: 'h', segments: [{ text: 'ab' }, { text: 'Router', preview: card }] }
    // Row 9 of 10 cannot hold two rows below it: over it, and the card's 20 columns clamp to the 30-column pane.
    const [, s] = pageRow(row, dark, 30, { row: 9, rows: 10 }).segments
    expect(s!.card!.at).toEqual({ row: 9, column: 2 })
    expect(s!.card!.place).toEqual(cardPlace(9, 2, { rows: 2, columns: 20 }, { rows: 10, columns: 30 }))
    expect(s!.card!.place).toEqual({ row: 7, column: 2 })
  })

  it('draws a diff as the lines of its hunks, numbered by the side each is on, on the diff grounds', () => {
    const source = '@@ -9,3 +9,3 @@ heading\n kept\n-old\n+new\n\\ No newline at end of file'
    const drawn = pageRow({ key: 'diff-0', segments: [], code: { source, path: 'a.php' } }, dark, 80, seat)
    expect(drawn.segments).toEqual([])
    expect(drawn.gutter).toEqual({ color: themeColour('inactive', 'dark') })
    expect(drawn.lines).toEqual([
      { gutter: ' 9', text: '  kept', look: {} },
      { gutter: '10', text: '- old', look: { background: themeColour('diffRemoved', 'dark') } },
      { gutter: '10', text: '+ new', look: { background: themeColour('diffAdded', 'dark') } },
    ])
  })
})
