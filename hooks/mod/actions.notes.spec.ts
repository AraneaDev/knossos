import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { KnossosView, NoteRow, NoteState, Notes, NotesState } from '../../types'
import { handlersOf } from './actions'
import { request } from './loaders'
import type { Port } from './port'
import { fieldKey, mod, PANE, reset } from './state'

// The reads run through their own timers and the wrapper: here only whether the notes are asked for again matters.
vi.mock('./loaders', async importOriginal => ({
    ...(await importOriginal<typeof import('./loaders')>()),
    request: vi.fn(async () => undefined),
    requestDetail: vi.fn(async () => undefined),
}))

const ROOT = '/work/app'
const RUN = '/plugin/hooks/scripts/knossos-run.sh'
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }
const VIEW: KnossosView = { inspect: null, isBandHidden: false, tab: 'notes', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' }
const row = (component: string, over: Partial<NoteRow> = {}): NoteRow => ({ component, kind: 'note', author: 'model', value: `What ${component} does.`, updated_at: '2026-10-16T11:00:00Z', component_status: 'present', stale: false, stale_reason: null, checked: false, check_reason: null, ...over })
/**
 * Gone (missing, the model's), Target (the model's, flagged), Mine (the person's). The tree walks them as:
 * 0 Gone and 1 Target (what needs the person), 2 core, 3 src/, 4 Target, 5 Mine, 6 the notes with no boundary.
 */
const NOTES: Notes = {
    status: 'ok',
    groups: {
        missing: [row('App\\Gone', { component_status: 'missing', updated_at: '2026-10-16T11:00:00Z' })],
        stale: [],
        rest: [
            row('App\\Target', { checked: true, check_reason: 'still?', path: 'src/Target.php', boundary: 'core', updated_at: '2026-10-16T11:02:00Z' }),
            row('App\\Mine', { author: 'user', value: 'Mine.', path: 'src/Mine.php', boundary: 'core', updated_at: '2026-10-16T11:01:00Z' }),
        ],
    },
    counts: { total: 3, missing: 1, stale: 0, flagged: 1 },
    truncated: false,
}
const STATE: NotesState = { snapshot: 's1', filter: 'all', text: '', version: 0, phase: 'done', answer: NOTES }
const done = JSON.stringify({ status: 'ok', executed: true, component: 'App\\Target', kind: 'note', action: 'remove' })
const busy = JSON.stringify({ status: 'error', code: 'KNOSSOS_DATABASE_BUSY', message: 'busy' })

/**
 * A port over plain values: cells in a map, timers run when the test says,
 * focus calls recorded, and a wrapper that records every argv and answers
 * with the next queued stdout ('' once the queue is empty).
 */
function fakePort(view: Partial<KnossosView> = {}, initial: Record<string, unknown> = {}) {
    const values = new Map<string, unknown>(Object.entries({ view: { ...VIEW, ...view }, notes: STATE, note: null, detail: null, dashboard: { status: 'ok', project_root: ROOT, snapshot_id: 's1' }, feedback: null, search: { query: '', for: null, phase: 'idle', answer: null }, ...initial }))
    const timers: (() => void)[] = []
    const runs: string[][] = []
    const answers: string[] = []
    const focused: unknown[] = []
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => values.get(key),
                update: async (change: (v: unknown) => unknown) => {
                    values.set(key, change(values.get(key)))
                    return values.get(key)
                },
            }),
        },
    )
    const io = {
        clock: { now: async () => 1_000, after: (_ms: number, run: () => void) => (timers.push(run), { cancel: () => undefined }) },
        ui: { focus: async (args: unknown) => (focused.push(args), {}), scroll: async () => ({}), log: () => undefined, invalidate: () => undefined },
        session: { root: async () => ROOT },
        plugin: { root: '/plugin' },
        process: {
            run: async (argv: string[]) => {
                runs.push(argv)
                return { exitCode: 0, stdout: answers.shift() ?? '', stderr: '' }
            },
        },
        state,
    } as unknown as Port
    const handlers = handlersOf(io)
    const settle = async (): Promise<void> => {
        for (let i = 0; i < 6; i++) await new Promise(resolve => setTimeout(resolve, 0))
    }
    const runTimers = async (): Promise<void> => {
        for (const run of timers.splice(0)) run()
        await settle()
    }
    const press = async (id: string): Promise<void> => {
        handlers.press(id)
        await settle()
    }
    const type = async (field: string, text: string): Promise<void> => {
        handlers.type(field, text)
        await settle()
    }
    const submit = async (field: string, text: string): Promise<void> => {
        handlers.submit(field, text)
        await settle()
    }
    const view_ = () => values.get('view') as KnossosView
    const said = () => (values.get('feedback') as { text: string } | null)?.text ?? null
    return { io, values, runs, answers, focused, press, type, submit, runTimers, view: view_, said, note: () => values.get('note') as NoteState | null }
}

