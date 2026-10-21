/**
 * The mod and the wrapper agree on every call: each argv the mod builds, by
 * the same function its call site uses, is run through the real
 * hooks/scripts/knossos-run.sh and the container variant, with a stub binary
 * (a stub docker for the container) that records what it was run with. A
 * call the wrapper refuses, or one that reaches the binary changed, fails
 * here: the engine test stands in for every process and cannot see either.
 *
 * KNOSSOS_PLUGIN_ROOT names another copy of the scripts to run (one with an
 * allowlist tightened, to see this fail).
 */
import { execFile } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest'
import type { NotesAsk } from '../../types'
import { annotateArgs } from './annotate-actions'
import { searchArgs } from './finder-actions'
import { dashboardArgs, LOADERS } from './loaders'
import type { Ask } from './loaders'
import { argsOf } from './notes-actions'
import { wrapper } from './port'
import type { Port } from './port'
import { mod, reset } from './state'
import { briefArgs, ledgerArgs, watchArgs } from './watcher'

const REPO = fileURLToPath(new URL('../..', import.meta.url))
const SCRIPTS = join(process.env.KNOSSOS_PLUGIN_ROOT ?? REPO, 'hooks/scripts')
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: true, watchPollMs: 1500, notifications: true }
const REV = '0123456789abcdef0123456789abcdef01234567'

let work: string
/** The project directory, as the wrapper resolves it. */
let project: string

beforeAll(() => {
  work = mkdtempSync(join(tmpdir(), 'knossos-contract-'))
  mkdirSync(join(work, 'project', 'src'), { recursive: true })
  mkdirSync(join(work, 'bin'), { recursive: true })
  // The wrapper hands the binary the physical path: a TMPDIR behind a link differs from the spelling given.
  project = realpathSync(join(work, 'project'))
  // The local wrapper beside its library, and the container variant with its placeholders filled, each where the mod looks for it
  // and each beside the guard and the argument checks it sources.
  for (const [dir, script, sed] of [
    ['local', 'knossos-run.sh', false],
    ['container', 'knossos-run-container.sh', true],
  ] as const) {
    mkdirSync(join(work, dir, 'hooks/scripts'), { recursive: true })
    const text = readFileSync(join(SCRIPTS, script), 'utf8')
    writeFileSync(join(work, dir, 'hooks/scripts/knossos-run.sh'), sed ? text.replace(/__KNOSSOS_IMAGE__/g, 'img:1').replace(/__KNOSSOS_DATA__/g, '/srv/data') : text)
    const sourced = sed ? ['guard.sh', 'run-args.sh'] : ['guard.sh', 'run-args.sh', 'lib.sh']
    for (const name of sourced) writeFileSync(join(work, dir, 'hooks/scripts', name), readFileSync(join(SCRIPTS, name), 'utf8'))
  }
  // Both stubs write their arguments, one run a line, and answer something.
  for (const name of ['knossos', 'docker']) writeFileSync(join(work, 'bin', name), `#!/bin/sh\nprintf '%s\\n' "$*" >> '${join(work, 'ran')}'\nprintf '%s\\n' '{"status":"ok"}'\n`, { mode: 0o755 })
})

afterAll(() => rmSync(work, { recursive: true, force: true }))

beforeEach(() => {
  reset(options)
  rmSync(join(work, 'ran'), { force: true })
})

/** A port whose `process.run` runs the wrapper of `variant` for real; `logs` holds what the mod logged. */
function portFor(variant: 'local' | 'container') {
  const logs: string[] = []
  const io = {
    state: new Proxy({}, { get: () => ({ read: async () => ({ status: 'ok', project_root: project }), update: async () => undefined }) }),
    clock: { now: async () => 0, after: () => ({ cancel: () => undefined }) },
    ui: { log: (text: string) => void logs.push(text) },
    session: { root: async () => project, id: async () => 'sess-1' },
    plugin: { root: join(work, variant) },
    process: {
      run: (argv: string[], opts: { timeoutMs: number }) =>
        new Promise(resolve =>
          execFile(
            argv[0]!,
            argv.slice(1),
            { env: { ...process.env, KNOSSOS_BIN: join(work, 'bin', 'knossos'), KNOSSOS_ROOTS_FILE: join(work, 'roots.json'), PATH: `${join(work, 'bin')}:${process.env.PATH ?? ''}` }, timeout: opts.timeoutMs },
            (err, stdout, stderr) => resolve({ exitCode: err === null ? 0 : 1, stdout, stderr }),
          ),
        ),
    },
  } as unknown as Port
  return { io, logs }
}

