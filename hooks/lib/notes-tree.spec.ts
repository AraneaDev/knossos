import { describe, expect, it } from 'vitest'
import { firstOpen, labelOf, notesTree, noteName } from './notes-tree'
import type { NoteItem } from './notes-tree'

const note = (component: string, over: Partial<NoteItem> = {}): NoteItem => ({
  component,
  label: component.split(/\\|::|\//).at(-1) ?? component,
  author: 'model',
  kind: 'note',
  value: `About ${component}.`,
  line: `About ${component}.`,
  date: '2026-10-16',
  updated: '2026-10-16T10:00:00Z',
  missing: false,
  stale: false,
  staleReason: null,
  checked: false,
  checkReason: null,
  suggested: null,
  path: `src/${component.split('\\').at(-1)}.php`,
  boundary: 'Query',
  ...over,
})

const items = [
  note('App\\Query\\Cache', { path: 'src/Query/Cache.php', updated: '2026-10-16T09:00:00Z' }),
  note('App\\Query\\Drift\\Probe', { path: 'src/Query/Drift/Probe.php', updated: '2026-10-16T08:00:00Z' }),
  note('hooks/lib/layout.ts', { path: 'hooks/lib/layout.ts', boundary: 'hooks', updated: '2026-10-16T11:00:00Z', stale: true }),
  note('App\\Gone', { path: null, boundary: null, missing: true, updated: '2026-10-15T10:00:00Z' }),
  note('App\\Store\\Db', { path: 'src/Store/Db.php', boundary: 'Store', checked: true, checkReason: 'still?', updated: '2026-10-14T10:00:00Z' }),
]

describe('the notes as a tree', () => {
  it('names a file note on a file that is gone by its path, never by its extension', () => {
    const gone = note('workers/python/bin/knossos_python/fixtures.py', { label: labelOf('workers/python/bin/knossos_python/fixtures.py'), path: null, missing: true })
    expect(noteName(gone)).toBe('workers/python/bin/knossos_python/fixtures.py')
    expect(labelOf('workers/python/bin/knossos_python/fixtures.py')).toBe('fixtures.py')
    expect(labelOf('.github/workflows/quality.yml')).toBe('quality.yml')
    // A dotted module name and a symbol keep their own last part.
    expect(labelOf('workers.python.bin.knossos_python.module_index.ProjectModuleIndex::_declare')).toBe('_declare')
    expect(labelOf('hooks/lib/envelopes.ts#betweenTypes')).toBe('betweenTypes')
    expect(labelOf('App\\Query\\Cache')).toBe('Cache')
  })

  it('names a file note by its path and a symbol by its short name', () => {
    expect(noteName(items[2]!)).toBe('hooks/lib/layout.ts')
    expect(noteName(items[0]!)).toBe('Cache')
    // A symbol in a TypeScript file by its own name, after the file's `#`.
    expect(noteName(note('hooks/lib/envelopes.ts#betweenTypes', { label: labelOf('hooks/lib/envelopes.ts#betweenTypes'), path: 'hooks/lib/envelopes.ts' }))).toBe('betweenTypes')
  })

  it('opens first the boundary that holds the newest note', () => {
    expect(firstOpen(items)).toBe('hooks')
    expect(firstOpen([])).toBeNull()
  })

  it('puts what needs the person first, a note on a missing name and a flagged one; stale alone does not', () => {
    const lines = notesTree(items, [], false)
    const needs = lines.filter(l => l.kind === 'needs').map(l => (l.kind === 'needs' ? l.item.component : ''))
    expect(needs).toEqual(['App\\Gone', 'App\\Store\\Db'])
    // They stay in the tree too, under where they live.
    expect(notesTree(items, ['notes:b+:Store'], false).some(l => l.kind === 'note' && l.item.component === 'App\\Store\\Db')).toBe(true)
  })

  it('lists boundaries by name, the notes with none last, each folded but the first open one, its folders open', () => {
    // By the name a person reads, not the boundary's kind before it.
    const kinds = notesTree([note('A\\x', { boundary: 'namespace:Knossos\\Boundary' }), note('B\\y', { boundary: 'hooks' })], [], false)
    expect(kinds.filter(l => l.kind === 'boundary').map(l => (l.kind === 'boundary' ? l.name : null))).toEqual(['namespace:Knossos\\Boundary', 'hooks'])
    const lines = notesTree(items, [], false)
    expect(lines.filter(l => l.kind === 'boundary').map(l => (l.kind === 'boundary' ? [l.name, l.count, l.open] : null))).toEqual([
      ['hooks', 1, true],
      ['Query', 2, false],
      ['Store', 1, false],
      [null, 1, false],
    ])
    const open = lines.slice(lines.findIndex(l => l.kind === 'boundary' && l.name === 'hooks') + 1)
    expect(open[0]).toMatchObject({ kind: 'folder', path: 'hooks/lib', open: true })
    expect(open[1]).toMatchObject({ kind: 'note', item: { component: 'hooks/lib/layout.ts' } })
  })

  it('flips a group from its default by its key: a closed boundary opens, an open folder closes', () => {
    const lines = notesTree(items, ['notes:b+:Query', 'notes:f:Query:src/Query'], false)
    const query = lines.slice(lines.findIndex(l => l.kind === 'boundary' && l.name === 'Query'))
    expect(query[0]).toMatchObject({ open: true, fold: 'notes:b-:Query' })
    expect(query[1]).toMatchObject({ kind: 'folder', path: 'src/Query', open: false, fold: 'notes:f:Query:src/Query' })
    expect(query[2]).toMatchObject({ kind: 'folder', path: 'src/Query/Drift', open: true })
    expect(query[3]).toMatchObject({ kind: 'note', item: { component: 'App\\Query\\Drift\\Probe' } })
    // The first open boundary closes the same way.
    expect(notesTree(items, ['notes:b-:hooks'], false).find(l => l.kind === 'boundary' && l.name === 'hooks')).toMatchObject({ open: false })
  })

  it('keeps a boundary as the person left it when the newest note moves elsewhere', () => {
    // Query opened by hand, hooks closed by hand: the line's key says which way, and the opposite key it replaces.
    const lines = notesTree(items, ['notes:b+:Query', 'notes:b-:hooks'], false)
    expect(lines.find(l => l.kind === 'boundary' && l.name === 'Query')).toMatchObject({ open: true, fold: 'notes:b-:Query', contrary: 'notes:b+:Query' })
    expect(lines.find(l => l.kind === 'boundary' && l.name === 'hooks')).toMatchObject({ open: false, fold: 'notes:b+:hooks', contrary: 'notes:b-:hooks' })
    // The model writes a newer note in Query: it stays open, and hooks stays closed.
    const moved = [...items, note('App\\Query\\New', { path: 'src/Query/New.php', updated: '2026-10-17T09:00:00Z' })]
    expect(notesTree(moved, ['notes:b+:Query'], false).find(l => l.kind === 'boundary' && l.name === 'Query')).toMatchObject({ open: true })
    expect(notesTree(moved, ['notes:b-:hooks'], false).find(l => l.kind === 'boundary' && l.name === 'hooks')).toMatchObject({ open: false })
    // And Query, now first, closes by hand the same way.
    expect(notesTree(moved, ['notes:b-:Query'], false).find(l => l.kind === 'boundary' && l.name === 'Query')).toMatchObject({ open: false })
  })

  it('puts a note on no file straight under its boundary, with no folder row', () => {
    const lines = notesTree(items, ['notes:b+:'], false)
    const none = lines.slice(lines.findIndex(l => l.kind === 'boundary' && l.name === null) + 1)
    expect(none).toHaveLength(1)
    expect(none[0]).toMatchObject({ kind: 'note', item: { component: 'App\\Gone' } })
  })

  it('opens every group while searching, so every match shows', () => {
    const lines = notesTree(items, ['notes:b-:hooks'], true)
    expect(lines.filter(l => (l.kind === 'boundary' || l.kind === 'folder') && !l.open)).toEqual([])
    expect(lines.filter(l => l.kind === 'note')).toHaveLength(items.length)
  })

  it('draws nothing for no notes', () => {
    expect(notesTree([], [], false)).toEqual([])
  })
})
