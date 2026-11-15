/**
 * The notes card on a component's detail, and the note under way in the
 * footer. Pure: the annotations and the note's phase in, rows out.
 *
 * The notes are labelled facts, as on the head card: each by its kind in
 * the dim label column, its text in the value column, one note after the
 * other with no blank row between (see `spacing.ts`). The way to add one is
 * the last row, at the body.
 */
import type { Block } from './cards'
import { ACCENT, STATUS_COLOURS } from './palette'
import { button } from './rows'
import { apart, bodyLine, bodyRow, bodyText, LABEL_GAP, labelledRows } from './spacing'
import type { Fact } from './spacing'
import { cells, padEnd, spaces, TAB_CELLS, wrapWords } from './text'
import type { Row, Segment } from './types'
import type { NoteInput } from './views'
import { SEVERITY } from './marks'
import { busy } from './motion'

/** An annotation as the pane has it: its kind, its text, and who wrote it (`model`, an agent). */
type Annotation = { kind: string; value: string; author?: string }

/** The label of the note's field in the footer: the kind a note is recorded as. */
const FIELD_LABEL = 'note'

/** Who wrote a note, before its text when it was not the person: `(agent)`, dim. */
const AGENT = '(agent)'

/**
 * A note's text as lines `width` wide: each line of it on its own, so a short
 * ASCII sketch keeps its shape (a line that fits stays as written, spacing
 * and all; only a longer one is wrapped), `(agent)` before an agent's.
 */
function noteLines(a: Annotation, width: number): Segment[][] {
  const [first = '', ...rest] = a.value.split('\n')
  const agent = a.author === 'model'
  const lines = [
    ...wrapWords(agent ? `${AGENT} ${first}` : first, width),
    ...rest.flatMap(line => {
      // A tab would count as one cell and draw as several: spelled as spaces, the line measures as it draws.
      const kept = line.replace(/\t/g, spaces(TAB_CELLS)).replace(/\s+$/, '')
      return kept === '' ? [] : cells(kept) <= width ? [kept] : wrapWords(kept, width)
    }),
  ]
  return lines.map((line, j) => (j === 0 && agent && line.startsWith(AGENT) ? [{ text: AGENT, dim: true }, { text: line.slice(AGENT.length) }] : [{ text: line }]))
}

/**
 * The component's notes as a card: each annotation by its kind, and, on a
 * component's own detail, the way to add one (`m`) and where that stands:
 * the field to type it in, knossos checking it, the question before it is
 * recorded (`y` records, `n` drops it; what it replaces said), recording,
 * or why knossos refused it. Nothing is written until the person says yes.
 */
export function notesBlock(name: string, annotations: Annotation[], note: NoteInput | null, notable: boolean): Block {
  return {
    key: 'notes',
    make: columns => {
      const facts: Fact[] = annotations.map((a, i) => ({ key: `note-${i}`, label: a.kind.replace(/_/g, ' '), numbered: true, lines: width => noteLines(a, width) }))
      const body: Row[] = labelledRows(facts, columns)
      if (annotations.length === 0) body.push(bodyLine('note-none', `No notes on ${name} yet.`, columns))
      if (!notable) return { key: 'notes', title: 'Notes', body }
      if (note === null || note.phase === 'failed') {
        if (note !== null) body.push(...bodyText('note-failed', `${SEVERITY.error.glyph} knossos did not record it: ${note.reason ?? 'it said nothing'}`, columns, { color: STATUS_COLOURS.alert }))
        // The person's own note: an agent's beside it is not theirs to change here.
        const own = annotations.some(a => a.kind === 'note' && a.author !== 'model')
        body.push(bodyRow('note-keys', [button('note-card', own ? 'change the note' : 'add a note', undefined, { dim: true })]))
      }
      // A note under way is taken in the footer (noteFooterRows), where a long detail cannot scroll it away.
      return { key: 'notes', title: 'Notes', body }
    },
  }
}

/**
 * A note under way on the component `name`, as the footer draws it: the
 * field while it is typed, a line while knossos checks or records it, and
 * the question with its answers before it is recorded. `width` is the
 * footer's inner width. The answers' keys are left to a question that holds
 * y and n meanwhile (`keys` false).
 */
export function noteFooterRows(name: string, note: NoteInput, width: number): Row[] {
  const said = (key: string, text: string, style: Omit<Segment, 'text'>): Row[] => wrapWords(text, Math.max(1, width)).map((line, i) => ({ key: `${key}-${i}`, segments: [{ text: line, ...style }] }))
  if (note.phase === 'editing') {
    // The field labelled as the notes card labels a note, and what Enter does under it, in the value's column.
    const column = cells(FIELD_LABEL) + LABEL_GAP
    return [
      { key: 'note-field', segments: [{ text: padEnd(FIELD_LABEL, column), dim: true }, { text: note.value, field: { id: 'note', value: note.value, placeholder: `what to remember about ${name}` } }] },
      ...wrapWords('Enter checks it with knossos; nothing is written yet.', Math.max(1, width - column)).map((line, i): Row => ({ key: `note-field-keys-${i}`, segments: [{ text: spaces(column) }, { text: line, dim: true }] })),
    ]
  }
  if (note.phase === 'previewing' || note.phase === 'saving') return said('note-busy', busy(note.phase === 'saving' ? 'recording…' : 'checking with knossos…'), { dim: true })
  const replaces = note.previous === null ? '' : ` It replaces: "${note.previous}"`
  return [
    ...said('note-ask', `Record this note on ${name}? "${note.value}"${replaces}`, { color: ACCENT }),
    { key: 'note-answers', segments: [button('note-yes', 'record', note.keys ? 'y' : undefined, { dim: false }), apart(), button('note-no', 'cancel', note.keys ? 'n' : undefined, { dim: true })] },
  ]
}
