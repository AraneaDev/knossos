import { describe, expect, it } from 'vitest'
import { CARD_ICON, CERTAINTY, CHANGE, KIND, PLACE, RANKING, REACH, SEVERITY, SHARED, kindLegend, kindMark, legend, mark, signed } from './marks'
import { ACCENT, STATUS_COLOURS } from './palette'

const families = { SEVERITY, CERTAINTY, REACH, CHANGE, PLACE, RANKING }

describe('the mark registry', () => {
  it('gives each glyph one meaning, apart from the overlaps that mean the same', () => {
    const byGlyph = new Map<string, Set<string>>()
    for (const family of Object.values(families)) for (const m of Object.values(family)) byGlyph.set(m.glyph, new Set([...(byGlyph.get(m.glyph) ?? []), m.meaning]))
    for (const [glyph, meanings] of byGlyph) if (!SHARED.includes(glyph)) expect([...meanings], glyph).toHaveLength(1)
  })

  it('never uses a card icon as a row mark, apart from the overlaps', () => {
    const marks = new Set(Object.values(families).flatMap(f => Object.values(f).map(m => m.glyph)))
    for (const [card, icon] of Object.entries(CARD_ICON)) if (!SHARED.includes(icon)) expect(marks.has(icon), `${card} ${icon}`).toBe(false)
  })

  it('colours a mark by its tone and never by colour alone', () => {
    expect(mark(SEVERITY.error)).toEqual({ text: '✗', color: STATUS_COLOURS.alert })
    expect(mark(REACH.testsOnly, ' ')).toEqual({ text: '◐ ', color: STATUS_COLOURS.warn })
    expect(mark(CERTAINTY.possible)).toEqual({ text: '○', dim: true })
    expect(mark(CERTAINTY.probable)).toEqual({ text: '●' })
    expect(mark(PLACE.centre)).toEqual({ text: '◉', color: ACCENT })
  })

  it('marks a known kind in two cells and leaves an unknown one blank in the same two', () => {
    expect(kindMark('class')).toEqual({ text: 'C ', dim: true })
    expect(kindMark('method')).toEqual({ text: 'm ', dim: true })
    expect(kindMark('mystery')).toEqual({ text: '  ' })
    expect(kindMark(undefined)).toEqual({ text: '  ' })
    // A kind named like an Object.prototype member is no kind the registry holds.
    for (const odd of ['constructor', 'toString', '__proto__', 'hasOwnProperty']) expect(kindMark(odd), odd).toEqual({ text: '  ' })
    // Every kind the graph holds has a letter.
    for (const kind of ['variable', 'object', 'package', 'type_alias', 'middleware', 'namespace']) expect(kindMark(kind).text.trim(), kind).not.toBe('')
    expect(KIND.route?.glyph).toBe('R')
  })

  it('builds a legend of the marks shown, in the order given, leaving out zero counts', () => {
    expect(legend([[CERTAINTY.probable, 25], [REACH.testsOnly, 17]])).toBe('● 25 probable · ◐ 17 tests only')
    expect(legend([[CERTAINTY.probable, 0], [REACH.testsOnly, 3]])).toBe('◐ 3 tests only')
    expect(legend([[CERTAINTY.probable, 0]])).toBe('')
    expect(legend([[RANKING.hotspotOnly, '']])).toBe('✦ hotspot only')
    expect(legend([[SEVERITY.error, 1], [SEVERITY.warn, 2]])).toBe('✗ 1 error · ▲ 2 warnings')
  })

  it('names the kinds shown, in the registry\'s order, each once, unknown ones left out', () => {
    expect(kindLegend(['method', 'class', 'method', 'mystery', 'enum'])).toBe('C class · N enum · m method')
    expect(kindLegend(['mystery', 'constructor'])).toBe('')
  })

  it('writes a delta as a signed number', () => {
    expect(signed(3)).toBe('+3')
    expect(signed(-2)).toBe('−2')
    expect(signed(0)).toBe('±0')
    expect(signed(1234)).toBe('+1,234')
  })
})
