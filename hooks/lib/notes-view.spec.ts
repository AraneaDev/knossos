import { describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView, NoteRow, Notes, NotesState } from '../../types'
import { parseNotes } from './envelopes'
import { footerRows, TABS } from './chrome'
import { listFor } from './pane-list'
import { paneInput } from './pane-input'
import { paneLayout } from './layout'
import { nextFilter, NOTES_FILTERS } from './notes-view'
import { ACCENT } from './palette'
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
    missing: [row('App\\Gone', { component_status: 'missing', updated_at: '2026-10-16T10:00:00Z' })],
    stale: [row('App\\Chain1', { stale: true, stale_reason: 'written before src/Chain1.php changed', path: 'src/Chain1.php', boundary: 'core', updated_at: '2026-10-16T10:01:00Z' })],
    rest: [
      row('App\\User', { author: 'user', value: 'Mine.', path: 'src/User.php', boundary: 'core', updated_at: '2026-10-16T10:02:00Z' }),
      row('App\\Target', { checked: true, check_reason: 'still per request?', path: 'src/Target.php', boundary: 'core', updated_at: '2026-10-16T10:03:00Z' }),
    ],
  },
  counts: { total: 4, missing: 1, stale: 1, flagged: 1 },
  truncated: false,
  ...over,
})
const state = (a: Notes | null, phase: NotesState['phase'] = 'done'): NotesState => ({ snapshot: 's1', filter: 'all', text: '', version: 0, phase, answer: a })
const pane = (s: NotesState | null, v = view()) => paneInput({ dashboard: d, brief: null, refresh: { fetchedAt: 0, failed: false }, rescan: { phase: 'idle', reason: null }, view: v, now: 0, terminal: true, detail: null, allow: null, sessionRoot: null, extras: { notes: s } })
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

  it('puts what needs the person first, then the notes by where they live, each with its author and first line, stale marked quietly', () => {
    const t = text(state(answer()), 100, 60, view({ selected: 2 }))
    const needs = t.indexOf('Needs you')
    expect(needs).toBeGreaterThan(-1)
    expect(t.indexOf('Gone')).toBeGreaterThan(needs)
    expect(t).toMatch(/▲ Gone .*model .*What App\\Gone does\./)
    expect(t).toMatch(/\? Target .*model/)
    // Then the tree: the boundary holding the newest note open, its folder, its notes newest first.
    expect(t).toMatch(/▾ .*core +3/)
    expect(t).toMatch(/▾ src\/ +3/)
    expect(t.lastIndexOf('Target')).toBeLessThan(t.indexOf('User'))
    expect(t.indexOf('User')).toBeLessThan(t.indexOf('Chain1'))
    expect(t).toMatch(/Chain1 .*model .*≈$/m)
    expect(t).toMatch(/User .*you .*Mine\./)
    expect(t).not.toContain('Stale: written before their files changed')
    expect(t).toContain('4 notes · 1 missing · 1 stale · 1 flagged')
  })

  it('walks what needs the person, then each boundary, folder and note; a group folds on o, a missing note opens nothing', () => {
    const list = listFor(pane(state(answer())))
    expect(list.map(i => i.fold ?? i.canonical)).toEqual(['App\\Gone', 'App\\Target', 'notes:b-:core', 'notes:f:core:src', 'App\\Target', 'App\\User', 'App\\Chain1', 'notes:b+:'])
    expect(list[0]?.inert).toBe(true)
    expect(list[5]?.inert).toBeUndefined()
    expect(list[2]?.inert).toBe(true)
    // Folded, the boundary's folder and notes leave the walk.
    expect(listFor(pane(state(answer()), view({ folds: ['notes:b-:core'] }))).map(i => i.fold ?? i.canonical)).toEqual(['App\\Gone', 'App\\Target', 'notes:b+:core', 'notes:b+:'])
  })

  it('shows the marked note whole under the tree: where it lives, who wrote it, when, why it is stale, and all its text', () => {
    const t = text(state(answer()), 100, 60, view({ selected: 6 }))
    expect(t).toContain('A second line.')
    expect(t).toMatch(/src\/Chain1\.php .*core/)
    expect(t).toContain('written before src/Chain1.php changed')
    expect(t).toMatch(/model · 2026-10-16/)
    // On a boundary's row, no note is shown whole.
    expect(text(state(answer()), 100, 60, view({ selected: 2 }))).not.toContain('A second line.')
  })

  it('fills the height it has with the tree', () => {
    for (const height of [40, 60]) {
      const { body, footer } = paneLayout(pane(state(answer()), view({ selected: 6 })), 120, height)
      expect(body.reduce((n, r) => n + (r.code === undefined ? 1 : 0), 0) + footer.length).toBe(height)
    }
  })

  it('names a file note by its file name under its folder, and by its path where no folder stands over it', () => {
    const file = row('hooks/lib/paths.ts', { path: 'hooks/lib/paths.ts', boundary: 'core', checked: true, check_reason: 'still?', updated_at: '2026-10-16T10:04:00Z' })
    const t = text(state(answer({ groups: { ...answer().groups, rest: [file, ...answer().groups.rest] } })), 120, 60)
    expect(t).toMatch(/^ *\? hooks\/lib\/paths\.ts /m)
    expect(t).toMatch(/^ *▾ hooks\/lib\/ +1$/m)
    // In the tree, after the flag's mark: the file name alone.
    expect(t).toMatch(/^ ?\?? {6,}paths\.ts +model/m)
    expect(t).not.toMatch(/^ ?\?? {6,}hooks\/lib\/paths\.ts +model/m)
  })

  it('lines its notes up in columns: the author, then the text, at the same place on every row', () => {
    const long = row('App\\Deep\\AVeryLongComponentName', { author: 'user', value: 'Long.', path: 'src/Deep/AVeryLongComponentName.php', boundary: 'core', updated_at: '2026-10-16T10:05:00Z' })
    const t = text(state(answer({ groups: { ...answer().groups, rest: [long, ...answer().groups.rest] } })), 120, 60)
    const notes = t.split('\n').filter(l => / (model|you) {2,}\S/.test(l))
    expect(notes.length).toBeGreaterThanOrEqual(5)
    expect(new Set(notes.map(l => l.search(/ (model|you) /))).size).toBe(1)
    expect(new Set(notes.map(l => l.search(/(?<=(model|you) +)\S/))).size).toBe(1)
  })

  it("opens a note on a file as that file's detail", () => {
    const file = row('src/A.php', { path: 'src/A.php', boundary: 'core', updated_at: '2026-10-16T10:09:00Z' })
    const list = listFor(pane(state(answer({ groups: { ...answer().groups, rest: [file, ...answer().groups.rest] } }))))
    expect(list.find(i => i.canonical === 'src/A.php')).toMatchObject({ file: true })
    expect(list.find(i => i.canonical === 'App\\User')?.file).toBeUndefined()
  })

  it('opens every group while narrowed by text, so every match shows', () => {
    const list = listFor(pane(state(answer({ text: 'App' })), view({ notesText: 'App', folds: ['notes:b-:core'] })))
    expect(list.filter(i => i.fold === undefined).map(i => i.canonical)).toContain('App\\Chain1')
  })

  it('marks the hit in each line of a note while narrowed by text, in any case', () => {
    const body = paneLayout(pane(state(answer({ text: 'TARGET' })), view({ notesText: 'TARGET' })), 120, 60).body
    const hits = body.flatMap(r => r.segments).filter(s => s.bold === true && s.color === ACCENT)
    expect(hits.map(s => s.text)).toContain('Target')
    expect(paneLayout(pane(state(answer())), 120, 60).body.flatMap(r => r.segments).some(s => s.bold === true && s.text === 'Target')).toBe(false)
  })

  it('opens every group under a filter too, since what it narrowed to is what the person came for', () => {
    const stale = answer({ filter: 'stale', groups: { missing: [], stale: answer().groups.stale, rest: [] }, counts: { total: 4, missing: 1, stale: 1, flagged: 1 } })
    const list = listFor(pane(state(stale), view({ notesFilter: 'stale', folds: ['notes:b-:core'] })))
    expect(list.filter(i => i.fold === undefined).map(i => i.canonical)).toEqual(['App\\Chain1'])
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
    expect(keys(6)).toMatch(/adopt.*remove.*flag/)
    expect(keys(6)).not.toContain('unflag')
    expect(keys(5)).toMatch(/edit.*remove/)
    expect(keys(5)).not.toContain('flag')
    // A note on a missing name has nothing to edit or flag: o finds where it goes.
    expect(keys(0)).not.toMatch(/adopt|edit|flag/)
    // A flagged note offers to take the flag back.
    expect(keys(4)).toContain('unflag')
    // A boundary's row is no note: nothing to edit, remove or flag.
    expect(keys(2)).not.toMatch(/adopt|edit|remove|flag/)
  })

  it('offers v on a stale note of the model\'s, to say it is still true, and on no other', () => {
    const press = (selected: number) => footerRows(pane(state(answer()), view({ selected })), 140, true).bar.flatMap(r => r.segments).find(s => s.press?.id === 'notes-confirm')?.press
    expect(press(6)).toMatchObject({ hotkey: 'v' })
    expect(plainText(footerRows(pane(state(answer()), view({ selected: 6 })), 140, true).bar[0]!)).toContain('still true')
    for (const other of [0, 4, 5, 2]) expect(press(other), `${other}`).toBeUndefined()
  })

  it('offers v only on a plain note that rests on a file, and says so only there', () => {
    // Chain1, stale, is the note at 6: as another kind, and resting on no file.
    const as = (over: Partial<NoteRow>) => answer({ groups: { ...answer().groups, stale: [{ ...answer().groups.stale[0]!, ...over }] } })
    const offered = (a: Notes) => footerRows(pane(state(a), view({ selected: 6 })), 140, true).bar.flatMap(r => r.segments).some(s => s.press?.id === 'notes-confirm')
    expect(offered(as({ kind: 'intentional' }))).toBe(false)
    const unfiled = as({ stale_reason: 'rests on no scanned file' })
    expect(offered(unfiled)).toBe(false)
    expect(text(state(unfiled), 100, 60, view({ selected: 6 }))).not.toContain('v says it is still true')
    expect(text(state(answer()), 100, 60, view({ selected: 6 }))).toContain('v says it is still true')
  })

  it('asks before a write, in the card, and offers only y and n meanwhile', () => {
    const asked = (notesAsk: KnossosView['notesAsk']) => text(state(answer()), 100, 60, view({ selected: 4, notesAsk }))
    expect(asked({ action: 'remove', component: 'App\\Target', author: 'model', kind: 'note' })).toContain("Remove the model's note on Target?")
    expect(asked({ action: 'move', component: 'App\\Gone', author: 'model', kind: 'note', to: 'App\\Adapter' })).toContain('Move the note on App\\Gone to App\\Adapter?')
    expect(asked({ action: 'uncheck', component: 'App\\Target', author: 'model', kind: 'note' })).toContain('Take back the flag on Target?')
    expect(asked({ action: 'check', component: 'App\\Target', author: 'model', kind: 'note', value: 'why' })).toContain('Flag the note on Target for the model; why (optional): why')
    const bar = footerRows(pane(state(answer()), view({ selected: 4, notesAsk: { action: 'remove', component: 'App\\Target', author: 'model', kind: 'note' } })), 140, true).bar.map(plainText).join(' ')
    expect(bar).not.toMatch(/narrow|show:|adopt|remove|flag/)
  })

  it('gives every key a digit or a lowercase letter, as the engine takes them, remove on w', () => {
    const presses = (v: Partial<KnossosView>) => [...footerRows(pane(state(answer()), view(v)), 140, true).bar, ...paneLayout(pane(state(answer()), view(v)), 140, 60).body].flatMap(r => r.segments).flatMap(s => (s.press?.hotkey === undefined ? [] : [s.press]))
    for (const v of [{ selected: 6 }, { selected: 5 }, { selected: 4, notesAsk: { action: 'remove' as const, component: 'App\\Target', author: 'model' as const, kind: 'note' } }, { selected: 4, notesAsk: { action: 'check' as const, component: 'App\\Target', author: 'model' as const, kind: 'note', value: '' } }]) {
      for (const p of presses(v)) expect(p.hotkey, p.id).toMatch(/^[0-9a-z]$/)
    }
    expect(presses({ selected: 6 }).find(p => p.id === 'notes-remove')?.hotkey).toBe('w')
  })

  it('offers n to drop the flag question as well as Enter to flag', () => {
    const body = paneLayout(pane(state(answer()), view({ selected: 4, notesAsk: { action: 'check', component: 'App\\Target', author: 'model', kind: 'note', value: '' } })), 140, 60).body.flatMap(r => r.segments)
    expect(body.find(s => s.press?.id === 'notes-no')?.press?.hotkey).toBe('n')
  })

  it('reads the answer, refusing one without its groups', () => {
    expect(parseNotes(JSON.stringify(answer()))?.counts.total).toBe(4)
    expect(parseNotes(JSON.stringify({ status: 'ok', counts: {} }))).toBeNull()
    expect(parseNotes(JSON.stringify({ status: 'refused', reason: 'no' }))?.status).toBe('refused')
  })
})