/** What the stub binary was run with, one line a run. */
const ran = (): string[] => (existsSync(join(work, 'ran')) ? readFileSync(join(work, 'ran'), 'utf8').split('\n').filter(l => l !== '') : [])

const asked = (sub: string, args: string[], root?: string): Ask => ({ sub, args, timeoutMs: 20_000, ...(root === undefined ? {} : { root }) })
const note = (q: Partial<NotesAsk> & Pick<NotesAsk, 'action'>): Ask => {
  const { sub, args } = argsOf({ component: 'App\\Greeter', author: 'model', kind: 'note', ...q })
  return asked(sub, args)
}

/**
 * Every call the mod makes, built as its call site builds it, and what the
 * binary must receive for it (`@` is the project directory). `watch` is run
 * here as a plain run: the wrapper execs the binary either way.
 */
const CALLS: { name: string; ask: (io: Port) => Promise<Ask> | Ask; binary: string }[] = [
  { name: 'dashboard', ask: () => asked('dashboard', dashboardArgs()), binary: `dashboard @ --fan-in-threshold=20 ${dashboardArgs()[1]} --json` },
  { name: 'turn-brief', ask: () => asked('turn-brief', briefArgs(['src/A b.php'], 's1', true, true)), binary: 'turn-brief @ --files=src/A b.php --since=s1 --reuse-scan --json' },
  { name: 'turn-brief without policies', ask: () => asked('turn-brief', briefArgs([], null, false, false)), binary: 'turn-brief @ --no-policies --json' },
  { name: 'scan', ask: () => asked('scan', []), binary: 'rescan @ --json' },
  { name: 'allow-root', ask: () => asked('allow-root', []), binary: 'allow-root @ --execute --json' },
  { name: 'session-head', ask: () => asked('session-head', []), binary: 'session-head @ --json' },
  { name: 'session-changes since a snapshot', ask: () => asked('session-changes', ledgerArgs('scan_ab12')), binary: 'session-changes @ --since=scan_ab12 --json' },
  { name: 'session-changes of the working tree', ask: () => asked('session-changes', ledgerArgs(null)), binary: 'session-changes @ --working-tree --json' },
  { name: 'watch', ask: () => asked('watch', watchArgs(1500)), binary: 'watch @ --shared --poll-ms=1500' },
  { name: 'graph-search', ask: () => asked('graph-search', searchArgs('dash svc')), binary: 'graph-search @ --query=dash svc --json' },
  { name: 'annotate preview', ask: () => asked('annotate', annotateArgs('App\\Greeter', 'a note', false)), binary: 'annotate @ --component=App\\Greeter --value=a note --json' },
  { name: 'annotate write', ask: () => asked('annotate', annotateArgs('App\\Greeter', 'a note', true)), binary: 'annotate @ --component=App\\Greeter --value=a note --execute --json' },
  { name: 'note remove', ask: () => note({ action: 'remove' }), binary: 'annotate @ --component=App\\Greeter --remove --author=model --execute --json' },
  { name: 'note remove of an intended boundary', ask: () => note({ action: 'remove', author: 'user', kind: 'intended_boundary' }), binary: 'annotate @ --component=App\\Greeter --remove --kind=intended_boundary --execute --json' },
  { name: 'note check with a reason', ask: () => note({ action: 'check', value: 'still?' }), binary: 'note-check @ --component=App\\Greeter --value=still? --execute --json' },
  { name: 'note check without a reason', ask: () => note({ action: 'check', value: '' }), binary: 'note-check @ --component=App\\Greeter --execute --json' },
  { name: 'note uncheck', ask: () => note({ action: 'uncheck' }), binary: 'note-check @ --component=App\\Greeter --remove --execute --json' },
  { name: 'note confirm', ask: () => note({ action: 'confirm' }), binary: 'note-confirm @ --component=App\\Greeter --execute --json' },
  { name: 'note move', ask: () => note({ action: 'move', to: 'App\\Hello' }), binary: 'note-move @ --component=App\\Greeter --to=App\\Hello --author=model --execute --json' },
  { name: 'boundary-couplings', ask: io => LOADERS.couplings.ask(io, { from: 'namespace:App\\Core', to: 'module:hooks (+ts)', snapshot: 's1', root: project } as never), binary: 'boundary-couplings @ --from=namespace:App\\Core --to=module:hooks (+ts) --json' },
  { name: 'session-diff', ask: io => LOADERS.diff.ask(io, { rev: REV, name: 'src/a b.php', root: project } as never), binary: `session-diff @ --rev=${REV} --file=src/a b.php --json` },
  { name: 'component-detail', ask: io => LOADERS.detail.ask(io, { shown: { name: 'App\\Greeter', label: 'Greeter' }, snapshot: 's1' } as never), binary: 'component-detail @ App\\Greeter --json' },
  { name: 'file-detail', ask: io => LOADERS.detail.ask(io, { shown: { name: 'src/A.php', label: 'A.php', file: true }, snapshot: 's1' } as never), binary: 'file-detail @/src/A.php --json' },
  { name: 'peek of a file', ask: io => LOADERS.peek.ask(io, { shown: { name: 'src/A.php', label: 'A.php', file: true }, snapshot: 's1' } as never), binary: 'file-detail @/src/A.php --json' },
  { name: 'branch-diff', ask: io => LOADERS.branch.ask(io, { root: project } as never), binary: 'branch-diff @ --json' },
  { name: 'churn', ask: io => LOADERS.churn.ask(io, { root: project } as never), binary: 'churn @ --json' },
  { name: 'notes, all', ask: io => LOADERS.notes.ask(io, { filter: 'all', text: '', root: project } as never), binary: 'notes @ --json' },
  { name: 'notes, narrowed', ask: io => LOADERS.notes.ask(io, { filter: 'stale', text: 'cache', root: project } as never), binary: 'notes @ --filter=stale --text=cache --json' },
  { name: 'blast-radius', ask: io => LOADERS.rings.ask(io, { name: 'App\\Greeter::greet', root: project } as never), binary: 'blast-radius @ --component=App\\Greeter::greet --json' },
  { name: 'path-between', ask: io => LOADERS.route.ask(io, { from: 'App\\A', to: 'App\\B', root: project } as never), binary: 'path-between @ --from=App\\A --to=App\\B --json' },
]

