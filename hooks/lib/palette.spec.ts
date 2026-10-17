import { describe, expect, it } from 'vitest'
import { ACCENT, BOUNDARY_COLOURS, FAINT, HEADING, SECONDARY, STATUS_COLOURS, STAT_HUES, TITLE, boundaryColour, boundaryLabel, declaredOf, heat, huesOf, languageColour } from './palette'

describe('boundaryColour', () => {
  it('gives a declared boundary past the first eight its own colour, even one named like an inferred one', () => {
    const items = ['tests', 'core', 'ns:Query', 'typescript-worker', 'rust-worker', 'python-worker', 'ns:Scan', 'tooling', 'ns:Cli'].map((name, i) => ({ name, members: 100 - i, source: 'explicit' }))
    const hues = huesOf({ boundaries: { items, declared: items.map(b => b.name) } } as never)
    expect(hues.has('ns:Cli')).toBe(false)
    expect(boundaryColour('ns:Cli', hues)).toBeDefined()
    // An inferred one past the first eight stays neutral.
    expect(boundaryColour('module:hooks', hues)).toBeUndefined()
  })

  it('is one of the palette, and the same for the same name every time', () => {
    const colour = boundaryColour('core')
    expect(BOUNDARY_COLOURS).toContain(colour)
    expect(boundaryColour('core')).toBe(colour)
  })
  it('spreads different names over the palette', () => {
    const names = ['core', 'tests', 'tooling', 'hooks', 'python-worker', 'rust-worker', 'php-worker', 'typescript-worker', 'Http', 'Domain']
    expect(new Set(names.map(n => boundaryColour(n))).size).toBeGreaterThanOrEqual(5)
  })
  it('leaves an unassigned component uncoloured', () => {
    expect(boundaryColour(null)).toBeUndefined()
    expect(boundaryColour(undefined)).toBeUndefined()
    expect(boundaryColour('')).toBeUndefined()
  })
  it('never hands a boundary a status colour', () => {
    for (const status of Object.values(STATUS_COLOURS)) expect(BOUNDARY_COLOURS).not.toContain(status)
  })
})

describe('theme keys', () => {
  it('draws every colour as a Claude Code theme key, never a raw colour', () => {
    const keys = [ACCENT, HEADING, SECONDARY, FAINT, ...Object.values(STATUS_COLOURS), ...BOUNDARY_COLOURS]
    for (const key of keys) expect(key).toMatch(/^[a-zA-Z_]+$/)
    expect(STATUS_COLOURS).toEqual({ ok: 'success', warn: 'warning', alert: 'error' })
    expect(BOUNDARY_COLOURS.every(k => k.endsWith('_FOR_SUBAGENTS_ONLY'))).toBe(true)
  })
})

describe('huesOf', () => {
  const d = {
    boundaries: {
      items: [
        { name: 'namespace:App', source: 'inferred', members: 900 },
        { name: 'tests', source: 'explicit', members: 400 },
        { name: 'core', source: 'explicit', members: 600 },
      ],
      truncated: false,
    },
    boundary_matrix: { boundaries: ['core', 'tests', 'module:hooks'], members: [600, 400, 50], boundaries_truncated: false, cells: [], forbidden: [], edges: 0, truncated: false, truncation_reasons: [] },
  }
  it('colours declared boundaries first, each group largest first, in the palette order', () => {
    const hues = huesOf(d)
    expect([...hues.keys()]).toEqual(['core', 'tests', 'namespace:App', 'module:hooks'])
    expect([...hues.values()]).toEqual(BOUNDARY_COLOURS.slice(0, 4))
    expect(boundaryColour('core', hues)).toBe(BOUNDARY_COLOURS[0])
  })
  it('ranks a boundary declared past the short list as declared', () => {
    const past = { ...d, boundaries: { ...d.boundaries, declared: ['core', 'tests', 'module:hooks'] } }
    expect(declaredOf(past)).toEqual(new Set(['core', 'tests', 'module:hooks']))
    expect([...huesOf(past).keys()]).toEqual(['core', 'tests', 'module:hooks', 'namespace:App'])
  })
  it('is the same whatever order the dashboard lists them in', () => {
    const shuffled = { ...d, boundaries: { ...d.boundaries, items: [...d.boundaries.items].reverse() } }
    expect([...huesOf(shuffled).entries()]).toEqual([...huesOf(d).entries()])
  })
  it('gives at most seven colours, never red; the rest are picked by name', () => {
    const many = { boundaries: { items: Array.from({ length: 11 }, (_, i) => ({ name: `b${i}`, source: 'explicit', members: 100 - i })), truncated: false } }
    const hues = huesOf(many)
    expect(hues.size).toBe(7)
    expect(new Set(hues.values()).size).toBe(7)
    expect(BOUNDARY_COLOURS).toContain(boundaryColour('b10', hues))
    expect(BOUNDARY_COLOURS.some(c => c.startsWith('red'))).toBe(false)
    // Pink, nearest the error colour in the dark themes, only for a seventh boundary.
    expect(BOUNDARY_COLOURS.at(-1)).toBe('pink_FOR_SUBAGENTS_ONLY')
  })
  it('leaves an inferred boundary past the coloured ones neutral, never red or a declared one\'s colour by chance', () => {
    const declared = Array.from({ length: 7 }, (_, i) => ({ name: `d${i}`, source: 'explicit', members: 100 - i }))
    const hues = huesOf({ boundaries: { items: [...declared, { name: 'namespace:Knossos', source: 'inferred', members: 5000 }], truncated: false } })
    expect(hues.has('namespace:Knossos')).toBe(false)
    expect(boundaryColour('namespace:Knossos', hues)).toBeUndefined()
    expect(boundaryColour('module:never-listed', hues)).toBeUndefined()
  })
  it('tells apart two labels that differ only in case, by their source', () => {
    const hues = huesOf({
      boundaries: {
        items: [
          { name: 'core', source: 'explicit', members: 10 },
          { name: 'namespace:Knossos', source: 'inferred', members: 9 },
          { name: 'composer:vendor/knossos (+node:x)', source: 'inferred', members: 8 },
          { name: 'module:hooks', source: 'inferred', members: 7 },
        ],
        truncated: false,
      },
    })
    expect(boundaryLabel('namespace:Knossos', hues)).toBe('namespace:Knossos')
    expect(boundaryLabel('composer:vendor/knossos (+node:x)', hues)).toBe('composer:knossos')
    // Labels that collide on nothing stay short.
    expect(boundaryLabel('module:hooks', hues)).toBe('hooks')
    expect(boundaryLabel('core', hues)).toBe('core')
  })
})

