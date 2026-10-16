import { describe, expect, it } from 'vitest'
import { dotGlyph, grown, MOTION_MS, moving, NO_MOTION, spinGlyph } from './motion'

const landed = (frame: number) => ({ since: 1_000, frame })

describe('motion after a scan', () => {
  it('moves only within its moment after a scan lands, and never without one', () => {
    expect(moving(NO_MOTION, 5_000)).toBe(false)
    expect(moving(landed(0), 1_100)).toBe(true)
    expect(moving(landed(0), 1_000 + MOTION_MS)).toBe(false)
  })

  it('breathes the live dot frame by frame in that moment, and holds it still after', () => {
    expect(dotGlyph(landed(0), 1_100)).not.toBe(dotGlyph(landed(1), 1_100))
    expect(dotGlyph(landed(1), 1_000 + MOTION_MS + 1)).toBe(dotGlyph(NO_MOTION, 0))
  })

  it('grows a bar to its width over the first frames, and draws it whole otherwise', () => {
    expect(grown(40, landed(0), 1_100)).toBeLessThan(grown(40, landed(3), 1_100))
    expect(grown(40, landed(3), 1_100)).toBeLessThan(40)
    expect(grown(40, landed(6), 1_100)).toBe(40)
    expect(grown(40, NO_MOTION, 0)).toBe(40)
    expect(grown(40, landed(0), 1_000 + MOTION_MS)).toBe(40)
    expect(grown(0, landed(0), 1_100)).toBe(0)
  })

  it('turns the spinner one step a frame, round its cycle', () => {
    const steps = Array.from({ length: 8 }, (_, i) => spinGlyph(i))
    expect(new Set(steps).size).toBe(8)
    expect(spinGlyph(8)).toBe(spinGlyph(0))
  })
})