describe.each(['local', 'container'] as const)('every call the mod makes, through the %s wrapper', variant => {
  it.each(CALLS)('$name reaches the binary as built, and is not refused', async call => {
    const { io, logs } = portFor(variant)
    const ask = await call.ask(io)
    await wrapper(io, ask.sub, ask.args, ask.timeoutMs, ask.root)
    expect(logs).toEqual([])
    const runs = ran()
    expect(runs).toHaveLength(1)
    const binary = call.binary.replace(/@/g, project)
    // The container stub is docker: its argv ends with the image, then what the binary would receive.
    if (variant === 'local') expect(runs[0]).toBe(binary)
    else expect(runs[0]!.endsWith(` img:1 ${binary}`), runs[0]).toBe(true)
  })
})

describe('the wrappers and the engine test agree on what runs', () => {
  /** The subcommands the wrappers accept, and each one's default bound, from the `case "$SUBCOMMAND"` list both source. */
  const limitsOf = (): Map<string, string> => {
    const text = readFileSync(join(SCRIPTS, 'run-args.sh'), 'utf8')
    const start = text.indexOf('case "$SUBCOMMAND" in\n')
    const block = text.slice(start, text.indexOf('\nesac', start))
    const limits = new Map<string, string>()
    for (const line of block.split('\n')) {
      const m = /^\s+([a-z|-]+)\)\s*LIMIT=(?:\$\{KNOSSOS_RUN_TIMEOUT:-(\d+)\}|(0))/.exec(line)
      if (m !== null) for (const sub of m[1]!.split('|')) limits.set(sub, m[2] ?? m[3]!)
    }
    return limits
  }

  it('each wrapper accepts every subcommand the engine test answers', () => {
    const test = readFileSync(join(REPO, 'hooks/register.test.ts'), 'utf8')
    const answered = [...test.matchAll(/^\s+\['([a-z-]+)', queues\.[a-z]+\],?$/gm)].map(m => m[1]!)
    expect(answered.length).toBeGreaterThan(15)
    const accepted = limitsOf()
    expect(answered.filter(sub => !accepted.has(sub))).toEqual([])
    expect(accepted.size).toBeGreaterThan(20)
  })

  it('both wrappers source the one copy of the argument checks', () => {
    for (const script of ['knossos-run.sh', 'knossos-run-container.sh']) {
      const text = readFileSync(join(SCRIPTS, script), 'utf8')
      expect(text, script).toContain('. "$KNOSSOS_HOOK_DIR/run-args.sh"')
      expect(text, script).not.toContain('case "$SUBCOMMAND" in\n    turn-brief)')
    }
  })

  it('the watcher is started with the poll interval from the options', () => {
    expect(watchArgs(mod.watch.pollMs)).toEqual(['--poll-ms=1500'])
  })
})