describe('boundaryLabel', () => {
  it('prints a declared name as written', () => expect(boundaryLabel('python-worker')).toBe('python-worker'))
  it('drops an inferred source prefix and merged siblings', () => {
    expect(boundaryLabel('module:hooks (+typescript:hooks/tsconfig.json)')).toBe('hooks')
    expect(boundaryLabel('namespace:Knossos')).toBe('Knossos')
  })
  it('keeps the last part of a scoped package', () => {
    expect(boundaryLabel('node:@araneadev/typescript-scanner')).toBe('typescript-scanner')
    expect(boundaryLabel('composer:vendor/package (+node:other)')).toBe('package')
  })
  it('is empty for none', () => {
    expect(boundaryLabel(null)).toBe('')
    expect(boundaryLabel(undefined)).toBe('')
  })
})

describe('the livelier look', () => {
    // Theme keys that mean no boundary: a boundary's colour is only ever on its own marks.
    const THEME_KEYS = new Set(['text', 'inverseText', 'inactive', 'subtle', 'suggestion', 'remember', 'success', 'error', 'warning', 'merged', 'claude', 'permission', 'planMode', 'autoAccept', 'ide', 'bashBorder'])

    it('titles cards in the orange Claude Code marks itself with', () => {
        expect(TITLE).toBe('claude')
    })

    it('gives each stat tile a theme hue, none a raw colour', () => {
        for (const hue of Object.values(STAT_HUES)) {
            expect(THEME_KEYS.has(hue), hue).toBe(true)
            expect(hue).not.toMatch(/^#/)
        }
        expect(Object.keys(STAT_HUES).sort()).toEqual(['boundaries', 'components', 'cycles', 'degree', 'drifted', 'issues'])
        // The selection's colour means the marker and the open tab: no figure wears it.
        expect(Object.values(STAT_HUES)).not.toContain(ACCENT)
    })

    it('colours each language its own hue, by its chip or its full name, and anything else as secondary text', () => {
        expect(languageColour('php')).not.toBe(languageColour('ts'))
        expect(languageColour('PHP')).toBe(languageColour('php'))
        expect(languageColour('typescript')).toBe(languageColour('ts'))
        expect(languageColour('cobol')).toBe(SECONDARY)
        for (const l of ['php', 'ts', 'js', 'py', 'rs']) expect(THEME_KEYS.has(languageColour(l)), l).toBe(true)
    })

    it('shades buckets cool to hot: the first in the suggestion blue, the last in the error colour, never cooler further on', () => {
        expect(heat(0, 5)).toBe(ACCENT)
        expect(heat(4, 5)).toBe(STATUS_COLOURS.alert)
        const order = [ACCENT, STATUS_COLOURS.ok, STATUS_COLOURS.warn, STATUS_COLOURS.alert]
        for (let i = 1; i < 9; i++) expect(order.indexOf(heat(i, 9))).toBeGreaterThanOrEqual(order.indexOf(heat(i - 1, 9)))
        expect(heat(0, 1)).toBe(ACCENT)
    })
})
