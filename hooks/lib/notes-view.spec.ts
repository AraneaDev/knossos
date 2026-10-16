import { describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView, NoteRow, Notes, NotesState } from '../../types'
import { parseNotes } from './envelopes'
import { footerRows, listFor, paneInput, paneLayout, TABS } from './layout'
import { nextFilter, NOTES_FILTERS } from './notes-view'
import { plainText } from './__tests__/plain-text'

const ROOT = '/work/app'
const d = { status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: 's1', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false } as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'notes', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })
const row = (component: string, over: Partial<NoteRow> = {}): NoteRow => ({ component, kind: 'note', author: 'model', value: `What ${component} does.\nA second line.`, updated_at: '2026-10-16T11:00:00Z', component_status: 'present', stale: false, stale_reason: null, checked: false, check_reason: null, ...over })
const answer = (over: Partial<Notes> = {}): Notes => ({
  status: 'ok',
  filter: 'all',
  text: '',
  groups: {
    missing: [row('App\\Gone', { component_status: 'missing' })],
    stale: [row('App\\Chain1', { stale: true, stale_reason: 'written before src/Chain1.php changed' })],
    rest: [row('App\\User', { author: 'user', value: 'Mine.' }), row('App\\Target', { checked: true, check_reason: 'still per request?' })],
  },
  counts: { total: 4, missing: 1, stale: 1, flagged: 1 },
  truncated: false,
  ...over,
})
const state = (a: Notes | null, phase: NotesState['phase'] = 'done'): NotesState => ({ snapshot: 's1', filter: 'all', text: '', version: 0, phase, answer: a })
const pane = (s: NotesState | null, v = view()) => paneInput(d, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, v, 0, true, null, null, undefined, null, undefined, { notes: s })
const text = (s: NotesState | null, columns = 100, height = 60, v = view()) => paneLayout(pane(s, v), columns, height).body.map(plainText).join('\n')

describe('the Notes tab', () => {
  it('comes last, on 9, so the older tabs keep their keys', () => {
    expect(TABS.at(-1)).toEqual({ id: 'notes', full: 'Notes', hotkey: '9' })
  })

  it('says what it is doing, or why there is nothing to list', () => {
    expect(text(null)).toContain('Reading the notes…')
    expect(text(state(null))).toContain('knossos did not answer')
    expect(text(state({ ...answer(), status: 'refused', reason: 'filter must be one of: all, yours.' }))).toContain('filter must be one of: all, yours.')
    const none = answer({ groups: { missing: [], stale: [], rest: [] }, counts: { total: 0, missing: 0, stale: 0, flagged: 0 } })
    expect(text(state(none))).toContain("No notes yet: m on a component's detail writes one")
    expect(text(state({ ...none, counts: { total: 4, missing: 1, stale: 1, flagged: 1 } }))).toContain('No note matches')
  })

  it('lists the missing first, then the stale, then the rest, each with its mark, author and first line', () => {
    const t = text(state(answer()))
    const at = (s: string) => t.indexOf(s)
    expect(at('Gone')).toBeGreaterThan(-1)
    expect(at('Gone')).toBeLessThan(at('Chain1'))
    expect(at('Chain1')).toBeLessThan(at('User'))
    expect(at('User')).toBeLessThan(at('Target'))
    expect(t).toMatch(/▲ Gone .*model .*What App\\Gone does\./)
    expect(t).toMatch(/≈ Chain1 .*model/)
    expect(t).toMatch(/User .*you .*Mine\./)
    expect(t).toMatch(/\? Target .*model/)
    expect(t).not.toContain('A second line.')
    expect(t).toContain('4 notes · 1 missing · 1 stale · 1 flagged')
  })

  it('walks every row in that order; a missing note is no detail to open', () => {
    const list = listFor(pane(state(answer())))
    expect(list.map(i => i.canonical)).toEqual(['App\\Gone', 'App\\Chain1', 'App\\User', 'App\\Target'])
    expect(list[0]?.inert).toBe(true)
    expect(list[2]?.inert).toBeUndefined()
  })

  it('cycles its filter through every one and back, and says which it shows', () => {
    expect(NOTES_FILTERS).toEqual(['all', 'yours', 'model', 'stale', 'missing', 'flagged'])
    expect(NOTES_FILTERS.map(nextFilter)).toEqual(['yours', 'model', 'stale', 'missing', 'flagged', 'all'])
    expect(text(state(answer({ filter: 'stale' })), 100, 60, view({ notesFilter: 'stale' }))).toContain('showing stale')
    expect(text(state(answer()), 100, 60, view({ notesText: 'cache' }))).toContain('narrowed to "cache"')
  })

  it('offers the field to narrow by text while n has it open', () => {
    const t = text(state(answer()), 100, 60, view({ filtering: true, notesText: 'cac' }))
    expect(t).toContain('narrow: cac')
  })

  it('pages a long list like Issues, saying how many are below', () => {
    const rest = Array.from({ length: 80 }, (_, i) => row(`App\\Many${String(i).padStart(2, '0')}`))
    const t = text(state(answer({ groups: { missing: [], stale: [], rest }, counts: { total: 80, missing: 0, stale: 0, flagged: 0 } })), 100, 30)
    expect(t).toContain('Many00')
    expect(t).not.toContain('Many79')
    expect(t).toMatch(/\d+ more ↓/)
  })

  it("offers m, X and g on the marked note: edit the person's, adopt the model's, flag only the model's", () => {
    const keys = (selected: number) => footerRows(pane(state(answer()), view({ selected })), 140, true).bar.map(plainText).join(' ')
    expect(keys(1)).toMatch(/adopt.*remove.*flag/)
    expect(keys(1)).not.toContain('unflag')
    expect(keys(2)).toMatch(/edit.*remove/)
    expect(keys(2)).not.toContain('flag')
    // A note on a missing name has nothing to edit or flag: o finds where it goes.
    expect(keys(0)).not.toMatch(/adopt|edit|flag/)
    // A flagged note offers to take the flag back.
    expect(keys(3)).toContain('unflag')
  })

  it('asks before a write, in the card, and offers only y and n meanwhile', () => {
    const asked = (notesAsk: KnossosView['notesAsk']) => text(state(answer()), 100, 60, view({ selected: 3, notesAsk }))
    expect(asked({ action: 'remove', component: 'App\\Target', author: 'model', kind: 'note' })).toContain("Remove the model's note on Target?")
    expect(asked({ action: 'move', component: 'App\\Gone', author: 'model', kind: 'note', to: 'App\\Adapter' })).toContain('Move the note on App\\Gone to App\\Adapter?')
    expect(asked({ action: 'uncheck', component: 'App\\Target', author: 'model', kind: 'note' })).toContain('Take back the flag on Target?')
    expect(asked({ action: 'check', component: 'App\\Target', author: 'model', kind: 'note', value: 'why' })).toContain('Flag the note on Target for the model; why (optional): why')
    const bar = footerRows(pane(state(answer()), view({ selected: 3, notesAsk: { action: 'remove', component: 'App\\Target', author: 'model', kind: 'note' } })), 140, true).bar.map(plainText).join(' ')
    expect(bar).not.toMatch(/narrow|show:|adopt|remove|flag/)
  })

  it('reads the answer, refusing one without its groups', () => {
    expect(parseNotes(JSON.stringify(answer()))?.counts.total).toBe(4)
    expect(parseNotes(JSON.stringify({ status: 'ok', counts: {} }))).toBeNull()
    expect(parseNotes(JSON.stringify({ status: 'refused', reason: 'no' }))?.status).toBe('refused')
  })
})
