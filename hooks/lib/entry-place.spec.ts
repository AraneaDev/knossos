import { describe, expect, it } from 'vitest'
import { entryRow, entrySpec } from './views'
import type { Entry } from './views'
import { NO_HUES } from './palette'
import { plainText } from './__tests__/plain-text'
import { fit } from './text'

/** A dead-code row as the Issues tab builds one: its marks (4 cells) and its name in the main text, its boundary, its place. */
const dead = (name: string, place: string, boundary = 'php-worker'): Entry => ({
  need: 4 + name.length,
  marks: 4,
  main: width => [{ text: '○ ∅ ' }, { text: fit(name, width - 4) }],
  boundary,
  place,
})

describe('a place in an issue row', () => {
  const rows = [dead('FactCollector::beforeTraverse', 'workers/php/src/FactCollector.php:108'), dead('helper', 'src/Support/helper.php:4', 'core')]

  it('gives up its folders first, then the name gives up cells, so the file name and line stay whole', () => {
    // 58 columns: the inner width of a 60 column pane. The name keeps more than its floor, the file name is whole.
    expect(rows.map(e => plainText({ key: 'x', segments: entryRow('x', e, entrySpec(58, rows), NO_HUES).segments }))).toEqual([
      '   ○ ∅ FactCollector::… ■ php-worker FactCollector.php:108',
      '   ○ ∅ helper           ■ core       …Support/helper.php:4',
    ])
    // Wider, the name is whole and the place takes back its folders, up to its share of the row.
    expect(entrySpec(80, rows)).toEqual({ main: 33, boundary: 12, place: 28 })
  })

  it('cuts the file name only once the name is down to its floor, and keeps the marks and the boundary', () => {
    for (const columns of [58, 54, 50, 46]) {
      const spec = entrySpec(columns, rows)
      const file = 'FactCollector.php:108'.length
      // The name gives up cells down to its floor (twelve past its marks) before the file name loses any.
      if (spec.place < file) expect(spec.main, `${columns}`).toBe(16)
      else expect(spec.main, `${columns}`).toBeGreaterThanOrEqual(16)
      expect([spec.boundary, spec.main + spec.boundary + spec.place + 3 + 2 <= columns], `${columns}`).toEqual([12, true])
    }
    expect(entrySpec(54, rows)).toEqual({ main: 16, boundary: 12, place: 21 })
    expect(entrySpec(50, rows)).toEqual({ main: 16, boundary: 12, place: 17 })
  })
})
