import { describe, expect, it } from 'vitest'
import { arrange, cardInner, cardRows, stackRows } from './cards'
import type { Block, Section } from './cards'
import { plainText, rawText } from './__tests__/plain-text'
import { notListedRow, rowWidth } from './rows'
import { BODY, blankRow, GRID_GAP, GUTTER, isBlank, labelledRows, MARGIN, marginOf, moreRow, trimBlanks, wrapValue } from './spacing'
import type { Fact } from './spacing'
import { MARK, tierOf } from './table'
import type { Row } from './types'

const line = (key: string, text: string): Row => ({ key, segments: [{ text }] })
const text = (value: string): Fact['lines'] => width => wrapValue(value, width).map(l => [{ text: l }])

describe('the spacing', () => {
  it('puts everything on a margin of two cells, one when narrow, and the body after a gutter of three', () => {
    expect([MARGIN, GUTTER, BODY]).toEqual([2, 3, 5])
    expect(marginOf('medium')).toBe(2)
    expect(marginOf('wide')).toBe(2)
    expect(marginOf('narrow')).toBe(1)
    // A table's marker and mark are the gutter: its names start where every body starts.
    expect(MARK).toBe(GUTTER)
    for (const columns of [60, 100, 140]) expect(cardInner(columns, tierOf(columns))).toBe(columns - 2 * marginOf(tierOf(columns)))
  })

  it('draws a blank row one way, as wide as it is asked to be', () => {
    expect(blankRow('x')).toEqual({ key: 'x', segments: [{ text: ' ' }] })
    expect(rowWidth(blankRow('x', 40))).toBe(40)
    expect(isBlank(blankRow('x', 40))).toBe(true)
    // Something pressed, tinted or drawn as cells is not blank, however it reads.
    expect(isBlank({ key: 'p', segments: [{ text: ' ', press: { id: 'a', label: 'a' } }] })).toBe(false)
    expect(isBlank({ key: 't', segments: [{ text: ' ' }], tint: 'x' })).toBe(false)
    expect(isBlank({ key: 'd', segments: [], code: { source: '@@ -1 +1 @@\n-a', path: 'a' } })).toBe(false)
  })

  it('never lets two blank rows run, nor one open or close a body', () => {
    const rows = [blankRow('a'), line('b', 'x'), blankRow('c'), blankRow('d'), line('e', 'y'), blankRow('f')]
    expect(trimBlanks(rows).map(r => r.key)).toEqual(['b', 'c', 'e'])
    expect(trimBlanks([blankRow('a')])).toEqual([])
  })
})

describe('what a list does not show', () => {
  it('is said as +N more at the body, its count grouped, dim', () => {
    const row = moreRow(1204, 'x-more')
    expect(rawText(row)).toBe('   +1,204 more')
    expect(row.segments[0]!.text).toHaveLength(GUTTER)
    expect(row.segments[1]).toMatchObject({ text: '+1,204 more', dim: true })
    expect(rawText(moreRow(3, 'x', 40, 'hubs'))).toBe('   +3 more hubs')
    expect(rowWidth(moreRow(1_000_000, 'x', 8))).toBeLessThanOrEqual(8)
    // The rows a list ends in say it the same way.
    expect(notListedRow('x', 12, 5, 40)).toEqual([moreRow(7, 'x', 40)])
  })
})

