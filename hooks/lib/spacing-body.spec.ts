import { describe, expect, it } from 'vitest'
import { arrange } from './cards'
import type { Block } from './cards'
import { rawText } from './__tests__/plain-text'
import { rowWidth } from './rows'
import { atBody, bodyLine, bodyRow, bodyText, gutter, GUTTER, isBlank, toBody } from './spacing'
import type { Row } from './types'

const texts = (rows: Row[]) => rows.map(r => r.segments.map(s => s.text).join(''))

describe('the body and its gutter, one way', () => {
  it('fills the gutter with the marker, the row mark and the cell that keeps the mark off the body', () => {
    expect(gutter().map(s => s.text).join('')).toBe('   ')
    expect(gutter(true).map(s => s.text).join('')).toBe('›  ')
    expect(gutter(true, { text: '▲' }).map(s => s.text).join('')).toBe('›▲ ')
    expect(gutter(false, { text: '✗' }).map(s => s.text).join('')).toBe(' ✗ ')
    expect(gutter(true).reduce((n, s) => n + s.text.length, 0)).toBe(GUTTER)
  })

  it('moves text in to the body, cut or wrapped to the card, a mark in the gutter before its first line', () => {
    expect(texts([bodyRow('a', [{ text: 'x' }])])).toEqual(['   x'])
    expect(texts(toBody([{ key: 'a', segments: [{ text: 'x' }] }]))).toEqual(['   x'])
    expect(atBody([{ text: 'y' }]).map(s => s.text)).toEqual(['   ', 'y'])
    expect(texts([bodyLine('a', 'a sentence too long for the card', 20)])).toEqual(['   a sentence too l…'])
    const wrapped = bodyText('said', 'No git repository, or git did not answer.', 24, { dim: true }, { text: '▲' })
    expect([wrapped.map(r => r.key), texts(wrapped)]).toEqual([
      ['said-0', 'said-1'],
      [' ▲ No git repository, or', '   git did not answer.'],
    ])
    for (const r of wrapped) expect(rowWidth(r)).toBeLessThanOrEqual(24)
  })
})

describe('the height a wide grid leaves', () => {
  /** A card with `n` rows of body. */
  const card = (key: string, n: number): Block => ({ key, make: () => ({ key, title: key, body: Array.from({ length: n }, (_, i) => ({ key: `${key}-${i}`, segments: [{ text: `${key}${i}` }] })) }) })

  it('goes to the foot of the pane, under the last card, never between one grid row and the next', () => {
    for (const width of [140, 200]) {
      const rows = arrange({ left: [], rows: [[card('a', 2), card('b', 6)], [card('c', 3), card('d', 1)]] }, width, 50, true)
      const keys = rows.map(r => r.key)
      // The first grid row is as tall as its taller card, and the next one follows at once.
      const lastOfFirst = keys.findIndex(k => k.endsWith('|b-5'))
      expect(keys[lastOfFirst + 1], `${width}`).toBe('gap-c|d')
      // The cards of a row still share its height: the shorter one is stretched beside the taller.
      expect(keys.filter(k => k.startsWith('a-fill-')), `${width}`).toHaveLength(4)
      // What is left is blank rows after the last card, to the budget.
      const lastCard = keys.findIndex(k => k.startsWith('c-2|'))
      expect([rows.length, rows.slice(lastCard + 1).every(isBlank)], `${width}`).toEqual([50, true])
      for (const r of rows) expect(rowWidth(r), rawText(r)).toBeLessThanOrEqual(width)
    }
  })
})
