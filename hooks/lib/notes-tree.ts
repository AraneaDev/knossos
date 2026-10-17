/**
 * The notes as a tree of where they live: what needs the person first (a
 * note on a name the graph no longer holds, and one the person flagged for
 * the model), then each boundary, its folders, and their notes.
 *
 * Pure. Every group is folded or open by default (a boundary folded, but the
 * one holding the newest note; a folder open), and its key in the view's
 * folds flips that default; while searching, every group is open so every
 * match shows. A file note is named by its path, a symbol by its short name.
 */
import type { NoteItem } from './notes-view'

/** One walked line of the tree. `fold` is the key `o` flips in the view's folds. */
export type TreeLine =
  | { kind: 'needs'; item: NoteItem }
  | { kind: 'boundary'; name: string | null; count: number; open: boolean; fold: string }
  | { kind: 'folder'; boundary: string | null; path: string; count: number; open: boolean; fold: string }
  | { kind: 'note'; item: NoteItem }

/** How a note is named: a file note by its path, anything else by its short name. */
export const noteName = (item: NoteItem): string => (item.path !== null && item.component === item.path ? item.path : item.label)

/** The boundary that holds the newest note (null for the notes with none), opened first; null for no notes. */
export function firstOpen(items: NoteItem[]): string | null {
  const newest = [...items].sort((a, b) => b.updated.localeCompare(a.updated))[0]
  return newest === undefined ? null : newest.boundary
}

/** The folder a note's file is in, '' for the root, and one for a note on no file. */
const folderOf = (item: NoteItem): string => (item.path === null ? '(no file)' : item.path.includes('/') ? item.path.slice(0, item.path.lastIndexOf('/')) : '')

const boundaryKey = (name: string | null): string => `notes:b:${name ?? ''}`
const folderKey = (boundary: string | null, path: string): string => `notes:f:${boundary ?? ''}:${path}`

/** Newest first, then by name. */
const newestFirst = (a: NoteItem, b: NoteItem): number => b.updated.localeCompare(a.updated) || noteName(a).localeCompare(noteName(b))

/** The tree's lines in walk order, with `folds` flipping each group's default and `searching` opening every one. */
export function notesTree(items: NoteItem[], folds: string[], searching: boolean): TreeLine[] {
  if (items.length === 0) return []
  const flipped = new Set(folds)
  const first = firstOpen(items)
  const needs = items.filter(i => i.missing || i.checked).sort(newestFirst).map((item): TreeLine => ({ kind: 'needs', item }))
  // Boundaries by name, the notes with none last.
  const names = [...new Set(items.map(i => i.boundary))].sort((a, b) => (a === null ? 1 : b === null ? -1 : a.localeCompare(b)))
  const lines: TreeLine[] = [...needs]
  for (const name of names) {
    const inside = items.filter(i => i.boundary === name)
    const bOpen = searching || (name === first) !== flipped.has(boundaryKey(name))
    lines.push({ kind: 'boundary', name, count: inside.length, open: bOpen, fold: boundaryKey(name) })
    if (!bOpen) continue
    for (const path of [...new Set(inside.map(folderOf))].sort()) {
      const notes = inside.filter(i => folderOf(i) === path).sort(newestFirst)
      const fOpen = searching || !flipped.has(folderKey(name, path))
      lines.push({ kind: 'folder', boundary: name, path, count: notes.length, open: fOpen, fold: folderKey(name, path) })
      if (fOpen) lines.push(...notes.map((item): TreeLine => ({ kind: 'note', item })))
    }
  }
  return lines
}
