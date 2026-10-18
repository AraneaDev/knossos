import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

import type { KnossosView } from '../../types'
import { cellChange, INITIAL } from './cells'

describe('cells', () => {
  it('declares an atom in register.tsx for every cell, and no other', () => {
    const source = readFileSync(join(__dirname, '..', 'register.tsx'), 'utf8')
    const declared = new Set([...source.matchAll(/atom\(\{ plugin: 'knossos', key: '(\w+)' \}/g)].map(m => m[1]))
    expect(declared).toEqual(new Set(Object.keys(INITIAL)))
  })

  it("keeps the view's scrolls only while marker, tab and open item stay", () => {
    const at: KnossosView = { ...INITIAL.view, tab: 'overview', scrolls: { a: 3 } }
    const moved = cellChange('view', v => ({ ...v, tab: 'hubs' }))(at)
    expect(moved.tab).toBe('hubs')
    expect(moved.scrolls).toBeUndefined()
    expect(cellChange('view', v => ({ ...v, showKeys: true }))(at).scrolls).toEqual({ a: 3 })
  })

  it('hands every other cell its change unchanged', () => {
    const change = (v: boolean) => !v
    expect(cellChange('untestedOnly', change)).toBe(change)
  })
})
