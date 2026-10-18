import { describe, expect, it } from 'vitest'
import { cardPlace } from './place'

describe('cardPlace', () => {
  const pane = { rows: 20, columns: 80 }
  it('goes under its row when the rows below can hold it', () => {
    expect(cardPlace(3, 10, { rows: 4, columns: 30 }, pane)).toEqual({ row: 4, column: 10 })
  })
  it('goes over its row when the rows below cannot hold it and the rows above can', () => {
    expect(cardPlace(18, 10, { rows: 4, columns: 30 }, pane)).toEqual({ row: 14, column: 10 })
  })
  it('stays under its row when neither side can hold it', () => {
    expect(cardPlace(2, 0, { rows: 19, columns: 10 }, pane)).toEqual({ row: 3, column: 0 })
  })
  it('never passes the right edge, nor the left', () => {
    expect(cardPlace(3, 70, { rows: 4, columns: 30 }, pane).column).toBe(50)
    expect(cardPlace(3, 5, { rows: 4, columns: 100 }, pane).column).toBe(0)
  })
})
