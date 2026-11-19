import { expect, mock } from 'claude-code/testing'
import type { Engine } from 'claude-code/testing'
import type { On } from 'claude-code'

export const ROOT = '/repo'

export type Answer = { stdout: string; hold?: number }

export const dashboard = JSON.stringify({
  status: 'ok',
  path: ROOT,
  project_root: ROOT,
  project_id: 'p1',
  snapshot_id: 's1',
  freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
  hubs: [],
  hubs_truncated: false,
  hubs_truncation_reasons: [],
  hotspots: [],
  dead_code_candidates: 0,
  dead_code_truncated: false,
  cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
  trend: [],
  fan_in: [{ path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'] }],
  fan_in_truncated: false,
})

export const brief = (over: Record<string, unknown> = {}) =>
  JSON.stringify({
    status: 'ok',
    path: ROOT,
    project_root: ROOT,
    project_id: 'p1',
    snapshot_id: 's1',
    scanned_at: 0,
    scan_ms: 5,
    reason: null,
    roots_file: null,
    refused_root: null,
    changed_files: ['src/Router.php'],
    added_files: [],
    deleted_files: [],
    impact: { 'src/Router.php': { path: 'src/Router.php', dependent_files: 41, boundaries: ['Http'], boundary: 'Http' } },
    tests: [],
    policy: { status: 'evaluated', total: 0, violations: [], truncated: false },
    ...over,
  })

/**
 * Stands in for the engine beneath the mod: the session root, the wrapper's
 * answers (a queue per subcommand, the last answer repeating), and every
 * engine call the mod makes, recorded.
 */
export function world(
  on: On,
  answers: {
    dashboard?: Answer[]
    brief?: Answer[]
    detail?: Answer[]
    file?: Answer[]
    scan?: Answer[]
    allow?: Answer[]
    /** What `claim` answers (the folder /knossos was run in): already allowed, by default. */
    claim?: Answer[]
    /** What `autostart` answers (the project's pane setting): off, by default. */
    autostart?: Answer[]
    /** What `session-changes` answers: the scan ledger's changes since the session began. */
    ledger?: Answer[]
    /** What `session-head` answers (the commit the session begins at), and `session-diff` (a file's change since it). */
    head?: Answer[]
    diff?: Answer[]
    /** What `boundary-couplings` answers: one heat map cell spelled out. */
    couplings?: Answer[]
    /** What `graph-search` (the finder), `branch-diff` (the Branch tab) and `file-context` (the model's tool) answer. */
    search?: Answer[]
    branch?: Answer[]
    /** What `churn` (the Churn tab), `blast-radius` (a detail's rings), `path-between` (a route) and `annotate` (a note) answer. */
    churn?: Answer[]
    rings?: Answer[]
    route?: Answer[]
    note?: Answer[]
    /** What `notes` (the Notes tab's list) answers. */
    notes?: Answer[]
    /**
     * Whether the plugin's `$.session.append` lands: the kit stores the row and
     * `appended` reads it back. Left out, the world refuses it, and every
     * turn-end note lands in the debug log instead.
     */
    appendRows?: boolean
    editor?: 'opens' | 'missing'
    refuseRegister?: () => boolean | Promise<boolean>
    /** Per watcher start, the event lines it writes at once; none left: the start writes nothing and ends (no watcher offered). */
    watch?: object[][]
    /** How long each Bash call runs on the mocked clock (a command that takes a while). */
    bashMs?: number
    /** What a Bash call prints, by its command (`ran Bash` otherwise). */
    bashOutput?: (command: string) => string
    /**
     * The project's repository: the reflog entries each Bash command adds to
     * HEAD, oldest first (`commit: x`, `checkout: moving from a to b`), each
     * moving HEAD to a new commit. Absent: the project is in no repository.
     * `reflogOff` keeps HEAD moving but prints no reflog.
     */
    git?: (command: string) => string[]
    reflogOff?: boolean
    /** The session's id, and what the plugin's store holds at the start (an earlier process's baselines). */
    sessionId?: string
    store?: Record<string, unknown>
  } = {},
  disk: { root?: string; links?: Record<string, string>; gone?: string[]; garbled?: string[] } = {},
) {
  const clock = mock.clock(on)
  /** The project's repository: HEAD and its reflog, newest first, one commit to begin with. */
  const repo = { made: 1, head: '1'.padStart(40, '0'), reflog: [{ sha: '1'.padStart(40, '0'), subject: 'commit (initial): start' }] }
  // The plugin's own store, in memory, readable by the test: what an earlier process left, and what this one keeps.
  const store = new Map<string, unknown>(Object.entries(answers.store ?? {}))
  // The kit's own answer to a plugin's session.append differs by version (a refusal, or an echo that resolves), so
  // by default the world refuses it itself: every turn-end note then lands in the debug log, the same on any kit.
  // With `appendRows` the row is stored as a session stores it, and read back from there.
  const session = answers.appendRows === true ? mock.session(on) : null
  if (session === null) on('session.append', () => ({ deny: 'refused by the test world' }))
  /** The text of every row the mod appended to the conversation, oldest first. */
  const appended = (): string[] => ((session?.appended() ?? []) as unknown[]).map((row: unknown) => JSON.stringify((row as { message?: unknown }).message ?? row))
  on('store.get', (_$, e) => ({ value: structuredClone(store.get(e.key)) }))
  on('store.set', (_$, e) => {
    store.set(e.key, structuredClone(e.value))
    return { value: undefined }
  })
  on('store.delete', (_$, e) => {
    store.delete(e.key)
    return { value: undefined }
  })
  on('store.keys', () => ({ value: [...store.keys()] }))
  let sessionId = answers.sessionId ?? 'session-1'
  on('session.id', () => ({ value: sessionId }))
  /** The process going on under another session id, as after a /clear or a resume. */
  const switchSession = (id: string) => (sessionId = id)
  const links = disk.links ?? {}
  const gone = new Set(disk.gone ?? [])
  const queues = {
    dashboard: answers.dashboard ?? [{ stdout: dashboard }],
    brief: answers.brief ?? [{ stdout: brief() }],
    detail: answers.detail ?? [{ stdout: '' }],
    file: answers.file ?? [{ stdout: '' }],
    scan: answers.scan ?? [{ stdout: '{"status":"ok"}' }],
    allow: answers.allow ?? [{ stdout: '{"path":"/repo","roots_file":"/data/roots.json","added":true}' }],
    claim: answers.claim ?? [{ stdout: '{"status":"present","root":"/repo","reason":null}' }],
    autostart: answers.autostart ?? [{ stdout: '{"status":"ok","autostart":false,"root":"/repo"}' }],
    ledger: answers.ledger ?? [{ stdout: '' }],
    head: answers.head ?? [{ stdout: '' }],
    diff: answers.diff ?? [{ stdout: '' }],
    couplings: answers.couplings ?? [{ stdout: '' }],
    search: answers.search ?? [{ stdout: '' }],
    branch: answers.branch ?? [{ stdout: '' }],
    churn: answers.churn ?? [{ stdout: '' }],
    rings: answers.rings ?? [{ stdout: '' }],
    route: answers.route ?? [{ stdout: '' }],
    note: answers.note ?? [{ stdout: '' }],
    notes: answers.notes ?? [{ stdout: '' }],
  }
  /**
   * The wrapper's subcommands the mod runs, each to the answers it gets. One
   * missing here fails the test where the mod runs it: the wrapper would
   * refuse it, so it is never answered as some other subcommand.
   */
  const wrapped = new Map<string, Answer[]>([
    ['turn-brief', queues.brief],
    ['dashboard', queues.dashboard],
    ['component-detail', queues.detail],
    ['file-detail', queues.file],
    ['scan', queues.scan],
    ['allow-root', queues.allow],
    ['claim', queues.claim],
    ['autostart', queues.autostart],
    ['session-changes', queues.ledger],
    ['session-head', queues.head],
    ['session-diff', queues.diff],
    ['boundary-couplings', queues.couplings],
    ['graph-search', queues.search],
    ['branch-diff', queues.branch],
    ['churn', queues.churn],
    ['blast-radius', queues.rings],
    ['path-between', queues.route],
    ['annotate', queues.note],
    ['notes', queues.notes],
    ['note-move', queues.note],
    ['note-check', queues.note],
    ['note-confirm', queues.note],
  ])
  /** Every prompt submitted, and every copy with the surface it was for. */
  const prompts: string[] = []
  const copies: { text: string; surface: string | undefined }[] = []
  const calls: string[][] = []
  const toasts: string[] = []
  const logs: { text: string; to: string }[] = []
  const invalidations: string[] = []
  const opened: string[] = []
  const closed: string[] = []
  /** The panes the engine holds open, as `ui.panes` lists them. */
  const panes = new Set<string>()
  on('session.start', (_$, e) => ({ cwd: e.cwd }))
  on('session.root', () => ({ value: disk.root ?? ROOT }))
  // Every path exists except those gone; a path under a link lands under its target; a garbled one answers nonsense.
  on('fs.stat', (_$, e) => {
    if (gone.has(e.path)) throw Object.assign(new Error(`ENOENT: ${e.path}`), { code: 'ENOENT' })
    if (disk.garbled?.includes(e.path) === true) return { value: { kind: 'file', size: 1, mtimeMs: 0, isLink: false, realPath: 42 as never } }
    const link = Object.keys(links).find(l => e.path === l || e.path.startsWith(`${l}/`))
    const realPath = link === undefined ? e.path : `${links[link]}${e.path.slice(link.length)}`
    return { value: { kind: 'file', size: 1, mtimeMs: 0, isLink: link !== undefined, ...(e.resolve ? { realPath } : {}) } }
  })
  on('turn.complete', (_$, e) => ({ text: e.answer }))
  /** Commands registered; `refuseRegister` refuses one as the engine does while no session is bound. */
  const registered: string[] = []
  on('command.register', async (_$, e) => {
    if ((await answers.refuseRegister?.()) === true) throw new Error('$.command.register is not available in this mode: no session is bound in this process')
    registered.push(e.name)
    return { value: { command: e.name } }
  })
  /** The tools the mod registered for the model. */
  const tools: string[] = []
  on('tool.register', (_$, e) => {
    tools.push(e.name)
    return { value: { tool: `mcp__knossos__${e.name}` } }
  })
  on('ui.toast', (_$, e) => {
    toasts.push(e.text)
    return { value: undefined }
  })
  on('ui.log', (_$, e) => {
    logs.push({ text: e.text, to: e.to })
    return { value: undefined }
  })
  // Counted, then passed on: the redraw itself still happens.
  on('ui.invalidate', (_$, e, next) => {
    invalidations.push(e.event)
    return next(e)
  })
  on('ui.open', (_$, e) => {
    opened.push(e.id)
    panes.add(e.id)
    return { value: { isPlaced: true } }
  })
  on('ui.close', (_$, e) => {
    closed.push(e.id)
    panes.delete(e.id)
    return { value: undefined }
  })
  on('prompt.submit', (_$, e) => {
    prompts.push(e.text)
    return { text: e.text }
  })
  on('ui.copy', (_$, e) => {
    copies.push({ text: e.text, surface: e.surface })
    return { value: { isCopied: true } }
  })
  /** Where the focus ring landed, by element (or `key` for a `$.ui.focus` call). */
  const focuses: string[] = []
  // The focus ring lands where it was asked to.
  on('ui.focus', (_$, e) => {
    const element = e.element ?? (e as { key?: unknown }).key
    if (typeof element === 'string') focuses.push(element)
    return {}
  })
  on('ui.panes', () => ({
    value: [...panes].map(id => ({ id, title: 'Knossos', isShown: true, isFocused: false, isPlaced: true })),
  }))
  /**
   * The live watcher's child: each start writes its scripted lines, then
   * whatever `send` queues, sleeping on the mocked clock between, until
   * `stop` ends it or the mod leaves its loop (`ended` counts the ends).
   */
  const watcher = { starts: [] as string[][], ended: 0, queue: [] as string[], stopped: false }
  on('process.spawn', async function* (_$, e) {
    watcher.starts.push([...e.argv])
    const script = answers.watch?.shift()
    if (script === undefined) return { value: { code: 0, signal: null } }
    watcher.stopped = false
    try {
      for (const line of script) yield { stream: 'stdout' as const, text: `${JSON.stringify(line)}\n` }
      // A watcher that refused or stopped exits after saying so.
      const last = (script.at(-1) as { event?: string } | undefined)?.event
      watcher.stopped = last === 'refused' || last === 'stopped'
      // As the real one, it says it is still there every 15 s even when nothing changes.
      let quiet = 0
      while (!watcher.stopped) {
        while (watcher.queue.length > 0) yield { stream: 'stdout' as const, text: watcher.queue.shift()! }
        await clock.sleep(50)
        quiet += 50
        if (quiet >= 15_000) {
          quiet = 0
          yield { stream: 'stdout' as const, text: '{"event":"heartbeat"}\n' }
        }
      }
    } finally {
      watcher.ended++
    }
    return { value: { code: 0, signal: null } }
  })
  on('session.end', (_$, e) => ({ sessionId: e.sessionId }) as never)
  const watchSend = (event: object) => watcher.queue.push(`${JSON.stringify(event)}\n`)
  const watchStop = () => {
    watcher.stopped = true
  }
  on('process.run', async (_$, e) => {
    calls.push([...e.argv])
    // The project's repository, as `git rev-parse` and `git reflog` read it.
    if (e.argv[0] === 'git') {
      const done = (exitCode: number, stdout: string) => ({ value: { exitCode, stdout, stderr: '', isStdoutTruncated: false, isStderrTruncated: false } })
      if (answers.git === undefined) return done(128, '')
      if (e.argv.includes('rev-parse')) return done(0, `${repo.head}\n`)
      if (e.argv.includes('reflog')) return done(0, answers.reflogOff === true ? '' : repo.reflog.slice(0, 20).map(r => `${r.sha}\x1f${r.subject}\n`).join(''))
      return done(1, '')
    }
    // A signal to a process the mod started: delivered.
    if (e.argv[0] === 'kill') return { value: { exitCode: 0, stdout: '', stderr: '', isStdoutTruncated: false, isStderrTruncated: false } }
    // The editor's command: there and opening the file, or not installed.
    if (e.argv[0] === 'code') {
      if (answers.editor === 'missing') throw Object.assign(new Error('spawn code ENOENT'), { code: 'ENOENT' })
      return { value: { exitCode: 0, stdout: '', stderr: '', isStdoutTruncated: false, isStderrTruncated: false } }
    }
    // Anything else the mod runs is the wrapper, by its script; a subcommand it does not run fails the test here,
    // whatever the mod makes of the failure.
    expect(e.argv[0], `a process the test world does not stand in for: ${e.argv.join(' ')}`).toBe('sh')
    expect(String(e.argv[1]).endsWith('/hooks/scripts/knossos-run.sh'), `not the wrapper: ${e.argv.join(' ')}`).toBe(true)
    const sub = String(e.argv[2])
    const queue = wrapped.get(sub)
    expect(queue, `a subcommand the wrapper does not run: ${sub}`).toBeDefined()
    if (queue === undefined) throw new Error(`a subcommand the wrapper does not run: ${sub}`)
    const answer = (queue.length > 1 ? queue.shift() : queue[0]) ?? { stdout: '' }
    if (answer.hold !== undefined) await clock.sleep(answer.hold)
    return {
      value: { exitCode: 0, stdout: answer.stdout, stderr: '', isStdoutTruncated: false, isStderrTruncated: false },
    }
  })
  // The engine's own band: an empty row, which no hook of the mod's draws.
  on('ui.render', () => ({ type: 'Box', props: { key: 'engine' }, children: [] }))
  on('tool.call', async (_$, e) => {
    if (e.tool === 'Bash' && answers.bashMs !== undefined) await clock.sleep(answers.bashMs)
    const command = (e as { command?: unknown }).command
    if (e.tool === 'Bash' && typeof command === 'string') {
      for (const subject of answers.git?.(command) ?? []) {
        repo.head = (++repo.made).toString(16).padStart(40, '0')
        repo.reflog.unshift({ sha: repo.head, subject })
      }
    }
    return { result: {} as never, text: e.tool === 'Bash' && answers.bashOutput !== undefined && typeof command === 'string' ? answers.bashOutput(command) : `ran ${e.tool}` }
  })
  const briefRuns = () => calls.filter(c => c[2] === 'turn-brief')
  const detailRuns = () => calls.filter(c => c[2] === 'component-detail')
  const fileRuns = () => calls.filter(c => c[2] === 'file-detail')
  const scanRuns = () => calls.filter(c => c[2] === 'scan')
  const dashboardRuns = () => calls.filter(c => c[2] === 'dashboard')
  const allowRuns = () => calls.filter(c => c[2] === 'allow-root')
  const editorRuns = () => calls.filter(c => c[0] === 'code')
  const kills = () => calls.filter(c => c[0] === 'kill')
  const ledgerRuns = () => calls.filter(c => c[2] === 'session-changes')
  const headRuns = () => calls.filter(c => c[2] === 'session-head')
  const diffRuns = () => calls.filter(c => c[2] === 'session-diff')
  const couplingRuns = () => calls.filter(c => c[2] === 'boundary-couplings')
  const searchRuns = () => calls.filter(c => c[2] === 'graph-search')
  const branchRuns = () => calls.filter(c => c[2] === 'branch-diff')
  const churnRuns = () => calls.filter(c => c[2] === 'churn')
  const ringRuns = () => calls.filter(c => c[2] === 'blast-radius')
  const routeRuns = () => calls.filter(c => c[2] === 'path-between')
  const noteRuns = () => calls.filter(c => c[2] === 'annotate')
  return { appended, churnRuns, ringRuns, routeRuns, noteRuns, tools, searchRuns, branchRuns, couplingRuns, store, switchSession, headRuns, diffRuns, ledgerRuns, kills, watcher, watchSend, watchStop, registered, clock, calls, briefRuns, detailRuns, fileRuns, scanRuns, dashboardRuns, allowRuns, editorRuns, toasts, logs, opened, closed, invalidations, prompts, copies, focuses }
}

export const START = { cwd: ROOT, surface: 'terminal', isInteractive: true } as const
export const TURN = { answer: '', durationMs: 1, isAborted: false, turnId: 't', reason: 'answer' } as const

export async function edit($: Engine, path: string) {
  return $.tool.call({ tool: 'Edit', file_path: path, old_string: 'a', new_string: 'b' })
}

