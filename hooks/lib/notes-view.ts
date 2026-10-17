/**
 * The Notes tab: every note in the project, the person's and the model's,
 * grouped so what needs the person comes first (`knossos notes`): on a name
 * the graph no longer holds, then the model's whose files changed, then the
 * rest, the person's before the model's, newest first.
 *
 * Pure. One card: each group under its own line, every note a row with its
 * mark (missing, stale, flagged for the model), its component, who wrote it,
 * its kind when it is not a plain note, the first line of its text and the
 * day it was written. The marker walks the rows in that order; a note on a
 * missing name has no detail to open.
 */
import type { NoteCounts, NoteRow, Notes, NotesAsk, NotesFilter, NotesState } from '../../types'
import { moreRows, noteOf, shownOf, windowOf } from './cards'
import type { Arrangement, Block, Section } from './cards'
import { CARD_ICON, NOTE, SEVERITY, mark } from './marks'
import { ACCENT, boundaryLabel, NO_HUES, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { button, cells, chip, clip, dimRow, fit, grouped, plural, segmentsWidth, wrapWords } from './rows'
import type { Row, Segment } from './rows'
import { entryRow, entrySpec } from './views'
import type { Entry, Openable } from './views'
import { labelOf, noteName, notesTree } from './notes-tree'
export { labelOf } from './notes-tree'
import type { TreeLine } from './notes-tree'

/** The filters `s` cycles through, in order. */
export const NOTES_FILTERS: NotesFilter[] = ['all', 'yours', 'model', 'stale', 'missing', 'flagged']

/** The filter after `filter` in the cycle, back to every note after the last. */
export const nextFilter = (filter: NotesFilter): NotesFilter => NOTES_FILTERS[(NOTES_FILTERS.indexOf(filter) + 1) % NOTES_FILTERS.length] ?? 'all'

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
  /** The file its component is in (a file note's own path), and that component's boundary; null when knossos does not say. */
  path: string | null
  boundary: string | null
}

/** The Notes tab's view of the answer. */
export type NotesInput = {
  /** What to say instead of the list (loading, nothing answered, a refusal); null when there are notes to draw or none. */
  said: string | null
  warn: boolean
  loading: boolean
  filter: NotesFilter
  text: string
  /** Whether the field that narrows by text is open (`n`). */
  filtering: boolean
  /** The write the card asks the person to confirm, or null. */
  ask: NotesAsk | null
  counts: NoteCounts
  groups: { missing: NoteItem[]; stale: NoteItem[]; rest: NoteItem[] }
  truncated: boolean
  /** The tree's lines in walk order (see `notes-tree.ts`): what needs the person, then each boundary, folder and note. */
  walk: TreeLine[]
}

const NO_COUNTS: NoteCounts = { total: 0, missing: 0, stale: 0, flagged: 0 }

const itemOf = (row: NoteRow): NoteItem => ({
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
  path: row.path ?? null,
  boundary: row.boundary ?? null,
})

/** The tab's view of the stored answer, with the filter and text the view holds and whether the field is open. */
export function notesInput(state: NotesState | null, filter: NotesFilter, text: string, filtering: boolean, ask: NotesAsk | null = null, folds: string[] = []): NotesInput {
  const base = { warn: false, loading: false, filter, text, filtering, ask, counts: NO_COUNTS, groups: { missing: [], stale: [], rest: [] }, truncated: false, walk: [] }
  const answer: Notes | null = state?.answer ?? null
  if (answer === null) {
    return state === null || state.phase === 'loading' ? { ...base, said: 'Reading the notes…', loading: true } : { ...base, said: 'knossos did not answer; the tab asks again with the next snapshot.', warn: true }
  }
  if (answer.status === 'refused') return { ...base, said: answer.reason ?? 'knossos refused the list.', warn: true }
  if (answer.status === 'unscanned') return { ...base, said: 'No graph yet: scan the project, and its notes list here.', warn: true }
  if (answer.status !== 'ok') return { ...base, said: 'knossos did not read the notes.', warn: true }
  const groups = { missing: answer.groups.missing.map(itemOf), stale: answer.groups.stale.map(itemOf), rest: answer.groups.rest.map(itemOf) }
  return {
    ...base,
    said: null,
    loading: state?.phase === 'loading',
    counts: answer.counts,
    groups,
    truncated: answer.truncated === true,
    // Narrowed by text or a filter, every group is open, so every match shows.
    walk: notesTree([...groups.missing, ...groups.stale, ...groups.rest], folds, text !== '' || filter !== 'all'),
  }
}

/** The stale reason of a note no file backs (`AnnotationNotes::staleReason`): confirming it would rest it on nothing. */
const ON_NO_FILE = 'rests on no scanned file'

