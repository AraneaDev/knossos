import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { CARD_ICON, CERTAINTY, CHANGE, NOTE, PLACE, RANKING, REACH, SEVERITY } from './marks'

/**
 * A registry glyph written anywhere but the registry is a meaning the
 * registry cannot vouch for: the next change could give it a second one.
 * ASCII glyphs (+ ~ and the kind letters) are left out, since they are also
 * ordinary characters in code.
 */
/** The arrows between two names, which are also the used-by and uses card icons: drawing, not marks. */
const DRAWING = ['→', '←']

const glyphs = [SEVERITY, CERTAINTY, REACH, CHANGE, PLACE, RANKING, NOTE]
  .flatMap(f => Object.values(f).map(m => m.glyph))
  .concat(Object.values(CARD_ICON))
  .filter(g => [...g].some(ch => ch.codePointAt(0)! > 0x7f) && !DRAWING.includes(g))

const HERE = fileURLToPath(new URL('.', import.meta.url))
const sources = [...readdirSync(HERE).filter(f => f.endsWith('.ts') && !f.endsWith('.spec.ts') && f !== 'marks.ts').map(f => join(HERE, f)), join(HERE, '..', 'register.tsx')]

describe('mark usage', () => {
  it('writes every registry glyph only in the registry', () => {
    const found: string[] = []
    for (const file of sources) {
      readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
        if (/^\s*(\/\*\*|\*|\/\/)/.test(line)) return
        for (const g of glyphs) if (line.includes(g)) found.push(`${file.split('/').pop()}:${i + 1} ${g}`)
      })
    }
    expect(found).toEqual([])
  })
})
