import { describe, expect, it } from 'vitest'
import type { BlastRadius, ComponentDetail, Dashboard, KnossosView, NoteState, RingsState } from '../../types'
import { detailInput } from './views'
import { listFor } from './pane-list'
import { paneInput } from './pane-input'
import { paneLayout } from './layout'
import { RING_MIN, ringRows, ringsInput, ringsList, RINGS_MIN } from './rings'
import { plainText, rawText } from './__tests__/plain-text'
import { rowWidth } from './rows'

const ROOT = '/work/app'
const d = { status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: 's1', freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false } as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: { name: 'App\\Store\\StableId', label: 'StableId' }, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })
const member = (name: string, tested: boolean, boundary = 'core') => ({ name, canonical_name: `App\\${name}`, kind: 'class', path: `src/${name}.php`, line: 3, boundary, tested })
const radius = (n = 4): BlastRadius => ({
  status: 'ok',
  component: { name: 'StableId', canonical_name: 'App\\Store\\StableId', kind: 'class', boundary: 'core' },
  truncated: false,
  rings: [
    { hop: 1, count: n, tested: n - 1, items: Array.from({ length: n }, (_, i) => member(`Near${i}`, i > 0, i === 1 ? 'edge' : 'core')), tests: { count: 2, items: [{ path: 'tests/NearTest.php', hop: 2 }, { path: 'tests/Other/FarTest.php', hop: 3 }] } },
    { hop: 2, count: 30, tested: 30, items: Array.from({ length: 12 }, (_, i) => member(`MiddleComponentWithALongerName${i}`, true)), tests: { count: 9, items: Array.from({ length: 9 }, (_, i) => ({ path: `tests/Middle${i}Test.php`, hop: 3 })) } },
    { hop: 3, count: 0, tested: 0, items: [], tests: { count: 0, items: [] } },
  ],
})
const state = (a: BlastRadius | null, name = 'App\\Store\\StableId'): RingsState => ({ name, snapshot: 's1', phase: 'done', answer: a })
const detailAnswer: ComponentDetail = {
  status: 'ok',
  path: ROOT,
  name: 'App\\Store\\StableId',
  project_id: 'p1',
  snapshot_id: 's1',
  component: {
    name: 'App\\Store\\StableId',
    display_name: 'StableId',
    kind: 'class',
    path: 'src/Store/StableId.php',
    line: 9,
    boundary: 'core',
    boundaries: ['core'],
    used_by: { count: 1, truncated: false, names: [], items: [{ name: 'Caller', canonical_name: 'App\\Caller', kind: 'class', boundary: 'core', edges: 2 }] },
    uses: { count: 0, truncated: false, names: [], items: [] },
    annotations: [],
  },
  candidates: [],
}
const shown = detailInput({ name: 'App\\Store\\StableId', label: 'StableId' }, { snapshot_id: 's1', name: 'App\\Store\\StableId', detail: detailAnswer, phase: 'done' }, ROOT)
const pane = (rings: RingsState | null, v = view(), note: NoteState | null = null) => paneInput({ dashboard: d, brief: null, refresh: { fetchedAt: 0, failed: false }, rescan: { phase: 'idle', reason: null }, view: v, now: 0, terminal: true, detail: shown, allow: null, sessionRoot: null, extras: { rings, note } })
const text = (rings: RingsState | null, columns = 100, v = view()) => paneLayout(pane(rings, v), columns, 80).body.map(plainText).join('\n')

