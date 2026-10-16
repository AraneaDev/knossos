import { describe, expect, it } from 'vitest'
import { widthGauge } from './gauge'
import { cells, MEDIUM_MAX, MEDIUM_MIN } from './rows'

const plain = (columns: number, room: number) => widthGauge(columns, room).map(s => s.text).join('')

describe('the width gauge', () => {
  it('names the layout the pane is in, the next one, and how far it is', () => {
    expect(plain(118, 60)).toContain('medium')
    expect(plain(118, 60)).toContain('wide')
    expect(plain(118, 60)).toContain(`118/${MEDIUM_MAX + 1}`)
    expect(plain(60, 60)).toContain(`60/${MEDIUM_MIN}`)
    expect(plain(60, 60)).toMatch(/^narrow .* medium/)
  })

  it('ends at wide: the widest layout has no next one, so it says the width alone', () => {
    const wide = plain(200, 60)
    expect(wide).toMatch(/^wide /)
    expect(wide).toContain('200')
    expect(wide).not.toContain('/')
  })

  it('moves its marker along the track as the pane widens within a layout', () => {
    const at = (columns: number) => plain(columns, 60).indexOf('▮')
    expect(at(MEDIUM_MIN)).toBeLessThan(at(105))
    expect(at(105)).toBeLessThan(at(MEDIUM_MAX))
  })

  it('shrinks to the width and the layout where it has little room, and to nothing where it has less', () => {
    expect(plain(118, 14)).toBe('118 · medium')
    expect(cells(plain(118, 14))).toBeLessThanOrEqual(14)
    expect(widthGauge(118, 5)).toEqual([])
    for (const room of [0, 10, 14, 20, 40, 60]) expect(cells(plain(118, room)), `${room}`).toBeLessThanOrEqual(room)
  })
})