/** Whether `v` can say a note is still true: a stale plain note of the model's on a name the graph holds, resting on a file. */
export const confirmable = (n: NoteItem): boolean => n.author === 'model' && n.kind === 'note' && n.stale && !n.missing && n.staleReason !== ON_NO_FILE

/** The note the marker stands on, or null on a boundary's or a folder's row (or with nothing listed). */
export function markedNote(input: NotesInput, selected: number): NoteItem | null {
  const line = input.walk[Math.min(Math.max(0, selected), input.walk.length - 1)]
  return line?.kind === 'needs' || line?.kind === 'note' ? line.item : null
}

/** Every note the tab lists, in the order the marker walks them. */
export const notesItems = (input: NotesInput): NoteItem[] => [...input.groups.missing, ...input.groups.stale, ...input.groups.rest]

/** The rows the tab walks: a note opens its component's detail, except one on a name the graph no longer holds; a group folds. */
export const notesList = (input: NotesInput): Openable[] =>
  input.walk.map((line): Openable => {
    if (line.kind === 'boundary') return { name: line.name ?? 'no boundary', canonical: line.name ?? '', inert: true, fold: line.fold, contrary: line.contrary }
    if (line.kind === 'folder') return { name: `${line.path}/`, canonical: line.path, inert: true, fold: line.fold }
    const n = line.item
    return { name: noteName(n), canonical: n.component, ...(n.missing ? { inert: true as const } : {}), ask: `What is the ${n.author === 'user' ? 'person' : 'model'}'s note on ${n.component} about, and does it still hold?` }
  })

/** The Overview's line and the card's note: how many notes, and how many need the person. */
export function countsLine(counts: NoteCounts): string {
  const parts = [plural(counts.total, 'note', 'notes'), ...(counts.missing > 0 ? [`${grouped(counts.missing)} missing`] : []), ...(counts.stale > 0 ? [`${grouped(counts.stale)} stale`] : []), ...(counts.flagged > 0 ? [`${grouped(counts.flagged)} flagged`] : [])]
  return parts.join(' · ')
}

/** Who wrote a note, as the question names them. */
const whose = (author: 'user' | 'model'): string => (author === 'user' ? 'your' : "the model's")

/** The card's question for a write waiting on the person: what it would do, and y or n (or, to flag, the reason's field). */
function askRows(ask: NotesAsk | null, columns: number): Row[] {
  if (ask === null) return []
  const label = labelOf(ask.component)
  if (ask.action === 'check') {
    return [
      { key: 'notes-ask', segments: [{ text: `   Flag the note on ${label} for the model; why (optional): `, color: STATUS_COLOURS.warn }, { text: ask.value ?? '', field: { id: 'notes-reason', value: ask.value ?? '', placeholder: 'Enter flags it' } }] },
      { key: 'notes-ask-keys', segments: [{ text: '   ' }, button('notes-no', 'no', 'n'), { text: '  Enter flags it', dim: true }] },
    ]
  }
  const question = ask.action === 'remove' ? `Remove ${whose(ask.author)} note on ${label}?` : ask.action === 'uncheck' ? `Take back the flag on ${label}?` : `Move the note on ${ask.component} to ${ask.to ?? ''}?`
  return [
    ...wrapWords(question, Math.max(1, columns - 3)).map((line, i): Row => ({ key: `notes-ask-${i}`, segments: [{ text: `   ${line}`, color: STATUS_COLOURS.warn }] })),
    { key: 'notes-ask-keys', segments: [{ text: '   ' }, button('notes-yes', 'yes', 'y'), { text: '  ' }, button('notes-no', 'no', 'n')] },
  ]
}

/** The fewest rows the tree shows, however short the pane. */
const LIST_MIN = 5

/** The most lines of a note the panel under the tree shows whole; past it, how many more. */
const DETAIL_LINES = 12

/** A note's row: its mark (missing, flagged), its name, who wrote it, its kind when not a plain note, its first line; stale as a quiet `≈` at its end, then its day. */
/** A line with the first hit of `text` in it marked, in any case; the line alone when it holds none. */
function withHit(line: string, text: string): Segment[] {
  const at = text === '' ? -1 : line.toLowerCase().indexOf(text.toLowerCase())
  if (at < 0) return [{ text: line }]
  return [{ text: line.slice(0, at) }, { text: line.slice(at, at + text.length), color: ACCENT, bold: true }, { text: line.slice(at + text.length) }].filter(s => s.text !== '')
}

/** A note's name in the tree, under its folder: a file note by its file name alone, the folder above saying the rest. */
const nameInFolder = (n: NoteItem): string => (n.path !== null && n.component === n.path ? n.path.slice(n.path.lastIndexOf('/') + 1) : noteName(n))

