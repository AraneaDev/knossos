/**
 * What the finder does: opened over the pane, what is typed searched for
 * after a pause, one search at a time, Enter opening the first match, and
 * the finder opened to pick where a route ends or a note moves.
 */
import type { KnossosView, SearchState } from '../../types'
import { parseGraphSearch } from '../lib/envelopes'
import { SingleFlight } from '../lib/scheduler'
import { focusField } from './field-actions'
import { openRow } from './navigate-actions'
import { missingMarked, startMove } from './notes-actions'
import { disable, wrapper } from './port'
import type { Port } from './port'
import { mod } from './state'

/** How long the finder waits after a keystroke before it asks: a word typed fast is one search. */
const SEARCH_DEBOUNCE_MS = 150

/** What the finder's search asks the wrapper: what was typed, trimmed. */
export const searchArgs = (query: string): string[] => [`--query=${query}`]

/** The wrapper bounds graph-search at 15 s. */
const SEARCH_TIMEOUT_MS = 20_000

/**
 * Opens the finder over the pane and moves the focus into its field once it
 * is drawn. What was typed last is kept, so a second look picks up where the
 * first left off.
 */
export async function openFinder(io: Port): Promise<void> {
  const v = await io.state.view.read()
  if (v.finding !== true) mod.search.findFrom = v.selected
  await io.state.view.update((w): KnossosView => ({ ...w, finding: true, filtering: false, selected: 0 }))
  focusField(io, 'find')
}

/** Closes the finder: the tab (or the detail) under it comes back with its marker where it stood. */
export async function closeFinder(io: Port): Promise<void> {
  mod.search.openWhenFound = false
  // A note waiting to move goes back to waiting for o: the finder no longer picks for it.
  await io.state.view.update((v): KnossosView => (v.finding === true ? { ...v, finding: false, picking: null, moving: null, selected: mod.search.findFrom } : v))
}

/** What was typed into the finder, kept at once; the search for it runs after a short pause in the typing. */
export async function typeQuery(io: Port, text: string): Promise<void> {
  await io.state.search.update((f): SearchState => ({ ...f, query: text }))
  await io.state.view.update(v => ({ ...v, selected: 0 }))
  requestSearch(io)
}

/**
 * `o`: opens the marked row, or, on a note on a name the graph no longer
 * holds, opens the finder on that name to pick where the note belongs. Apart
 * from {@link openRow}, which the finder's own search calls once a match
 * lands, so the search never leads back here.
 */
export async function openOrMove(io: Port): Promise<void> {
  const lost = (await io.state.view.read()).finding !== true ? await missingMarked(io) : null
  return lost === null ? openRow(io) : startMove(io, lost, openFinderOn)
}

/** Opens the finder with `query` typed in it, looking it up at once. */
async function openFinderOn(io: Port, query: string): Promise<void> {
  await openFinder(io)
  await typeQuery(io, query)
}

/** Enter in the finder: opens the marked match once the answer for what is typed is in; an empty field closes the finder. */
export async function submitQuery(io: Port, text: string): Promise<void> {
  if (text.trim() === '') return closeFinder(io)
  const found = await io.state.search.read()
  if (found.query !== text) await io.state.search.update((f): SearchState => ({ ...f, query: text }))
  if (found.for === text.trim() && found.phase === 'idle') return openRow(io)
  mod.search.openWhenFound = true
  requestSearch(io)
}

/** Asks for the matches of what is typed after {@link SEARCH_DEBOUNCE_MS}, one search at a time. */
function requestSearch(io: Port): void {
  if (mod.session.disabled) return
  mod.search.timer?.cancel()
  mod.search.timer = io.clock.after(SEARCH_DEBOUNCE_MS, () => {
    mod.search.timer = null
    void (mod.search.flight ??= new SingleFlight(() => loadSearch(io))).request().catch(() => undefined)
  })
}

/** One search for what is typed now; stored with the query it answers, so a stale answer is never shown as current. */
async function loadSearch(io: Port): Promise<void> {
  if (mod.session.disabled) return
  const query = (await io.state.search.read()).query.trim()
  if (query === '') {
    await io.state.search.update((f): SearchState => ({ ...f, for: '', phase: 'idle', answer: null }))
    return
  }
  await io.state.search.update((f): SearchState => ({ ...f, phase: 'searching' }))
  const parsed = parseGraphSearch(await wrapper(io, 'graph-search', searchArgs(query), SEARCH_TIMEOUT_MS))
  if (parsed?.status === 'no-binary') return disable(io)
  await io.state.search.update((f): SearchState => ({ ...f, for: query, phase: 'idle', answer: parsed }))
  // Enter was pressed before this answer landed: its first match opens now, if the field still says the same.
  if (mod.search.openWhenFound && (await io.state.search.read()).query.trim() === query) {
    mod.search.openWhenFound = false
    if (parsed?.status === 'ok' && parsed.results.length > 0) await openRow(io, 0)
  }
}

/**
 * `p` on a component's detail: the finder opens to pick where a route from
 * it ends; the component it opens on is the other end. Only components are
 * offered.
 */
export async function startRoute(io: Port): Promise<void> {
  const v = await io.state.view.read()
  if (v.inspect === null || v.inspect.file === true || (v.route ?? null) !== null) return
  const from = v.inspect
  await io.state.view.update((w): KnossosView => ({ ...w, picking: from }))
  await openFinder(io)
}
