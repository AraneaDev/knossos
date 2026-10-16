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
import { moreRows, noteOf, windowOf } from './cards'
import type { Arrangement, Block } from './cards'
import { NOTE, SEVERITY, mark } from './marks'
import { NO_HUES, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { button, cells, dimRow, fit, grouped, plural, wrapWords } from './rows'
import type { Row, Segment } from './rows'
import { entryRow, entrySpec } from './views'
import type { Entry, Openable } from './views'

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
  missing: boolean
  stale: boolean
  staleReason: string | null
  checked: boolean
  checkReason: string | null
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
}

const NO_COUNTS: NoteCounts = { total: 0, missing: 0, stale: 0, flagged: 0 }

/** A component's own name: what follows the last `\`, `::`, `.` or `/` of its full one. */
export const labelOf = (component: string): string => component.split(/\\|::|\/|(?<=\w)\.(?=\w)/).filter(p => p !== '').at(-1) ?? component

const itemOf = (row: NoteRow): NoteItem => ({
  component: row.component,
  label: labelOf(row.component),
  author: row.author,
  kind: row.kind,
  value: row.value,
  line: row.value.split('\n')[0]?.trim() ?? '',
  date: row.updated_at.slice(0, 10),
  missing: row.component_status === 'missing',
  stale: row.stale,
  staleReason: row.stale_reason,
  checked: row.checked,
  checkReason: row.check_reason,
})

/** The tab's view of the stored answer, with the filter and text the view holds and whether the field is open. */
export function notesInput(state: NotesState | null, filter: NotesFilter, text: string, filtering: boolean, ask: NotesAsk | null = null): NotesInput {
  const base = { warn: false, loading: false, filter, text, filtering, ask, counts: NO_COUNTS, groups: { missing: [], stale: [], rest: [] }, truncated: false }
  const answer: Notes | null = state?.answer ?? null
  if (answer === null) {
    return state === null || state.phase === 'loading' ? { ...base, said: 'Reading the notes…', loading: true } : { ...base, said: 'knossos did not answer; the tab asks again with the next snapshot.', warn: true }
  }
  if (answer.status === 'refused') return { ...base, said: answer.reason ?? 'knossos refused the list.', warn: true }
  if (answer.status === 'unscanned') return { ...base, said: 'No graph yet: scan the project, and its notes list here.', warn: true }
  if (answer.status !== 'ok') return { ...base, said: 'knossos did not read the notes.', warn: true }
  return {
    ...base,
    said: null,
    loading: state?.phase === 'loading',
    counts: answer.counts,
    groups: { missing: answer.groups.missing.map(itemOf), stale: answer.groups.stale.map(itemOf), rest: answer.groups.rest.map(itemOf) },
    truncated: answer.truncated === true,
  }
}

/** Every note the tab lists, in the order the marker walks them. */
export const notesItems = (input: NotesInput): NoteItem[] => [...input.groups.missing, ...input.groups.stale, ...input.groups.rest]

/** The rows the tab walks: each opens its component's detail, except one on a name the graph no longer holds. */
export const notesList = (input: NotesInput): Openable[] =>
  notesItems(input).map(n => ({ name: n.label, canonical: n.component, ...(n.missing ? { inert: true as const } : {}), ask: `What is the ${n.author === 'user' ? 'person' : 'model'}'s note on ${n.component} about, and does it still hold?` }))

/** The Overview's line and the card's note: how many notes, and how many need the person. */
export function countsLine(counts: NoteCounts): string {
  const parts = [plural(counts.total, 'note', 'notes'), ...(counts.missing > 0 ? [`${grouped(counts.missing)} missing`] : []), ...(counts.stale > 0 ? [`${grouped(counts.stale)} stale`] : []), ...(counts.flagged > 0 ? [`${grouped(counts.flagged)} flagged`] : [])]
  return parts.join(' · ')
}

/** The mark a note's row leads with: its component gone, its files changed, or flagged for the model; none otherwise. */
const markOf = (n: NoteItem): Segment | undefined => (n.missing ? mark(NOTE.missing) : n.stale ? mark(NOTE.stale) : n.checked ? mark(NOTE.flagged) : undefined)

/** Who wrote a note, as the question names them. */
const whose = (author: 'user' | 'model'): string => (author === 'user' ? 'your' : "the model's")