/** Who wrote a note, and its kind when it is not a plain note. */
const authorOf = (n: NoteItem): string => `${n.author === 'user' ? 'you' : 'model'}${n.kind === 'note' ? '' : ` ${n.kind}`}`

/** The columns every note's row shares: where its author starts (after the indent and the name) and how wide the author is. */
type NoteColumns = { name: number; author: number }

/** The columns for these rows: the widest indent and name, held to two fifths of the card so the text keeps its room, and the widest author. */
function noteColumns(rows: { indent: number; name: string; item: NoteItem }[], columns: number): NoteColumns {
  const name = Math.max(0, ...rows.map(r => r.indent + cells(r.name)))
  return { name: Math.min(name, Math.max(12, Math.floor(columns * 0.4))), author: Math.max(0, ...rows.map(r => cells(authorOf(r.item)))) }
}

function noteEntry(n: NoteItem, at: number, selected: number, indent: number, text: string, name: string, cols: NoteColumns): Entry {
  const author = authorOf(n)
  const lead = cols.name + 2 + cols.author + 2
  const mark0 = n.missing ? mark(NOTE.missing) : n.checked ? mark(NOTE.flagged) : undefined
  return {
    selected: at === selected,
    ...(mark0 === undefined ? {} : { mark: mark0 }),
    place: `${n.date}${n.stale ? ` ${NOTE.stale.glyph}` : ''}`,
    need: lead + cells(n.line),
    main: width => {
      // The name in its column, cut to it; a narrow card gives the name what the text cannot use.
      const room = Math.max(1, Math.min(cols.name, width - 8) - indent)
      const shown = fit(name, room)
      return [
        { text: ' '.repeat(indent) },
        button(`row:${at}`, shown),
        { text: `${' '.repeat(Math.max(0, room - cells(shown)))}  ${author.padEnd(cols.author)}  `, dim: true },
        ...withHit(fit(n.line, Math.max(0, width - lead)), text),
      ]
    },
  }
}

/** A group's row in the tree: its fold mark, its swatch (a boundary) or its path (a folder), and how many notes it holds. */
function groupEntry(line: Extract<TreeLine, { kind: 'boundary' | 'folder' }>, at: number, selected: number, hues: Hues): Entry {
  const fold: Segment = { text: `${line.open ? '▾' : '▸'} `, dim: true }
  const label: Segment[] =
    line.kind === 'boundary'
      ? [fold, ...(line.name === null ? [{ text: '· ', dim: true }, button(`row:${at}`, 'no boundary')] : [...chip(line.name, hues).slice(0, 1), { text: ' ' }, button(`row:${at}`, boundaryLabel(line.name, hues))])]
      : [{ text: '   ' }, fold, button(`row:${at}`, `${line.path === '' ? '.' : line.path}/`)]
  return { selected: at === selected, place: '', need: segmentsWidth(label) + 6, main: () => [...label, { text: `  ${grouped(line.count)}`, dim: true }] }
}

/** The marked note whole, under the tree: where it lives, who wrote it and when, why it needs a look, and its text. */
function detailSection(n: NoteItem, columns: number, hues: Hues): Section {
  const place: Row = { key: 'notes-detail-place', segments: clip([{ text: '   ' }, { text: n.path ?? 'on a name the graph no longer holds', dim: n.path === null }, ...(n.boundary === null ? [] : [{ text: '  ' }, ...chip(n.boundary, hues)])], columns) }
  const why: Row[] = [
    ...(n.missing ? [{ text: 'Its component is gone: o finds where the note goes.', tone: STATUS_COLOURS.alert }] : []),
    ...(n.stale && n.staleReason !== null ? [{ text: `Stale: ${n.staleReason}.${confirmable(n) ? ' v says it is still true.' : ''}`, tone: STATUS_COLOURS.warn }] : []),
    ...(n.checked ? [{ text: `Flagged for the model${n.checkReason !== null && n.checkReason !== '' ? `: ${n.checkReason}` : '.'}`, tone: STATUS_COLOURS.warn }] : []),
  ].flatMap((w, i) => wrapWords(w.text, Math.max(1, columns - 3)).map((part, j): Row => ({ key: `notes-detail-why-${i}-${j}`, segments: [{ text: `   ${part}`, color: w.tone }] })))
  const lines = n.value.split('\n').flatMap((para, i) => (para.trim() === '' ? [''] : wrapWords(para, Math.max(1, columns - 3)).map(part => part)).map((part, j) => ({ part, key: `${i}-${j}` })))
  const shown = lines.slice(0, DETAIL_LINES)
  const text: Row[] = shown.map(l => ({ key: `notes-detail-text-${l.key}`, segments: [{ text: `   ${l.part}` }] }))
  const more = lines.length > shown.length ? [dimRow('notes-detail-more', `   ${plural(lines.length - shown.length, 'more line', 'more lines')}: o opens the component with the note in full`, columns)] : []
  return { key: 'notes-detail', title: noteName(n), glyph: CARD_ICON.notes, note: noteOf(`${n.author === 'user' ? 'yours' : 'model'} · ${n.date}`), body: [place, ...why, { key: 'notes-detail-gap', segments: [{ text: ' ' }] }, ...text, ...more] }
}

