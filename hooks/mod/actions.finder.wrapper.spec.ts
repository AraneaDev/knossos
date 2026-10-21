/**
 * The finder against the real wrapper: the mod's search runs
 * hooks/scripts/knossos-run.sh itself, with KNOSSOS_BIN pointing at a stub
 * that records each run and prints a match. The engine test (register.test.ts)
 * stands in for every process, so it cannot see the wrapper refuse what the
 * mod asks; this is where the mod and the wrapper meet.
 *
 * KNOSSOS_PLUGIN_ROOT names another copy of the plugin to run the wrapper from
 * (a staged install, or one with a guard taken out to see this fail).
 */
import { execFile } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import type { KnossosView, SearchState } from '../../types'
import { handlersOf } from './actions'
import type { Port } from './port'
import { mod, reset } from './state'

const PLUGIN_ROOT = process.env.KNOSSOS_PLUGIN_ROOT ?? fileURLToPath(new URL('../..', import.meta.url))
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }
const GREETER = { type: 'component', name: 'Greeter', canonical_name: 'App\\Greeter', kind: 'class', path: 'src/Greeter.php', line: 3, boundary: 'core' }
/** The finder's search debounce, as the mod schedules it. */
const SEARCH_DEBOUNCE_MS = 150

let work: string

beforeEach(() => {
  reset(options)
  work = mkdtempSync(join(tmpdir(), 'knossos-finder-'))
})

afterEach(() => rmSync(work, { recursive: true, force: true }))

/**
 * A port whose `process.run` runs the argv it is given for real, with
 * KNOSSOS_BIN set to a stub that writes its arguments to `ran` and prints one
 * match; its clock's timers run only when the test says. The project is a
 * directory of its own, apart from the stub, as the wrapper's PATH filter
 * wants.
 */
function realPort() {
  const project = join(work, 'project')
  const bin = join(work, 'bin')
  for (const dir of [project, bin]) mkdirSync(dir, { recursive: true })
  const ran = join(work, 'ran')
  const stub = join(bin, 'knossos')
  writeFileSync(stub, `#!/bin/sh\nprintf '%s\\n' "$*" >> '${ran}'\nprintf '%s\\n' '${JSON.stringify({ status: 'ok', results: [GREETER] })}'\n`, { mode: 0o755 })
  const values: Record<string, unknown> = {
    view: { inspect: null, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', finding: true } as KnossosView,
    search: { query: '', for: null, phase: 'idle', answer: null } as SearchState,
  }
  const state = new Proxy(
    {},
    {
      get: (_, key: string) => ({
        read: async () => values[key] ?? null,
        update: async (change: (v: unknown) => unknown) => (values[key] = change(values[key] ?? null)),
      }),
    },
  )
  const timers: { ms: number; run: () => void; cancelled: boolean }[] = []
  const logs: { text: string; to?: string }[] = []
  const io = {
    state,
    clock: {
      now: async () => 0,
      after: (ms: number, run: () => void) => {
        const timer = { ms, run, cancelled: false }
        timers.push(timer)
        return { cancel: () => void (timer.cancelled = true) }
      },
    },
    ui: { log: (text: string, opts?: { to?: string }) => void logs.push({ text, to: opts?.to }), invalidate: () => undefined, focus: async () => ({}), toast: () => undefined },
    session: { root: async () => project, id: async () => 'sess-1' },
    plugin: { root: PLUGIN_ROOT },
    fs: { stat: async () => Promise.reject(new Error('none')) },
    store: { get: async () => undefined, set: async () => undefined },
    process: {
      run: (argv: string[], opts: { timeoutMs: number }) =>
        new Promise(resolve =>
          execFile(argv[0]!, argv.slice(1), { env: { ...process.env, KNOSSOS_BIN: stub }, timeout: opts.timeoutMs }, (err, stdout, stderr) =>
            resolve({ exitCode: err === null ? 0 : 1, stdout, stderr, isStdoutTruncated: false, isStderrTruncated: false }),
          ),
        ),
    },
  } as unknown as Port
  /** Types `text` into the finder and runs the search it schedules, once, to its end. */
  const search = async (text: string): Promise<SearchState> => {
    handlersOf(io).type('find', text)
    await settle()
    for (const timer of timers.filter(t => !t.cancelled && t.ms === SEARCH_DEBOUNCE_MS)) {
      timer.cancelled = true
      timer.run()
    }
    await until(() => (values.search as SearchState).phase === 'idle' && (values.search as SearchState).for !== null)
    return values.search as SearchState
  }
  /** What the stub was run with, one line a run; none when it never ran. */
  const stubRuns = (): string[] => (existsSync(ran) ? readFileSync(ran, 'utf8').split('\n').filter(l => l !== '') : [])
  return { search, stubRuns, logs, values }
}

/** Lets fire-and-forget handlers run to the end. */
async function settle(): Promise<void> {
  for (let i = 0; i < 20; i++) await new Promise(resolve => setTimeout(resolve, 0))
}

/** Waits, for real, until `done` holds: the wrapper is a process of its own. */
async function until(done: () => boolean, ms = 10_000): Promise<void> {
  const by = Date.now() + ms
  while (!done()) {
    if (Date.now() > by) throw new Error('the search never settled')
    await new Promise(resolve => setTimeout(resolve, 20))
  }
}

describe('the finder through the real wrapper', () => {
  it('finds what was typed: the wrapper runs the binary with the query', async () => {
    const { search, stubRuns } = realPort()
    const found = await search('Greeter')
    expect(found.answer?.status).toBe('ok')
    expect(found.answer?.results.map(r => r.canonical_name)).toEqual(['App\\Greeter'])
    expect(stubRuns()).toHaveLength(1)
    expect(stubRuns()[0]).toMatch(/^graph-search .* --query=Greeter --json$/)
  })

  it('takes a query of exactly 200 characters', async () => {
    const { search, stubRuns } = realPort()
    const found = await search('g'.repeat(200))
    expect(found.answer?.status).toBe('ok')
    expect(stubRuns()).toHaveLength(1)
  })

  it('shows nothing for a query past the wrapper limit, the binary never runs, and the refusal is logged', async () => {
    const { search, stubRuns, logs } = realPort()
    const found = await search('g'.repeat(201))
    expect(found).toMatchObject({ for: 'g'.repeat(201), phase: 'idle', answer: null })
    expect(stubRuns()).toEqual([])
    expect(logs).toEqual([{ text: 'knossos: the wrapper refused graph-search (graph-search does not take these arguments); read as no data', to: 'debug' }])
    expect(mod.session.disabled).toBe(false)
  })
})