/** The card's question for a write waiting on the person: what it would do, and y or n (or, to flag, the reason's field). */
function askRows(ask: NotesAsk | null, columns: number): Row[] {
  if (ask === null) return []
  const label = labelOf(ask.component)
  if (ask.action === 'check') {
    return [{ key: 'notes-ask', segments: [{ text: `   Flag the note on ${label} for the model; why (optional): `, color: STATUS_COLOURS.warn }, { text: ask.value ?? '', field: { id: 'notes-reason', value: ask.value ?? '', placeholder: 'Enter flags it' } }] }]
  }
  const question = ask.action === 'remove' ? `Remove ${whose(ask.author)} note on ${label}?` : ask.action === 'uncheck' ? `Take back the flag on ${label}?` : `Move the note on ${ask.component} to ${ask.to ?? ''}?`
  return [
    ...wrapWords(question, Math.max(1, columns - 3)).map((line, i): Row => ({ key: `notes-ask-${i}`, segments: [{ text: `   ${line}`, color: STATUS_COLOURS.warn }] })),
    { key: 'notes-ask-keys', segments: [{ text: '   ' }, button('notes-yes', 'yes', 'y'), { text: '  ' }, button('notes-no', 'no', 'n')] },
  ]
}

/** The fewest rows the list shows, however short the pane. */
const LIST_MIN = 5

/** Each group's line above its first row, and when a group is empty, nothing. */
const GROUP_TITLES = { missing: 'On a name the graph no longer holds', stale: 'Stale: written before their files changed', rest: 'Notes' } as const

/**
 * The Notes tab's card: the counts as its note, the filter and the text it
 * is narrowed by, the field while `n` has it open, and the groups as one
 * list, as long as the pane allows.
 */
export function notesArrangement(input: NotesInput, selected: number, hues: Hues = NO_HUES): Arrangement {
  const items = notesItems(input)
  const block: Block = {
    key: 'notes',
    grow: { length: items.length, min: LIST_MIN },
    make: (columns, limit) => {
      const title = 'Notes'
      if (input.said !== null) {
        const said = wrapWords(input.said, Math.max(1, columns - 3)).map((line, i): Row => ({ key: `notes-said-${i}`, segments: [{ text: input.warn && i === 0 ? `${SEVERITY.warn.glyph}  ` : '   ', color: STATUS_COLOURS.warn }, { text: line, ...(input.warn ? { color: STATUS_COLOURS.warn } : { dim: true }) }] }))
        return { key: 'notes', title, body: said }
      }
      const head: Row[] = [...askRows(input.ask, columns)]
      if (input.filtering) head.push({ key: 'notes-field', segments: [{ text: '   narrow: ', dim: true }, { text: input.text, field: { id: 'notes-filter', value: input.text, placeholder: 'part of a name or a note' } }] })
      const narrowed = [...(input.filter === 'all' ? [] : [`showing ${input.filter}`]), ...(input.text === '' || input.filtering ? [] : [`narrowed to "${input.text}"`])]
      if (narrowed.length > 0) head.push(dimRow('notes-narrowed', `   ${narrowed.join(' · ')} · ${plural(items.length, 'note', 'notes')}`, columns))
      const note = noteOf(`${countsLine(input.counts)}${input.truncated ? ' · the first 2,000' : ''}`)
      if (items.length === 0) {
        const empty = input.counts.total === 0 ? "   No notes yet: m on a component's detail writes one, and the model's land here as it records them." : '   No note matches.'
        return { key: 'notes', title, note, body: [...head, ...wrapWords(empty, Math.max(1, columns)).map((line, i) => dimRow(`notes-none-${i}`, line, columns))] }
      }
      const entries: Entry[] = items.map((n, i) => {
        const who = n.author === 'user' ? 'you' : 'model'
        const kind = n.kind === 'note' ? '' : ` ${n.kind}`
        const lead = cells(n.label) + 2 + cells(who) + cells(kind) + 2
        return {
          selected: i === selected,
          ...(markOf(n) === undefined ? {} : { mark: markOf(n)! }),
          place: n.date,
          need: lead + cells(n.line),
          main: width => [button(`row:${i}`, fit(n.label, Math.max(1, width - 8))), { text: `  ${who}${kind}  `, dim: true }, { text: fit(n.line, Math.max(0, width - lead)) }],
        }
      })
      const spec = entrySpec(columns, entries, hues)
      const window = windowOf(items.length, limit, selected)
      const starts = { missing: 0, stale: input.groups.missing.length, rest: input.groups.missing.length + input.groups.stale.length }
      const body: Row[] = [...head]
      for (let i = window.start; i < window.end; i++) {
        // A group's line above the first of its rows in view.
        for (const group of ['missing', 'stale', 'rest'] as const) {
          if (input.groups[group].length > 0 && (i === starts[group] || (i === window.start && i > starts[group] && i < starts[group] + input.groups[group].length))) {
            if (group !== 'rest' || starts.rest > 0) body.push(dimRow(`notes-group-${group}`, `   ${GROUP_TITLES[group]}`, columns))
          }
        }
        body.push(entryRow(`notes-${i}`, entries[i]!, spec, hues))
      }
      body.push(...moreRows('notes-window', window, items.length, columns, 0))
      return { key: 'notes', title, subtitle: "yours and the model's", note, body }
    },
  }
  return { left: [block] }
}
