/**
 * What the Notes tab's keys do to the marked note: `m` edits the person's or
 * adopts the model's (the component's detail opens with the note in the
 * editor, and saving writes it as the person's), `X` removes it, `g` flags a
 * model note for the model (with a reason) or takes the flag back, and `o` on
 * a note on a missing name lets the finder pick where it moves. Every write
 * asks first, in the tab's card, and `y` makes it; a write that meets a
 * scan's commit keeps the question and says to retry.
 */
import type { KnossosView, NoteState, NotesAsk } from '../../types'
import { BUSY_CODE, BUSY_SAID, parseNoteWrite } from '../lib/envelopes'
import { confirmable, labelOf, markedNote, notesInput } from '../lib/notes-view'
import type { NoteItem } from '../lib/notes-tree'
import { focusField, narrowing } from './field-actions'
import { request, showComponent } from './loaders'
import { disable, say, wrapper } from './port'
import type { Port } from './port'
import { mod } from './state'

/** The wrapper bounds the notes commands at 15 s. */
const WRITE_TIMEOUT_MS = 20_000

/** The note the Notes tab's marker stands on, or null when there is none (or another tab is open). */
async function marked(io: Port): Promise<NoteItem | null> {
  const v = await io.state.view.read()
  if (v.tab !== 'notes' || v.inspect !== null) return null
  return markedNote(notesInput(await io.state.notes.read(), v.notesFilter ?? 'all', v.notesText ?? '', false, null, v.folds ?? []), v.selected)
}

/** The question the tab asks, put up or dropped. */
const ask = (io: Port, question: NotesAsk | null): Promise<unknown> => io.state.view.update((v): KnossosView => ({ ...v, notesAsk: question }))

/** `X`: asks to remove the marked note, the person's or the model's. */
export async function askRemove(io: Port): Promise<void> {
  const n = await marked(io)
  if (n !== null) await ask(io, { action: 'remove', component: n.component, author: n.author, kind: n.kind })
}

/** `g`: on a model note, a reason for flagging it (the field opens); on a flagged one, the question whether to take the flag back. */
export async function askFlag(io: Port): Promise<void> {
  const n = await marked(io)
  if (n === null || n.author !== 'model' || n.missing) return
  if (n.checked) return void (await ask(io, { action: 'uncheck', component: n.component, author: n.author, kind: n.kind }))
  await ask(io, { action: 'check', component: n.component, author: n.author, kind: n.kind, value: '' })
  focusField(io, 'notes-reason')
}

/** The reason as typed into the flag's field. */
export async function typeReason(io: Port, text: string): Promise<void> {
  await io.state.view.update((v): KnossosView => (v.notesAsk?.action === 'check' ? { ...v, notesAsk: { ...v.notesAsk, value: text } } : v))
}

/** Enter in the flag's field: the note is flagged with that reason. */
export async function submitReason(io: Port, text: string): Promise<void> {
  await typeReason(io, text.trim())
  await confirm(io)
}

/** A pick in the finder while a note on a missing name waits to move (`notes-move:<component>`): the tab asks to move it there. */
export async function askMove(io: Port, to: string): Promise<void> {
  const v = await io.state.view.read()
  const moving = v.moving ?? null
  if (moving === null || to === '') return
  await io.state.view.update((w): KnossosView => ({ ...w, finding: false, moving: null, tab: 'notes', notesAsk: { action: 'move', component: moving.component, author: moving.author, kind: moving.kind, to } }))
}

/** `o` on a note on a missing name: the finder opens on where knossos suggests its component went, else on the name it had, to pick where the note moves. */
export async function startMove(io: Port, n: NoteItem, openFinder: (io: Port, query: string) => Promise<void>): Promise<void> {
  await io.state.view.update((v): KnossosView => ({ ...v, moving: { component: n.component, author: n.author, kind: n.kind } }))
  await openFinder(io, n.suggested ?? n.label)
}

/** The marked note, when the tab's `o` should move it rather than open it: one on a missing name. */
export async function missingMarked(io: Port): Promise<NoteItem | null> {
  const n = await marked(io)
  return n !== null && n.missing ? n : null
}

/**
 * `m`: the component's detail opens with the marked note in its editor, the
 * person's to edit or the model's to adopt; saving writes it as the
 * person's. A note on a missing name has no component to open.
 */
