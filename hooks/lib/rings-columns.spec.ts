import { describe, expect, it } from 'vitest'
import { ringRows, ringsList } from './rings'
import { rawText } from './__tests__/plain-text'
import { threeRings } from './__tests__/ring-fixture'
import { rowWidth } from './rows'

const drawn = (columns: number, selected = -1) => ringRows(threeRings(), columns, selected, 0)
const presses = (columns: number, selected = -1) => drawn(columns, selected).flatMap(r => r.segments).flatMap(s => (s.press === undefined ? [] : [s.press.id]))

describe('the rings, members in columns', () => {
  // The card's inner width at a 60, 100 and 140 column pane.
  it('draws three nested rings, the furthest outermost, the component in the middle (56 columns: a 60 column pane)', () => {
    expect(drawn(56).map(rawText)).toEqual([
      "╭─ 3+ hops · 40 ────────── ✓ 37 tested · ▲ 3 untested ─╮",
      "│ ▲ m Further0  ■ tooling  ▲ C Further1                │",
      "│ ▲ C Further2               C Further3  ■ tooling     │",
      "│   m Further4             +35 more                    │",
      "│ ✓ 22 test files  Further0Test.php +21                │",
      "│ ╭─ 2 hops · 1 ────────────────────── ✓ all tested ─╮ │",
      "│ │   m AnExtraordinarilyLongComponentNameThatFills… │ │",
      "│ │ ✓ 1 test file  LongTest.php                      │ │",
      "│ │ ╭─ 1 hop · 4 (1 probable) ─────────────────────╮ │ │",
      "│ │ │ ✓ 3 tested · ▲ 1 untested                    │ │ │",
      "│ │ │ ▲ C Near0 via make                           │ │ │",
      "│ │ │   C Near1          ■ edge                    │ │ │",
      "│ │ │ +2 more                                      │ │ │",
      "│ │ │ ✓ 2 test files  NearTest.php, FarTest.php    │ │ │",
      "│ │ │            ◉ Importer::run ■ core            │ │ │",
      "│ │ ╰──────────────────────────────────────────────╯ │ │",
      "│ ╰──────────────────────────────────────────────────╯ │",
      "╰──────────────────────────────────────────────────────╯",
    ])
  })

  it('lays a ring out in as many columns as its longest name lets the width hold (96 columns: a 100 column pane)', () => {
    expect(drawn(96).map(rawText)).toEqual([
      "╭─ 3+ hops · 40 ────────────────────────────────────────────────── ✓ 37 tested · ▲ 3 untested ─╮",
      "│ ▲ m Further0  ■ tooling  ▲ C Further1             ▲ C Further2                               │",
      "│   C Further3  ■ tooling    m Further4               C Further5                               │",
      "│   C Further6  ■ tooling    C Further7             +32 more                                   │",
      "│ ✓ 22 test files  Further0Test.php, Further1Test.php, Further2Test.php, Further3Test.php +18  │",
      "│ ╭─ 2 hops · 1 ────────────────────────────────────────────────────────────── ✓ all tested ─╮ │",
      "│ │   m AnExtraordinarilyLongComponentNameThatFillsItsRing::andItsMethod                     │ │",
      "│ │ ✓ 1 test file  LongTest.php                                                              │ │",
      "│ │ ╭─ 1 hop · 4 (1 probable) ───────────────────────────────── ✓ 3 tested · ▲ 1 untested ─╮ │ │",
      "│ │ │ ▲ C Near0 via make           C Near1          ■ edge    m Near2                      │ │ │",
      "│ │ │   f Near3                                                                            │ │ │",
      "│ │ │ ✓ 2 test files  NearTest.php, FarTest.php                                            │ │ │",
      "│ │ │                                ◉ Importer::run ■ core                                │ │ │",
      "│ │ ╰──────────────────────────────────────────────────────────────────────────────────────╯ │ │",
      "│ ╰──────────────────────────────────────────────────────────────────────────────────────────╯ │",
      "╰──────────────────────────────────────────────────────────────────────────────────────────────╯",
    ])
  })

  it('gives a wider pane more columns, and the long-named ring still one (136 columns: a 140 column pane)', () => {
    expect(drawn(136).map(rawText)).toEqual([
      "╭─ 3+ hops · 40 ────────────────────────────────────────────────────────────────────────────────────────── ✓ 37 tested · ▲ 3 untested ─╮",
      "│ ▲ m Further0  ■ tooling  ▲ C Further1             ▲ C Further2               C Further3  ■ tooling    m Further4                     │",
      "│   C Further5               C Further6  ■ tooling    C Further7               m Further8               C Further9  ■ tooling          │",
      "│   C Further10              C Further11            +28 more                                                                           │",
      "│ ✓ 22 test files  Further0Test.php, Further1Test.php, Further2Test.php, Further3Test.php, Further4Test.php, Further5Test.php +16      │",
      "│ ╭─ 2 hops · 1 ────────────────────────────────────────────────────────────────────────────────────────────────────── ✓ all tested ─╮ │",
      "│ │   m AnExtraordinarilyLongComponentNameThatFillsItsRing::andItsMethod                                                             │ │",
      "│ │ ✓ 1 test file  LongTest.php                                                                                                      │ │",
      "│ │ ╭─ 1 hop · 4 (1 probable) ───────────────────────────────────────────────────────────────────────── ✓ 3 tested · ▲ 1 untested ─╮ │ │",
      "│ │ │ ▲ C Near0 via make           C Near1          ■ edge    m Near2                    f Near3                                   │ │ │",
      "│ │ │ ✓ 2 test files  NearTest.php, FarTest.php                                                                                    │ │ │",
      "│ │ │                                                    ◉ Importer::run ■ core                                                    │ │ │",
      "│ │ ╰──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────╯ │ │",
      "│ ╰──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────╯ │",
      "╰──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────╯",
    ])
  })

  it("marks the selected member with '›' in the cell before its mark, moving no column or border", () => {
    // The walk is nearest first: Near0 is 0, Near1 is 1, Further0 is 5.
    const marked = (columns: number, selected: number, key: string) => rawText(drawn(columns, selected).find(r => r.key === key)!)
    expect(marked(96, 0, 'ring-0-names-0')).toBe('│ │ │›▲ C Near0 via make           C Near1          ■ edge    m Near2                      │ │ │')
    expect(marked(96, 1, 'ring-0-names-0')).toBe('│ │ │ ▲ C Near0 via make        ›  C Near1          ■ edge    m Near2                      │ │ │')
    expect(marked(136, 5, 'ring-2-names-0')).toBe('│›▲ m Further0  ■ tooling  ▲ C Further1             ▲ C Further2               C Further3  ■ tooling    m Further4                     │')
    expect(marked(56, 1, 'ring-0-names-1')).toBe('│ │ │›  C Near1          ■ edge                    │ │ │')
    // The row is the unmarked one with one cell changed, at every width, for each member that shows unmarked (a hidden one moves the window).
    for (const columns of [44, 56, 96, 136]) {
      for (const selected of [0, 1, 2, 5, 6].filter(n => presses(columns).includes(`rel:${n}`))) {
        const plain = drawn(columns).map(rawText)
        const rows = drawn(columns, selected).map(rawText)
        rows.forEach((text, i) => {
          const changed = [...text].filter((ch, j) => ch !== [...plain[i]!][j])
          expect(changed.join(''), `${columns} ${selected} ${i}`).toMatch(/^›?$/)
        })
        expect(rows.join('\n').split('›').length - 1, `${columns} ${selected}`).toBeLessThanOrEqual(1)
      }
    }
  })

  it('closes every box at one column: each frame row as wide as the top edge, at every width', () => {
    for (const columns of [44, 50, 56, 70, 96, 136, 196]) {
      const rows = drawn(columns)
      expect(new Set(rows.map(rowWidth)), `${columns}`).toEqual(new Set([columns]))
      // Each ring's borders stand at one column on every row it spans, the same indent on either side.
      for (let depth = 0; depth < 3; depth++) {
        const ring = 2 - depth
        const from = rows.findIndex(r => r.key === `ring-${ring}-top`)
        const to = rows.findIndex(r => r.key === `ring-${ring}-end`)
        for (const t of rows.slice(from, to + 1).map(r => [...rawText(r)])) {
          expect(t[depth * 2], `${columns} ${t.join('')}`).toMatch(/[│╭╰]/)
          expect(t[t.length - 1 - depth * 2], `${columns} ${t.join('')}`).toMatch(/[│╮╯]/)
        }
      }
    }
  })

  it('puts the marks, the names and the boundaries each in one line down a ring', () => {
    const rows = drawn(96).map(rawText).filter(t => /Further\d/.test(t))
    for (const column of [0, 1, 2]) {
      const at = rows.map(t => [...t.matchAll(/[▲ ] [mCf] Further/g)][column]?.index).filter(i => i !== undefined)
      expect(new Set(at).size, `column ${column}`).toBe(1)
    }
    expect(new Set(rows.flatMap(t => [...t.matchAll(/■ tooling/g)].map(m => m.index))).size).toBe(1)
  })

  it('names every member it shows by a press in the walk order of the list, and the hidden by one more press', () => {
    const list = ringsList(threeRings())
    const shown = presses(96).filter(id => id.startsWith('rel:')).map(id => Number(id.slice(4)))
    // Each press opens the walk's own item: ring by ring, nearest first, as the list walks them.
    for (const i of shown) expect(list[i]).toBeDefined()
    expect([...shown].sort((a, b) => a - b)).toEqual([0, 1, 2, 3, 4, ...Array.from({ length: 8 }, (_, i) => 5 + i)])
    // The furthest ring hides four it lists: its `+N more` walks to the first of them.
    expect(presses(96)).toContain('more:13')
  })

  it('tints the marked member where it stands, and brings one the cells hid into them', () => {
    const marked = drawn(96, 2).flatMap(r => r.segments).find(s => s.press?.id === 'rel:2')
    expect(marked).toMatchObject({ bg: 'userMessageBackground', bold: true })
    expect(presses(96)).not.toContain('rel:16')
    expect(presses(96, 16)).toContain('rel:16')
    expect(drawn(96, 16).flatMap(r => r.segments).find(s => s.press?.id === 'rel:16')).toMatchObject({ bg: 'userMessageBackground', bold: true })
  })
})