describe('the blast radius in a component detail', () => {
  it('says while it reads, and when nothing depends on the component', () => {
    expect(text(null)).toContain('Reading what depends on it, hop by hop…')
    expect(text(state(null))).toContain('knossos did not answer')
    expect(text(state({ ...radius(), status: 'not-found', component: null, rings: [] }))).toContain('knossos found no component by that name')
    const none = radius()
    none.rings.forEach(r => Object.assign(r, { count: 0, tested: 0, items: [], tests: { count: 0, items: [] } }))
    expect(text(state(none))).toContain('Nothing depends on it: a change here reaches no other component.')
    // The rings of another component never show on this one's detail.
    expect(text(state(radius(), 'App\\Other'))).toContain('Reading what depends on it')
  })

  it('says when the tests were searched only in part, so untested may mean not looked at', () => {
    expect(text(state(radius()))).not.toContain('tests partly searched')
    expect(text(state({ ...radius(), tests_partial: true }))).toContain('34 dependents, tests apart, tests partly searched')
  })

  it("counts a ring's probable members beside its count, and names the member a dependant came through", () => {
    const r = radius()
    r.rings[0] = { ...r.rings[0]!, by_confidence: { certain: 2, probable: 1, possible: 1 }, items: r.rings[0]!.items.map((m, i) => (i === 0 ? { ...m, via_member: 'App\\Store\\StableId::make' } : m)) }
    const t = text(state(r))
    expect(t).toMatch(/1 hop +4 \(2 probable\)/)
    expect(t).toMatch(/Near0 via make/)
    // None probable: the count alone.
    expect(t).not.toMatch(/2 hops +30 \(/)
    expect(text(state(r), 40)).toContain('1 hop · 4 (2 probable)')
  })

  it('gives a ring its legend at the right end of its rows: how many are tested, then how many are not', () => {
    const rows = paneLayout(pane(state(radius())), 100, 80).body.map(r => plainText(r).trim())
    const head = rows.findIndex(r => r.startsWith('1 hop'))
    expect(rows[head]).toMatch(/ ✓ 3 tested$/)
    expect(rows[head + 1]).toMatch(/ ▲ 1 untested$/)
    expect(rows.find(r => r.startsWith('2 hops'))).toMatch(/ ✓ all tested$/)
    expect(rows.find(r => r.startsWith('3+ hops'))).toMatch(/^3\+ hops +0 +none$/)
    // Narrow, the legend shares the ring's row while it fits.
    expect(text(state(radius()), 60)).toMatch(/1 hop · 4 +✓ 3 tested · ▲ 1 untested/)
  })

  it('marks each ring member with its kind before its name', () => {
    expect(text(state(radius()))).toMatch(/C Near0/)
  })

  it('says a ring no test file reaches is a warning, not an error', () => {
    const untested = radius()
    Object.assign(untested.rings[0]!, { tests: { count: 0, items: [] } })
    const shown = text(state(untested))
    expect(shown).toMatch(/▲ +no test file reaches it/)
    expect(shown).not.toContain('✗')
  })

  it('lines the rings up in columns, nearest first, a blank row between two, no box round them and no second name for the component', () => {
    const rows = ringRows(ringsInput(state(radius()), 'App\\Store\\StableId', ROOT), 96, -1, 0, undefined, 5)
    const raw = rows.map(rawText)
    expect(rows.map(r => r.key)).toEqual([
      'ring-0-head', 'ring-0-member-1', 'ring-0-member-2', 'ring-0-member-3', 'ring-0-tests', 'ring-1-gap',
      'ring-1-head', 'ring-1-member-1', 'ring-1-member-2', 'ring-1-member-3', 'ring-1-member-4', 'ring-1-more', 'ring-1-tests', 'ring-2-gap', 'ring-2-head',
    ])
    // Each ring's distance and count on its first row, at the body, the counts right-aligned.
    expect(rows.filter(r => r.key.endsWith('-head')).map(r => rawText(r).slice(0, 16))).toEqual(['   1 hop     4  ', '   2 hops   30  ', '   3+ hops   0  '])
    expect(rawText(rows[5]!).trim()).toBe('')
    expect(raw.some(r => /[│╭╮╰╯◉]/.test(r))).toBe(false)
    for (const r of rows) expect(rowWidth(r), rawText(r)).toBeLessThanOrEqual(96)
    // Marks in one column, names in the next, across every ring.
    const named = raw.filter(r => /[A-Z] (Near|Middle)/.test(r))
    expect(new Set(named.map(r => r.search(/(Near|Middle)/))).size).toBe(1)
    expect(new Set(named.map(r => r.search(/[CI] (Near|Middle)/))).size).toBe(1)
    // The untested marked before its kind; a boundary named only where it is not the component's own.
    expect(raw.find(r => r.includes('Near0'))).toMatch(/▲ C Near0/)
    expect(raw.find(r => r.includes('Near1'))).toMatch(/ {2}C Near1 +■ edge/)
    expect(raw.find(r => r.includes('Near2'))).not.toContain('■')
    // The standing in one column at the right of the names.
    const standing = raw.filter(r => /(tested|none)$/.test(r.trimEnd())).map(r => r.search(/(✓|▲ \d|none)/))
    expect(new Set(standing).size).toBe(1)
    // What the ring does not show, then the tests that reach it, by file name, then how many more.
    expect(raw.find(r => r.includes('more'))!.trim()).toBe('+25 more')
    expect(raw.find(r => r.includes('Middle0Test.php'))).toMatch(/✓ +9 test files {2}Middle0Test\.php, .* \+\d+$/)
    expect(raw.find(r => r.includes('NearTest'))!.trim()).toBe('✓   2 test files  NearTest.php, FarTest.php')
  })

  it("draws the owner's sketch: distance and count, marks, names, boundaries, standing, then what is not shown and the tests (100 columns)", () => {
    const m = (name: string, kind: string, boundary: string, tested: boolean) => ({ name, canonical: `X\\${name}`, kind, boundary, tested, loc: null })
    const sketch = {
      loading: false,
      said: null,
      centre: { name: 'MaintenanceCommand::importNotes', boundary: 'Cli' },
      truncated: false,
      rings: [
        { label: '1 hop', count: 1, tested: 1, members: [m('MaintenanceCommand::run', 'method', 'Cli', true)], tests: { count: 27, paths: ['tests/CommandsTest.php'] } },
        { label: '3+ hops', count: 15, tested: 13, members: [m('benchmark.php', 'file', 'tooling', false), m('quality', 'file', 'tooling', false), m('Application::run', 'method', 'core', true)], tests: { count: 22, paths: ['tests/ApplicationTest.php'] } },
      ],
    }
    expect(ringRows(sketch, 96, -1, 0).map(r => rawText(r).trimEnd())).toEqual([
      '   1 hop     1     m MaintenanceCommand::run              ✓ all tested',
      '                 ✓   27 test files  CommandsTest.php +26',
      '',
      '   3+ hops  15   ▲   benchmark.php            ■ tooling   ✓ 13 tested',
      '                 ▲   quality                  ■ tooling   ▲ 2 untested',
      '                   m Application::run         ■ core',
      '                 +12 more',
      '                 ✓   22 test files  ApplicationTest.php +21',
    ])
  })

  it('shows each ring as long as the height lets it, at least a few, the marked one always among them', () => {
    const input = ringsInput(state(radius()), 'App\\Store\\StableId', ROOT)
    const presses = (rows: ReturnType<typeof ringRows>) => rows.flatMap(r => r.segments).flatMap(s => (s.press === undefined ? [] : [s.press.id]))
    // Tall, every listed member is a press, nearest ring first, in the walk's order.
    expect(presses(ringRows(input, 96, -1, 1)).filter(id => id.startsWith('rel:'))).toEqual(Array.from({ length: 16 }, (_, i) => `rel:${i + 1}`))
    // Short, a ring lists its fewest and one more press walks to the first it hides; marked further down, the window follows.
    const short = presses(ringRows(input, 96, -1, 1, undefined, RING_MIN))
    // The near ring's fourth is shown rather than counted: a `+1 more` would take its row.
    expect(short).toEqual(['rel:1', 'rel:2', 'rel:3', 'rel:4', 'rel:5', 'rel:6', 'rel:7', 'more:8'])
    expect(presses(ringRows(input, 96, 12, 1, undefined, RING_MIN))).toContain('rel:12')
    // Five of five at a limit of three: three, and a press to the fourth.
    expect(presses(ringRows(ringsInput(state(radius(5)), 'App\\Store\\StableId', ROOT), 96, -1, 0, undefined, 3)).slice(0, 4)).toEqual(['rel:0', 'rel:1', 'rel:2', 'more:3'])
  })

  it('makes every named component a row the marker walks and o opens, after the detail\'s own', () => {
    const input = pane(state(radius()))
    const list = listFor(input)
    // The one dependent the detail lists, then the rings' four near and twelve further.
    expect(list.map(o => o.canonical)).toEqual(['App\\Caller', ...[0, 1, 2, 3].map(i => `App\\Near${i}`), ...Array.from({ length: 12 }, (_, i) => `App\\MiddleComponentWithALongerName${i}`)])
    expect(ringsList(ringsInput(state(radius()), 'App\\Store\\StableId', ROOT))[0]?.loc).toEqual({ path: `${ROOT}/src/Near0.php`, line: 3 })
    const rows = paneLayout(pane(state(radius()), view({ selected: 2 })), 100, 80).body
    const marked = rows.flatMap(r => r.segments).find(s => s.press?.id === 'rel:2')
    expect(marked).toMatchObject({ bg: 'userMessageBackground', bold: true })
  })

  it('gives the rings the height the pane has: their fewest on a short pane, every listed member on a tall one', () => {
    const named = (height: number) => paneLayout(pane(state(radius())), 100, height).body.filter(r => /^ring-1-(head|member)/.test(r.key)).length
    expect(named(24)).toBe(RING_MIN)
    expect(named(120)).toBe(12)
    // The footer stays whole on the short pane: the body scrolls under it.
    expect(paneLayout(pane(state(radius())), 100, 24).footer.map(plainText).join(' ')).toContain('b: back')
  })

  it('fits every width and height, in columns from the width they fit and as lists below it', () => {
    for (const columns of [40, 60, 80, 100, 130, 140, 200]) {
      for (const height of [24, 40, 60]) {
        for (const r of [null, state(radius(1)), state(radius(40))]) {
          const { body, footer } = paneLayout(pane(r), columns, height)
          for (const row of [...body, ...footer]) expect(rowWidth(row), `${columns}x${height} ${row.key}`).toBeLessThanOrEqual(columns)
        }
      }
    }
    // Narrow, the figures on the ring's own row and the legend under them when both do not fit.
    expect(text(state(radius()), 40)).toMatch(/1 hop · 4\n +✓ 3 tested · ▲ 1 untested/)
    expect(text(state(radius()), 40)).not.toMatch(/1 hop +4 {3}/)
    expect(RINGS_MIN).toBeLessThanOrEqual(60)
  })
})

describe('notes on a component detail', () => {
  const note = (over: Partial<NoteState> = {}): NoteState => ({ component: 'App\\Store\\StableId', phase: 'editing', value: 'Keep it pure', previous: null, reason: null, ...over })
  const rows = (n: NoteState | null, columns = 100) => paneLayout(pane(null, view(), n), columns, 80).body
  const said = (n: NoteState | null, columns = 100) => rows(n, columns).map(plainText).join('\n')
  const presses = (n: NoteState | null) => rows(n).flatMap(r => r.segments.flatMap(s => (s.press === undefined ? [] : [`${s.press.id}${s.press.hotkey === undefined ? '' : `=${s.press.hotkey}`}`])))

  const footerPresses = (n: NoteState | null) =>
    paneLayout(pane(null, view(), n), 100, 80).footer.flatMap(r => r.segments.flatMap(s => (s.press === undefined ? [] : [`${s.press.id}${s.press.hotkey === undefined ? '' : `=${s.press.hotkey}`}`])))

  it('offers m to add one in the footer, where it is never scrolled away, and says there is none yet', () => {
    expect(said(null)).toMatch(/Notes\n *\n {3}No notes on StableId yet\./)
    expect(footerPresses(null)).toContain('note=m')
    // The card's own button is a click under a key of its own (two Buttons may not share one), not a second m.
    expect(presses(null)).toContain('note-card')
    expect(presses(null)).not.toContain('note=m')
    // While a note is under way the field and its question have the keys.
    for (const phase of ['editing', 'confirming'] as const) expect(footerPresses(note({ phase }))).not.toContain('note=m')
  })

  const footer = (n: NoteState | null, columns = 100) => paneLayout(pane(null, view(), n), columns, 80).footer
  const footerSaid = (n: NoteState | null, columns = 100) => footer(n, columns).map(plainText).join('\n')

  it('takes the note in a field in the footer, asks there before recording it, and says what it replaces', () => {
    // In the footer, where neither a long detail nor its scroll can hide it; the card only lists the notes.
    const field = footer(note()).flatMap(r => r.segments).find(s => s.field !== undefined)
    expect(field?.field).toEqual({ id: 'note', value: 'Keep it pure', placeholder: 'what to remember about StableId' })
    expect(rows(note()).flatMap(r => r.segments).some(s => s.field !== undefined)).toBe(false)
    expect(footerSaid(note())).toContain('Enter checks it with knossos; nothing is written yet.')
    expect(footerSaid(note({ phase: 'previewing' }))).toContain('checking with knossos…')
    const asked = footerSaid(note({ phase: 'confirming', previous: 'Pure: no I/O.' }))
    expect(asked.replace(/\s+/g, ' ')).toContain('Record this note on StableId? "Keep it pure" It replaces: "Pure: no I/O."')
    expect(footerPresses(note({ phase: 'confirming' }))).toEqual(expect.arrayContaining(['note-yes=y', 'note-no=n']))
    expect(said(note({ phase: 'confirming' }))).not.toContain('Record this note')
    expect([...presses(note({ phase: 'confirming' })), ...footerPresses(note({ phase: 'confirming' }))]).not.toContain('note=m')
    expect(footerSaid(note({ phase: 'saving' }))).toContain('recording…')
    // A failure is said in the card, and m is offered again.
    expect(said(note({ phase: 'failed', reason: 'kind must be one of: note.' }))).toContain('✗ knossos did not record it: kind must be one of: note.')
    expect(footerPresses(note({ phase: 'failed' }))).toContain('note=m')
    // Every footer row fits, however narrow.
    for (const columns of [40, 60, 100, 200]) for (const r of footer(note({ phase: 'confirming', previous: 'x'.repeat(300) }), columns)) expect(rowWidth(r), `${columns}`).toBeLessThanOrEqual(columns)
  })

  it('answers by press alone while the allow-root question holds y and n', () => {
    const allowing = paneInput({ dashboard: d, brief: { status: 'not-allowed', path: ROOT, refused_root: ROOT, roots_file: null } as never, refresh: { fetchedAt: 0, failed: false }, rescan: { phase: 'idle', reason: null }, view: view(), now: 0, terminal: true, detail: shown, allow: { phase: 'confirming', root: ROOT, reason: null }, sessionRoot: null, extras: { note: note({ phase: 'confirming' }) } })
    const laid = paneLayout(allowing, 100, 80)
    const keys = [...laid.body, ...laid.footer].flatMap(r => r.segments.flatMap(s => (s.press === undefined ? [] : [`${s.press.id}${s.press.hotkey === undefined ? '' : `=${s.press.hotkey}`}`])))
    expect(keys).toEqual(expect.arrayContaining(['allow-yes=y', 'note-yes', 'note-no']))
  })

  it('belongs to the component it was started on, and fits every width', () => {
    expect(said(note({ component: 'App\\Other' }))).not.toContain('Keep it pure')
    for (const columns of [40, 60, 80, 100, 130, 140, 200]) {
      for (const n of [null, note(), note({ phase: 'confirming', value: 'x'.repeat(300), previous: 'y'.repeat(200) }), note({ phase: 'failed', reason: 'z'.repeat(200) })]) {
        for (const r of rows(n, columns)) expect(rowWidth(r), `${columns} ${r.key}`).toBeLessThanOrEqual(columns)
      }
    }
  })
})