beforeEach(() => {
    reset(options)
    vi.mocked(request).mockClear()
})

afterEach(() => {
    mod.session.disabled = false
})

describe('X on the Notes tab', () => {
    it("asks first, and on y removes the model's note it names, then reads the notes again", async () => {
        const port = fakePort({ selected: 1 })
        await port.press('notes-remove')
        expect(port.view().notesAsk).toEqual({ action: 'remove', component: 'App\\Target', author: 'model', kind: 'note' })
        expect(port.runs).toEqual([])
        port.answers.push(done)
        await port.press('notes-yes')
        await port.runTimers()
        expect(port.runs).toEqual([['sh', RUN, 'annotate', ROOT, '--component=App\\Target', '--remove', '--author=model', '--execute']])
        expect(port.view().notesAsk).toBeNull()
        expect(mod.notes.version).toBe(1)
        expect(vi.mocked(request)).toHaveBeenCalledWith(port.io, 'notes')
        expect(port.said()).toBe('✓ removed the note on Target')
    })

    it("removes the person's own without naming an author, and n drops the question writing nothing", async () => {
        const port = fakePort({ selected: 5 })
        await port.press('notes-remove')
        await port.press('notes-no')
        expect(port.view().notesAsk).toBeNull()
        await port.press('notes-remove')
        port.answers.push(done)
        await port.press('notes-yes')
        await port.runTimers()
        expect(port.runs).toEqual([['sh', RUN, 'annotate', ROOT, '--component=App\\Mine', '--remove', '--execute']])
    })

    it('keeps the question and says to retry while a scan commits', async () => {
        const port = fakePort({ selected: 1 })
        await port.press('notes-remove')
        port.answers.push(busy)
        await port.press('notes-yes')
        await port.runTimers()
        expect(port.view().notesAsk).toEqual({ action: 'remove', component: 'App\\Target', author: 'model', kind: 'note' })
        expect(port.said()).toBe('a scan is committing; try again in a moment')
        expect(mod.notes.version).toBe(0)
    })
})

describe('g on the Notes tab', () => {
    it("takes a reason for flagging the model's note, and Enter flags it", async () => {
        // Plain has no place, so it lives under no boundary, opened here: 0 Target (flagged), 1 core, 2 src/, 3 Target, 4 Mine, 5 no boundary, 6 its folder, 7 Plain.
        const port = fakePort({ selected: 7, folds: ['notes:b+:'] }, { notes: { ...STATE, answer: { ...NOTES, groups: { ...NOTES.groups, missing: [], rest: [row('App\\Plain'), ...NOTES.groups.rest] } } } })
        await port.press('notes-flag')
        expect(port.view().notesAsk).toEqual({ action: 'check', component: 'App\\Plain', author: 'model', kind: 'note', value: '' })
        await port.runTimers()
        expect(port.focused).toEqual([{ requestId: PANE, key: fieldKey('notes-reason') }])
        await port.type('notes-reason', 'the cache moved')
        port.answers.push(done)
        await port.submit('notes-reason', 'the cache moved')
        await port.runTimers()
        expect(port.runs).toEqual([['sh', RUN, 'note-check', ROOT, '--component=App\\Plain', '--value=the cache moved', '--execute']])
        expect(port.said()).toBe('✓ flagged Plain for the model')
    })

    it('asks to take a flag back on a flagged note, and does nothing on the person\'s own', async () => {
        const port = fakePort({ selected: 1 })
        await port.press('notes-flag')
        expect(port.view().notesAsk).toMatchObject({ action: 'uncheck', component: 'App\\Target' })
        port.answers.push(done)
        await port.press('notes-yes')
        await port.runTimers()
        expect(port.runs).toEqual([['sh', RUN, 'note-check', ROOT, '--component=App\\Target', '--remove', '--execute']])
        const mine = fakePort({ selected: 5 })
        await mine.press('notes-flag')
        expect(mine.view().notesAsk ?? null).toBeNull()
    })
})

