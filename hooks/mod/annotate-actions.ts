/**
 * A note on a component from its detail (`m`): typed into the notes card,
 * checked by knossos (a preview, nothing written), and recorded once the
 * person says yes.
 */
import type { NoteState } from '../../types'
import { BUSY_CODE, BUSY_SAID, parseAnnotate } from '../lib/envelopes'
import { requestDetail } from './loaders'
import { focusField } from './field-actions'
import { disable, say, wrapper } from './port'
import type { Port } from './port'

/** What a note on a component asks: a preview of it, or (`execute`) its write once the person confirmed. */
export const annotateArgs = (component: string, value: string, execute: boolean): string[] => [`--component=${component}`, `--value=${value}`, ...(execute ? ['--execute'] : [])]

/** The wrapper bounds annotate at 15 s. */
const NOTE_TIMEOUT_MS = 20_000

/**
 * `m` on a component's detail: a field opens in its notes card, holding the
 * component's note when it has one, and takes the focus once it is drawn.
 * Nothing is written by this, nor by typing.
 */
export async function startNote(io: Port): Promise<void> {
  const v = await io.state.view.read()
  if (v.inspect === null || v.inspect.file === true) return
  const stored = await io.state.detail.read()
  const component = stored?.detail?.component?.name ?? v.inspect.name
  // The person's own note, never an agent's: saving writes the person's.
  const existing = stored?.detail?.component?.annotations?.find(a => a.kind === 'note' && a.author !== 'model')?.value ?? ''
  await io.state.note.update((): NoteState => ({ component, phase: 'editing', value: existing, previous: null, reason: null }))
  // The note's field is in the footer, always on screen: nothing to scroll to.
  focusField(io, 'note', false)
}

/** What was typed into the note's field, kept as it is typed. */
export async function typeNote(io: Port, text: string): Promise<void> {
  await io.state.note.update((n): NoteState | null => (n !== null && n.phase === 'editing' ? { ...n, value: text } : n))
}

/**
 * Enter in the note's field: knossos checks the note (a preview, nothing
 * written) and the card asks before recording it. An empty field drops it.
 */
export async function submitNote(io: Port, text: string): Promise<void> {
  const asked = await io.state.note.read()
  if (asked === null || asked.phase !== 'editing') return
  const value = text.trim()
  if (value === '') {
    await io.state.note.update(() => null)
    return
  }
  await io.state.note.update((): NoteState => ({ ...asked, phase: 'previewing', value }))
  io.clock.after(0, () => void previewNote(io, asked.component, value).catch(() => undefined))
}

/** The preview of a note: what it would replace, or why knossos refuses it. Writes nothing. */
async function previewNote(io: Port, component: string, value: string): Promise<void> {
  const root = (await io.state.dashboard.read())?.project_root ?? undefined
  const parsed = parseAnnotate(await wrapper(io, 'annotate', annotateArgs(component, value, false), NOTE_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable(io)
  await io.state.note.update((n): NoteState | null => {
    if (n === null || n.component !== component || n.value !== value || n.phase !== 'previewing') return n
    if (parsed?.status === 'ok' && parsed.executed === false) return { ...n, phase: 'confirming', previous: parsed.previous ?? null }
    return { ...n, phase: 'failed', reason: parsed?.status === 'refused' ? (parsed.reason ?? 'it refused') : 'it said nothing' }
  })
}

/**
 * `y` on the card's question: records the note, then reads the detail
 * again so it shows it. Only from the question: a press at any other moment
 * writes nothing.
 */
export async function confirmNote(io: Port): Promise<void> {
  const asked = await io.state.note.read()
  if (asked === null || asked.phase !== 'confirming') return
  await io.state.note.update((): NoteState => ({ ...asked, phase: 'saving' }))
  io.clock.after(0, () => void recordNote(io, asked.component, asked.value).catch(() => undefined))
}

/** Records a confirmed note; the detail is read again once it is. */
async function recordNote(io: Port, component: string, value: string): Promise<void> {
  const root = (await io.state.dashboard.read())?.project_root ?? undefined
  const parsed = parseAnnotate(await wrapper(io, 'annotate', annotateArgs(component, value, true), NOTE_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable(io)
  // A scan committing holds the write lock: the note stays at its question, its text kept, to try again.
  if (parsed?.status === 'error' && parsed.code === BUSY_CODE) {
    await io.state.note.update((n): NoteState | null => (n !== null && n.component === component ? { ...n, phase: 'confirming' } : n))
    return say(io, BUSY_SAID, 'alert')
  }
  if (parsed?.status !== 'ok' || parsed.executed !== true) {
    await io.state.note.update((n): NoteState | null => (n !== null && n.component === component ? { ...n, phase: 'failed', reason: parsed?.status === 'refused' ? (parsed.reason ?? 'it refused') : 'it said nothing' } : n))
    return
  }
  await io.state.note.update(() => null)
  await say(io, `✓ noted on ${component.slice(component.lastIndexOf('\\') + 1)}`, 'ok')
  const shown = (await io.state.view.read()).inspect
  if (shown === null) return
  // The stored detail predates the note: read it again.
  await io.state.detail.update(() => null)
  await requestDetail(io, shown)
}