export async function editMarked(io: Port): Promise<void> {
  const n = await marked(io)
  if (n === null) return
  if (n.missing) return say(io, 'o finds where the note belongs first', 'alert')
  // The editor first: the detail draws it once its component is the note's.
  // The field and the wrapper take one line: a note of paragraphs is edited as one.
  await io.state.note.update((): NoteState => ({ component: n.component, phase: 'editing', value: n.value.replace(/\s+/g, ' ').trim(), previous: null, reason: null }))
  focusField(io, 'note')
  await showComponent(io, { name: n.component, label: n.label })
}

/** The wrapper's argv for a question's write. */
export function argsOf(q: NotesAsk): { sub: string; args: string[] } {
  const author = q.author === 'model' ? ['--author=model'] : []
  const kind = q.kind === 'note' ? [] : [`--kind=${q.kind}`]
  if (q.action === 'remove') return { sub: 'annotate', args: [`--component=${q.component}`, '--remove', ...author, ...kind, '--execute'] }
  if (q.action === 'check') return { sub: 'note-check', args: [`--component=${q.component}`, ...(q.value === undefined || q.value === '' ? [] : [`--value=${q.value}`]), '--execute'] }
  if (q.action === 'uncheck') return { sub: 'note-check', args: [`--component=${q.component}`, '--remove', '--execute'] }
  if (q.action === 'confirm') return { sub: 'note-confirm', args: [`--component=${q.component}`, '--execute'] }
  return { sub: 'note-move', args: [`--component=${q.component}`, `--to=${q.to ?? ''}`, ...author, ...kind, '--execute'] }
}

/** What the footer says once a question's write is done. */
function doneSaid(q: NotesAsk): string {
  if (q.action === 'remove') return `✓ removed the note on ${labelOf(q.component)}`
  if (q.action === 'check') return `✓ flagged ${labelOf(q.component)} for the model`
  if (q.action === 'uncheck') return `✓ took the flag back on ${labelOf(q.component)}`
  if (q.action === 'confirm') return `✓ the note on ${labelOf(q.component)} is still true`
  return `✓ moved the note to ${labelOf(q.to ?? '')}`
}

/**
 * `v`: the marked stale note of the model's is still true. Made at once, on a
 * timer, never inside the press: it changes no text and no flag, and the
 * next change to its files makes it stale again.
 */
export async function confirmMarked(io: Port): Promise<void> {
  const n = await marked(io)
  if (n === null || !confirmable(n)) return
  const q: NotesAsk = { action: 'confirm', component: n.component, author: 'model', kind: n.kind }
  io.clock.after(0, () => void write(io, q).catch(() => undefined))
}

/** `y` on the tab's question: the write is made on a timer, never inside the press. */
export async function confirm(io: Port): Promise<void> {
  const q = (await io.state.view.read()).notesAsk ?? null
  if (q === null) return
  io.clock.after(0, () => void write(io, q).catch(() => undefined))
}

/** Makes a confirmed write: done, the notes are read again; refused, the footer says why; busy, the question stays. */
async function write(io: Port, q: NotesAsk): Promise<void> {
  const root = (await io.state.dashboard.read())?.project_root ?? undefined
  const { sub, args } = argsOf(q)
  const parsed = parseNoteWrite(await wrapper(io, sub, args, WRITE_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable(io)
  if (parsed?.status === 'error' && parsed.code === BUSY_CODE) return say(io, BUSY_SAID, 'alert')
  await ask(io, null)
  if (parsed?.status !== 'ok' || parsed.executed !== true) return say(io, parsed?.status === 'refused' ? `✗ ${parsed.reason ?? 'knossos refused it'}` : '✗ knossos said nothing', 'alert')
  mod.notes.version += 1
  await request(io, 'notes')
  await say(io, doneSaid(q), 'ok')
}

/** The field that narrows the Notes tab by text: the list is read again for each change. */
const NOTES_FILTER = narrowing('notes', 'notes-filter', 'notesText', io => request(io, 'notes'))
/** Opens the field that narrows the Notes tab by text, and moves the focus into it once it is drawn. */
export const openNotesFilter = NOTES_FILTER.open
/** The Notes tab's text as typed: the list is read again for it, the marker back at the top. */
export const typeNotesFilter = NOTES_FILTER.type
/** Enter in the Notes tab's field: keep the text (an empty one clears it) and close the field. */
export const submitNotesFilter = NOTES_FILTER.submit