describe('m on the Notes tab', () => {
    it("opens the component with the model's note in the editor, saved as the person's", async () => {
        const port = fakePort({ selected: 1 })
        await port.press('notes-edit')
        expect(port.view().inspect).toEqual({ name: 'App\\Target', label: 'Target' })
        expect(port.note()).toEqual({ component: 'App\\Target', phase: 'editing', value: 'What App\\Target does.', previous: null, reason: null })
        await port.runTimers()
        expect(port.focused).toContainEqual({ requestId: PANE, key: fieldKey('note') })
    })

    it('says where a note on a missing name goes first, opening nothing', async () => {
        const port = fakePort({ selected: 0 })
        await port.press('notes-edit')
        expect(port.view().inspect).toBeNull()
        expect(port.note()).toBeNull()
        expect(port.said()).toBe('o finds where the note belongs first')
    })
})

describe('o on a note on a missing name', () => {
    it('opens the finder on the name it had, and a pick asks to move the note there', async () => {
        const port = fakePort({ selected: 0 })
        await port.press('open')
        expect(port.view()).toMatchObject({ finding: true, moving: { component: 'App\\Gone', author: 'model', kind: 'note' } })
        expect((port.values.get('search') as { query: string }).query).toBe('Gone')
        await port.press('notes-move:App\\Adapter')
        expect(port.view()).toMatchObject({ finding: false, moving: null, tab: 'notes', notesAsk: { action: 'move', component: 'App\\Gone', author: 'model', kind: 'note', to: 'App\\Adapter' } })
        // The finder's own search ran on the timers while it was open: none of it answers the move.
        await port.runTimers()
        port.runs.length = 0
        port.answers.length = 0
        port.answers.push(done)
        await port.press('notes-yes')
        await port.runTimers()
        expect(port.runs).toEqual([['sh', RUN, 'note-move', ROOT, '--component=App\\Gone', '--to=App\\Adapter', '--author=model', '--execute']])
        expect(port.said()).toBe('✓ moved the note to Adapter')
    })
})

describe('o on a note whose component likely moved', () => {
    it('opens the finder on the suggested component, so Enter on it moves the note there', async () => {
        const moved = { ...NOTES, groups: { ...NOTES.groups, missing: [row('App\\Gone', { component_status: 'missing', suggested_component: 'App\\New\\Gone', updated_at: '2026-10-16T11:00:00Z' })] } }
        const port = fakePort({ selected: 0 }, { notes: { ...STATE, answer: moved } })
        await port.press('open')
        expect(port.view()).toMatchObject({ finding: true, moving: { component: 'App\\Gone', author: 'model', kind: 'note' } })
        expect((port.values.get('search') as { query: string }).query).toBe('App\\New\\Gone')
    })
})

