/**
 * The notes as a tree of where they live: what needs the person first (a
 * note on a name the graph no longer holds, and one the person flagged for
 * the model), then each boundary, its folders, and their notes.
 *
 * Pure. Every group is folded or open by default (a boundary folded, but the
 * one holding the newest note; a folder open). A folder's key in the view's
 * folds flips its default; a boundary's key says which way the person set it
 * (`notes:b+:` open, `notes:b-:` closed), since its default moves with the
 * newest note. While searching, every group is open so every match shows. A file note is named by its path, a symbol by its short name.
 */
import type { NoteRow } from '../../types'

/** One note as the tab draws it. */
export type NoteItem = {
  component: string
  /** The component's own name, the last part of its full one. */
  label: string
  author: 'user' | 'model'
  kind: string
  /** Its text whole, and the first line of it, which the row shows. */
  value: string
  line: string
  date: string
  /** When it was written, whole: what orders the tree's notes and picks the boundary opened first. */
  updated: string
  missing: boolean
  stale: boolean
  staleReason: string | null
  checked: boolean
  checkReason: string | null
  /** On a missing name, the component it likely moved to; null when knossos suggests none. */
  suggested: string | null
  /** The file its component is in (a file note's own path), and that component's boundary; null when knossos does not say. */
  path: string | null
  boundary: string | null
}

/** Whether a component's name is a project path (a file component): it has a `/` and no symbol after a `#`. */
const isPath = (component: string): boolean => component.includes('/') && !component.includes('#') && !component.includes('\\') && !component.includes('::')

/**
 * A component's own name: a path's file name (`fixtures.py`, its extension
 * kept), else what follows the last `\`, `::`, `.`, `/` or `#` (a symbol in
 * a TypeScript file) of its full one.
 */
export const labelOf = (component: string): string =>
  isPath(component) ? (component.split('/').filter(p => p !== '').at(-1) ?? component) : (component.split(/\\|::|\/|#|(?<=\w)\.(?=\w)/).filter(p => p !== '').at(-1) ?? component)

/** A note of the answer as the tab draws it. */
export const itemOf = (row: NoteRow): NoteItem => ({
  component: row.component,
  label: labelOf(row.component),
  author: row.author,
  kind: row.kind,
  value: row.value,
  line: row.value.split('\n')[0]?.trim() ?? '',
  date: row.updated_at.slice(0, 10),
  updated: row.updated_at,
  missing: row.component_status === 'missing',
  stale: row.stale,
  staleReason: row.stale_reason,
  checked: row.checked,
  checkReason: row.check_reason,
  suggested: row.component_status === 'missing' ? (row.suggested_component ?? null) : null,
  path: row.path ?? null,
  boundary: row.boundary ?? null,
})

/** One walked line of the tree. `fold` is the key `o` flips in the view's folds, and `contrary` the opposite one it replaces. */
export type TreeLine =
  | { kind: 'needs'; item: NoteItem }
  | { kind: 'boundary'; name: string | null; count: number; open: boolean; fold: string; contrary: string }
  | { kind: 'folder'; boundary: string | null; path: string; count: number; open: boolean; fold: string }
  | { kind: 'note'; item: NoteItem }

/** How a note is named: a file note by its path, the file there or gone, anything else by its short name. */
export const noteName = (item: NoteItem): string => ((item.path !== null && item.component === item.path) || isPath(item.component) ? item.component : item.label)

/** The boundary that holds the newest note (null for the notes with none), opened first; null for no notes. */
export function firstOpen(items: NoteItem[]): string | null {
  const newest = [...items].sort((a, b) => b.updated.localeCompare(a.updated))[0]
  return newest === undefined ? null : newest.boundary
}

/** The folder a note's file is in, '' for the root; null for a note on no file, which has none. */
const folderOf = (item: NoteItem): string | null => (item.path === null ? null : item.path.includes('/') ? item.path.slice(0, item.path.lastIndexOf('/')) : '')

const boundaryKey = (name: string | null, open: boolean): string => `notes:b${open ? '+' : '-'}:${name ?? ''}`
const folderKey = (boundary: string | null, path: string): string => `notes:f:${boundary ?? ''}:${path}`

/** Newest first, then by name. */
const newestFirst = (a: NoteItem, b: NoteItem): number => b.updated.localeCompare(a.updated) || noteName(a).localeCompare(noteName(b))

/** The tree's lines in walk order, with `folds` flipping each group's default and `searching` opening every one. */
export function notesTree(items: NoteItem[], folds: string[], searching: boolean): TreeLine[] {
  if (items.length === 0) return []
  const flipped = new Set(folds)
  const first = firstOpen(items)
  // A note whose component is gone first, then one flagged for the model, each newest first.
  const needs = items.filter(i => i.missing || i.checked).sort((a, b) => Number(b.missing) - Number(a.missing) || newestFirst(a, b)).map((item): TreeLine => ({ kind: 'needs', item }))
  // Boundaries by the name a person reads (`Boundary` for `namespace:Knossos\Boundary`), the notes with none last.
  const names = [...new Set(items.map(i => i.boundary))].sort((a, b) => (a === null ? 1 : b === null ? -1 : labelOf(a).localeCompare(labelOf(b))))
  const lines: TreeLine[] = [...needs]
  for (const name of names) {
    const inside = items.filter(i => i.boundary === name)
    // As the person set it, else open only for the newest note's boundary.
    const set = flipped.has(boundaryKey(name, true)) ? true : flipped.has(boundaryKey(name, false)) ? false : name === first
    const bOpen = searching || set
    lines.push({ kind: 'boundary', name, count: inside.length, open: bOpen, fold: boundaryKey(name, !bOpen), contrary: boundaryKey(name, bOpen) })
    if (!bOpen) continue
    // A note on no file sits under the boundary itself.
    lines.push(...inside.filter(i => folderOf(i) === null).sort(newestFirst).map((item): TreeLine => ({ kind: 'note', item })))
    for (const path of [...new Set(inside.map(folderOf).filter(f => f !== null))].sort()) {
      const notes = inside.filter(i => folderOf(i) === path).sort(newestFirst)
      const fOpen = searching || !flipped.has(folderKey(name, path))
      lines.push({ kind: 'folder', boundary: name, path, count: notes.length, open: fOpen, fold: folderKey(name, path) })
      if (fOpen) lines.push(...notes.map((item): TreeLine => ({ kind: 'note', item })))
    }
  }
  return lines
}