/**
 * The Notes tab: the counts as its card's note, the filter and the text it
 * is narrowed by, the field while `n` has it open, then the tree: what needs
 * the person under its own line, then each boundary, folder and note, as
 * long as the pane allows; under it, the marked note whole.
 */
export function notesArrangement(input: NotesInput, selected: number, hues: Hues = NO_HUES): Arrangement {
  const walk = input.walk
  const block: Block = {
    key: 'notes',
    grow: { length: walk.length + 2, min: LIST_MIN },
    make: (columns, limit, scrolled) => {
      const title = 'Notes'
      if (input.said !== null) {
        const said = wrapWords(input.said, Math.max(1, columns - 3)).map((line, i): Row => ({ key: `notes-said-${i}`, segments: [{ text: input.warn && i === 0 ? `${SEVERITY.warn.glyph}  ` : '   ', color: STATUS_COLOURS.warn }, { text: line, ...(input.warn ? { color: STATUS_COLOURS.warn } : { dim: true }) }] }))
        return { key: 'notes', title, body: said }
      }
      const head: Row[] = [...askRows(input.ask, columns)]
      if (input.filtering) head.push({ key: 'notes-field', segments: [{ text: '   narrow: ', dim: true }, { text: input.text, field: { id: 'notes-filter', value: input.text, placeholder: 'part of a name or a note' } }] })
      const items = notesItems(input)
      const narrowed = [...(input.filter === 'all' ? [] : [`showing ${input.filter}`]), ...(input.text === '' || input.filtering ? [] : [`narrowed to "${input.text}"`])]
      if (narrowed.length > 0) head.push(dimRow('notes-narrowed', `   ${narrowed.join(' · ')} · ${plural(items.length, 'note', 'notes')}`, columns))
      const note = noteOf(`${countsLine(input.counts)}${input.truncated ? ' · the first 2,000' : ''}`)
      if (items.length === 0) {
        const empty = input.counts.total === 0 ? "   No notes yet: m on a component's detail writes one, and the model's land here as it records them." : '   No note matches.'
        return { key: 'notes', title, note, body: [...head, ...wrapWords(empty, Math.max(1, columns)).map((line, i) => dimRow(`notes-none-${i}`, line, columns))] }
      }
      // A note under "Needs you" by its full name; in the tree, under its folder, by what the folder leaves to say.
      const placed = walk.map(line => (line.kind === 'needs' ? { indent: 0, name: noteName(line.item), item: line.item } : line.kind === 'note' ? { indent: 6, name: nameInFolder(line.item), item: line.item } : null))
      const cols = noteColumns(
        placed.filter(p => p !== null),
        columns,
      )
      const entries: Entry[] = walk.map((line, i) => (line.kind === 'boundary' || line.kind === 'folder' ? groupEntry(line, i, selected, hues) : noteEntry(line.item, i, selected, placed[i]!.indent, input.text, placed[i]!.name, cols)))
      const spec = entrySpec(columns, entries, hues)
      const needs = walk.findIndex(l => l.kind === 'needs')
      const tree = walk.findIndex(l => l.kind !== 'needs')
      // The two lines over the parts take two rows of the window.
      const window = windowOf(walk.length, Math.max(1, limit - (needs >= 0 ? 1 : 0) - (needs >= 0 && tree >= 0 ? 1 : 0)), selected, scrolled)
      const body: Row[] = [...head]
      for (let i = window.start; i < window.end; i++) {
        if (i === Math.max(needs, window.start) && needs >= 0 && walk[i]?.kind === 'needs') body.push(dimRow('notes-needs', '   Needs you', columns))
        if (i === Math.max(tree, window.start) && needs >= 0 && tree >= 0 && walk[i]?.kind !== 'needs') body.push(dimRow('notes-all', '   Where they live', columns))
        body.push(entryRow(`notes-${i}`, entries[i]!, spec, hues))
      }
      body.push(...moreRows('notes-window', window, walk.length, columns, 0))
      return { key: 'notes', title, subtitle: "yours and the model's", note, body, window: shownOf(window, walk.length) }
    },
  }
  const marked = markedNote(input, selected)
  if (marked === null || input.said !== null) return { left: [block] }
  const detail: Block = { key: 'notes-detail', make: columns => detailSection(marked, columns, hues) }
  return { left: [block, detail] }
}