describe('leaving a question or a move', () => {
    it('drops the move when the finder closes, so a later find opens what it finds', async () => {
        const port = fakePort({ selected: 0 })
        await port.press('open')
        expect(port.view().moving).not.toBeNull()
        await port.press('find-close')
        expect(port.view()).toMatchObject({ finding: false, moving: null })
    })

    it('drops the flag question on n, writing nothing', async () => {
        // An unplaced, unflagged Target: 0 Gone, 1 core, 2 src/, 3 Mine, 4 no boundary (opened), 5 its folder, 6 Gone, 7 Target.
        const port = fakePort({ selected: 7, folds: ['notes:b+:'] }, { notes: { ...STATE, answer: { ...NOTES, groups: { ...NOTES.groups, rest: [row('App\\Target'), ...NOTES.groups.rest.slice(1)] } } } })
        await port.press('notes-flag')
        expect(port.view().notesAsk?.action).toBe('check')
        await port.press('notes-no')
        await port.runTimers()
        expect(port.view().notesAsk).toBeNull()
        expect(port.runs).toEqual([])
    })
})

describe('m on a note of several lines', () => {
    it('seeds the editor with it on one line, which the wrapper takes', async () => {
        const port = fakePort({ selected: 7, folds: ['notes:b+:'] }, { notes: { ...STATE, answer: { ...NOTES, groups: { ...NOTES.groups, rest: [row('App\\Target', { value: 'First line.\n\nSecond  paragraph.' }), ...NOTES.groups.rest.slice(1)] } } } })
        await port.press('notes-edit')
        expect(port.note()?.value).toBe('First line. Second paragraph.')
    })
})

describe("the detail's note while a scan commits", () => {
    it('keeps the text, back at the question, and says to retry', async () => {
        const port = fakePort({ inspect: { name: 'App\\Greeter', label: 'Greeter' }, tab: 'hubs' }, { note: { component: 'App\\Greeter', phase: 'confirming', value: 'be kind', previous: null, reason: null } })
        port.answers.push(busy)
        await port.press('note-yes')
        await port.runTimers()
        expect(port.note()).toEqual({ component: 'App\\Greeter', phase: 'confirming', value: 'be kind', previous: null, reason: null })
        expect(port.said()).toBe('a scan is committing; try again in a moment')
    })
})

describe('v on the Notes tab', () => {
    it("says a stale note of the model's is still true, at once, then reads the notes again", async () => {
        const stale = row('App\\Old', { stale: true, stale_reason: 'written before src/Old.php changed', path: 'src/Old.php', boundary: 'core', updated_at: '2026-10-16T11:03:00Z' })
        // 0 Gone, 1 Target (what needs the person), 2 core, 3 src/, 4 Old, 5 Target, 6 Mine.
        const port = fakePort({ selected: 4 }, { notes: { ...STATE, answer: { ...NOTES, groups: { ...NOTES.groups, stale: [stale] } } } })
        port.answers.push(JSON.stringify({ status: 'ok', executed: true, component: 'App\\Old', kind: 'note', action: 'confirm' }))
        await port.press('notes-confirm')
        await port.runTimers()
        expect(port.runs).toEqual([['sh', RUN, 'note-confirm', ROOT, '--component=App\\Old', '--execute']])
        expect(request).toHaveBeenCalledWith(port.io, 'notes')
        expect(port.said()).toBe('✓ the note on Old is still true')
    })

    it('does nothing on a stale note of another kind, nor on one that rests on no file', async () => {
        for (const over of [{ kind: 'intentional' }, { stale_reason: 'rests on no scanned file' }]) {
            const stale = row('App\\Old', { stale: true, stale_reason: 'written before src/Old.php changed', path: 'src/Old.php', boundary: 'core', updated_at: '2026-10-16T11:03:00Z', ...over })
            const port = fakePort({ selected: 4 }, { notes: { ...STATE, answer: { ...NOTES, groups: { ...NOTES.groups, stale: [stale] } } } })
            await port.press('notes-confirm')
            await port.runTimers()
            expect(port.runs, JSON.stringify(over)).toEqual([])
        }
    })

    it('does nothing on a note that is not stale, nor on a group\'s row', async () => {
        for (const selected of [5, 2]) {
            const port = fakePort({ selected })
            await port.press('notes-confirm')
            await port.runTimers()
            expect(port.runs, `${selected}`).toEqual([])
        }
    })
})