describe('labelled facts', () => {
  const facts: Fact[] = [
    { key: 'where', label: 'where', lines: text('src/Cli/Command/MaintenanceCommand.php:119') },
    { key: 'call', label: 'call', lines: text('importNotes(positionals: array): int') },
    { key: 'name', label: 'name', lines: text('Knossos\\Cli\\Command\\MaintenanceCommand::importNotes') },
  ]

  it('line their values up in one column after a dim label column, a blank row between two facts', () => {
    const rows = labelledRows(facts, 96)
    expect(rows.map(rawText)).toEqual([
      '   where   src/Cli/Command/MaintenanceCommand.php:119',
      ' ',
      '   call    importNotes(positionals: array): int',
      ' ',
      '   name    Knossos\\Cli\\Command\\MaintenanceCommand::importNotes',
    ])
    expect(rows.map(r => r.key)).toEqual(['where', 'call-gap', 'call', 'name-gap', 'name'])
    expect(rows[0]!.segments[1]).toEqual({ text: 'where   ', dim: true })
  })

  it('wrap a value too long for its column under that column, never under the label nor past the edge (60 columns)', () => {
    const inner = cardInner(60, tierOf(60))
    const long: Fact[] = [
      { key: 'where', label: 'where', lines: text(`src/${'deep/'.repeat(38)}File.php:12`) },
      { key: 'name', label: 'name', lines: text(`Knossos\\${'VeryLongNamespace\\'.repeat(6)}ClassWithNoSpacesAtAll::method`) },
      { key: 'said', label: 'said', lines: text('a sentence of plain words that runs on far past the width the value column has in a narrow pane') },
    ]
    const rows = labelledRows(long, inner)
    const column = rawText(rows[0]!).indexOf('src/')
    expect(column).toBe(GUTTER + 'where'.length + 3)
    for (const r of rows) {
      expect(rowWidth(r), rawText(r)).toBeLessThanOrEqual(inner)
      // Every row is its label (first row) or blank up to the value column, then the value.
      if (!isBlank(r)) expect(rawText(r).slice(0, column).trim(), r.key).toMatch(/^(where|name|said|)$/)
      if (!isBlank(r)) expect(rawText(r).charAt(column), r.key).not.toBe(' ')
    }
    // Nothing was cut: each value reads whole down its column.
    const value = (key: string) => rows.filter(r => r.key === key || r.key.startsWith(`${key}-`) && !r.key.endsWith('-gap')).map(r => rawText(r).slice(column)).join('')
    expect(value('where')).toBe(`src/${'deep/'.repeat(38)}File.php:12`)
    expect(value('name')).toBe(`Knossos\\${'VeryLongNamespace\\'.repeat(6)}ClassWithNoSpacesAtAll::method`)
    // A name with no spaces breaks after a separator, so each piece ends on a whole segment.
    expect(rows.filter(r => r.key.startsWith('name') && !r.key.endsWith('gap')).slice(0, -1).every(r => /[\\:]$/.test(rawText(r)))).toBe(true)
    expect(rows.find(r => r.key === 'said-1')).toBeDefined()
  })
})

describe('the card frame', () => {
  const card = (key: string, body: Row[]): Section => ({ key, title: key, body })
  const block = (section: Section): Block => ({ key: section.key, make: () => section })

  for (const columns of [60, 100, 140]) {
    it(`stands a card on the margin with one blank row above its title and one below, never two (${columns} columns)`, () => {
      const tier = tierOf(columns)
      const margin = marginOf(tier)
      // A body that opens and closes on blank rows of its own, with two running inside it.
      const rows = stackRows([card('a', [blankRow('a-0'), line('a-1', 'x'), blankRow('a-2'), blankRow('a-3'), line('a-4', 'y'), blankRow('a-5')]), card('b', [line('b-0', 'z')])], columns, tier)
      expect(rows.map(r => (isBlank(r) ? '_' : r.key.endsWith('-head') ? 'T' : 'x')).join('')).toBe('_T_x_x_T_x')
      for (const r of rows.filter(r => r.key.endsWith('-head'))) expect(rawText(r)).toMatch(new RegExp(`^ {${margin}}── \\S.* ── {${margin}}$|^ {${margin}}── \\S.*── {${margin}}$`))
      for (const r of rows.filter(r => !isBlank(r) && !r.key.endsWith('-head'))) expect(rawText(r).search(/\S/)).toBe(margin)
      for (const r of rows) expect(rowWidth(r) === columns || isBlank(r), r.key).toBe(true)
    })
  }

  it('sets the count at the right end of the title rule', () => {
    const [head] = cardRows({ key: 'c', title: 'Blast radius', note: [{ text: '17 dependents' }], body: [] }, 100, 'medium')
    expect(rawText(head!)).toMatch(/^ {2}── Blast radius ─+ 17 dependents ── {2}$/)
    expect(plainText(head!)).toMatch(/^Blast radius +17 dependents$/)
  })

  it('stands two cards side by side with their rules four cells apart, at every wide width', () => {
    expect(GRID_GAP).toBe(4)
    for (const columns of [131, 140, 200]) {
      const rows = arrange({ left: [block(card('l', [line('l-0', 'x')]))], right: [block(card('r', [line('r-0', 'y'), line('r-1', 'z')]))] }, columns, 40)
      const head = rows.find(r => r.key === 'l-head|r-head')!
      expect(rawText(head)).toMatch(/── {4}──/)
      expect(rawText(head).indexOf('──')).toBe(MARGIN)
      // The shorter card runs out in blank space: no blank row doubled where it ends, and no row past the pane.
      for (const r of rows) expect(rowWidth(r)).toBeLessThanOrEqual(columns)
    }
  })
})