/**
 * The local and the container wrapper let through exactly the same calls:
 * each subcommand (and one neither knows) is run through both with every
 * argument list below, valid and not, and what each did must agree. Either
 * the same refusal, the same silence, or the binary run with the same
 * arguments (for the container, what follows the image on docker's argv).
 */
describe('the local and the container wrapper accept and refuse the same arguments', () => {
  const ARGUMENTS: string[][] = [
    [],
    ['x'],
    ['-x'],
    ['--db=/x'],
    ['App\\Greeter'],
    ['src/A.php'],
    ['/etc/passwd'],
    ['../x'],
    ['src/../../x'],
    ['src/A.php', 'x'],
    ['--fan-in-threshold=20'],
    ['--query=dash svc'],
    ['--query='],
    ['--query=a\tb'],
    ['--component=App\\A'],
    ['--component='],
    ['--component=App\\A', 'x'],
    ['--component=App\\A', '--value=a note'],
    ['--component=App\\A', '--value=a note', '--execute'],
    ['--component=App\\A', '--value='],
    ['--component=App\\A', '--value=a\nb', '--execute'],
    ['--component=App\\A', '--execute'],
    ['--component=App\\A', '--execute', '--execute'],
    ['--component=App\\A', '--remove'],
    ['--component=App\\A', '--remove', '--execute'],
    ['--component=App\\A', '--remove', '--author=model', '--kind=note', '--execute'],
    ['--component=App\\A', '--remove', '--kind=note', '--author=model'],
    ['--component=App\\A', '--remove', '--kind=check'],
    ['--component=App\\A', '--remove', '--author=other'],
    ['--component=App\\A', '--to=App\\B'],
    ['--component=App\\A', '--to=App\\B', '--author=user', '--kind=intentional', '--execute'],
    ['--component=App\\A', '--to='],
    ['--from=a', '--to=b'],
    ['--to=b', '--from=a'],
    ['--from=a', '--to=b', '--db=/x'],
    ['--filter=stale'],
    ['--filter=stale', '--text=cache'],
    ['--text=cache', '--filter=stale'],
    ['--filter=other'],
    ['--text=cache'],
    ['--poll-ms=1500'],
    ['--poll-ms=1e3'],
    ['--poll-ms='],
    ['--since=scan_ab12'],
    ['--since=a b'],
    ['--working-tree'],
    [`--rev=${REV}`, '--file=src/a b.php'],
    [`--rev=${REV}`, '--file=../x'],
    [`--rev=${REV}`, '--file=/etc/passwd'],
    ['--rev=0123', '--file=src/a.php'],
    [`--rev=${REV.toUpperCase()}`, '--file=src/a.php'],
    ['--execute'],
    [`--component=${'a'.repeat(521)}`],
  ]

  /** Stubs for knossos and docker that write each run to the file the run names, so runs in parallel keep apart. */
  const STUBS = (): string => join(work, 'cmp-bin')
  beforeAll(() => {
    mkdirSync(STUBS(), { recursive: true })
    for (const name of ['knossos', 'docker']) writeFileSync(join(STUBS(), name), `#!/bin/sh\n{ printf '%s\\037' "$@"; printf '\\036'; } >> "$KNOSSOS_STUB_LOG"\nprintf '%s\\n' '{"status":"ok"}'\n`, { mode: 0o755 })
  })

  let runs = 0
  /** One run of a wrapper: its stdout, and the argv the binary got (for docker, what follows the image). */
  const outcome = (variant: 'local' | 'container', argv: string[], env: Record<string, string>): Promise<string> =>
    new Promise(resolve => {
      const log = join(work, `ran-${variant}-${++runs}`)
      execFile(
        'sh',
        [join(work, variant, 'hooks/scripts/knossos-run.sh'), ...argv],
        { env: { ...process.env, ...env, KNOSSOS_STUB_LOG: log, KNOSSOS_BIN: join(STUBS(), 'knossos'), KNOSSOS_ROOTS_FILE: join(work, 'roots.json'), PATH: `${STUBS()}:${process.env.PATH ?? ''}` }, timeout: 20_000 },
        (_err, stdout) => {
          // One run a record (\x1e), one argument a unit (\x1f): an argument may hold a line break.
          const records = existsSync(log) ? readFileSync(log, 'utf8').split('\x1e').filter(r => r !== '') : []
          rmSync(log, { force: true })
          const binary = records.map(r => r.split('\x1f').slice(0, -1)).map(argv => (variant === 'local' ? argv : argv.slice(argv.indexOf('img:1') + 1)))
          resolve(JSON.stringify({ stdout, binary }))
        },
      )
    })

  /** Every subcommand both accept, and one neither knows. */
  const SUBCOMMANDS = [...new Set([...readFileSync(join(SCRIPTS, 'run-args.sh'), 'utf8').matchAll(/^\s+([a-z|-]+)\)\s*LIMIT=/gm)].flatMap(m => m[1]!.split('|')))]

  it('covers every subcommand', () => {
    expect(SUBCOMMANDS.length).toBeGreaterThan(20)
  })

  it.each([...SUBCOMMANDS, 'bogus'])('%s', async sub => {
    const cases = ARGUMENTS.map(args => [sub, project, ...args])
    const results = await Promise.all(cases.flatMap(argv => [outcome('local', argv, {}), outcome('container', argv, {})]))
    for (let i = 0; i < cases.length; i++) expect(results[2 * i + 1], cases[i]!.join(' ')).toBe(results[2 * i])
    // Not vacuous: something was let through, and something refused but where a subcommand checks no options.
    expect(results.some(r => r.includes('"binary":[['))).toBe(sub !== 'bogus')
    expect(results.some(r => r.includes('refused'))).toBe(!['dashboard', 'turn-brief'].includes(sub))
  }, 60_000)

  it.each(['0', '007', 'abc', '86401', '999999999999'])('KNOSSOS_RUN_TIMEOUT=%s', async limit => {
    for (const sub of ['dashboard', 'watch']) {
      const argv = [sub, project]
      const [local, container] = await Promise.all([outcome('local', argv, { KNOSSOS_RUN_TIMEOUT: limit }), outcome('container', argv, { KNOSSOS_RUN_TIMEOUT: limit })])
      expect(container, `${sub} ${limit}`).toBe(local)
    }
  })
})
