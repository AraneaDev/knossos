/**
 * The shell commands that run tests: the command that runs a set of test
 * files by the runner their paths call for, and whether a command a turn ran
 * already ran one. Both read the same table of runners, so they never
 * disagree. Pure.
 */
import type { JsRunner } from '../../types'
import { baseName } from './paths'

/** Single-quoted for a POSIX shell when it holds anything a shell would read. */
const quote = (word: string): string => (/^[\w./@%+=:,-]+$/.test(word) ? word : `'${word.replace(/'/g, `'\\''`)}'`)

/**
 * The test runners a path can call for, each with how its tests are told
 * apart and what a shell command that invokes it looks like. The command
 * that runs the tests and the check whether a command already ran one read
 * the same table, so they never disagree.
 */
const RUNNERS = {
  phpunit: { test: /Test\.php$/, invoked: /\b(phpunit|paratest|pest)\b|\bcomposer\s+(run(-script)?\s+)?test\b/ },
  // Vitest or Jest, as the project's package.json says (`JsRunners`).
  js: { test: /\.(spec|test)\.[cm]?[jt]sx?$/, invoked: /\b(vitest|jest)\b|\b(npm|yarn|pnpm)\s+(run\s+)?test(:[\w-]+)?\b/ },
  pytest: { test: /(^|\/)test_[^/]*\.py$|_test\.py$/, invoked: /\bpytest\b/ },
  go: { test: /_test\.go$/, invoked: /\bgo\s+test\b/ },
  cargo: { test: /\.rs$/, invoked: /\bcargo\s+(test|nextest)\b/ },
} as const

/** The runner a test path calls for, or null for a path no runner runs (a helper, a fixture). */
export function runnerOf(path: string): keyof typeof RUNNERS | null {
  return (Object.keys(RUNNERS) as (keyof typeof RUNNERS)[]).find(r => RUNNERS[r].test.test(path)) ?? null
}

/** The runner of each JavaScript test as the turn brief read it from package.json; null or absent when unknown. */
export type JsRunners = Readonly<Record<string, JsRunner | null>>

/** Whether a command names `test` itself: a PHPUnit or pytest test, or a script whose runner is known. */
export function namedByCommand(test: string, js: JsRunners = {}): boolean {
  const runner = runnerOf(test)
  return runner === 'phpunit' || runner === 'pytest' || (runner === 'js' && (js[test] ?? null) !== null)
}

/**
 * A command that runs `tests`, by the runner their paths call for: PHPUnit
 * for `*Test.php` (the file, or a `--filter` over the classes for several),
 * Vitest or Jest for `*.spec.*` and `*.test.*` scripts as the project's
 * package.json says (`js`), pytest for `test_*.py` and `*_test.py`, `go test`
 * per package for `*_test.go`, `cargo test` for Rust. A script whose runner is
 * unknown is left out rather than handed to a runner that may not be there.
 * Several runners are joined with `&&`; null when no path names a runner.
 * The paths are relative to the project root, so a command run from
 * elsewhere (`cd`, see `cdFor`) changes to it first.
 */
export function testCommand(tests: string[], js: JsRunners = {}, cd: string | null = null): string | null {
  const of = (runner: keyof typeof RUNNERS) => tests.filter(t => runnerOf(t) === runner)
  const php = of('phpunit')
  const vitest = of('js').filter(t => js[t] === 'vitest')
  const jest = of('js').filter(t => js[t] === 'jest')
  const pytest = of('pytest')
  const packageOf = (t: string) => (t.includes('/') ? `./${t.slice(0, t.lastIndexOf('/'))}` : '.')
  const go = [...new Set(of('go').map(packageOf))]
  const commands: string[] = []
  if (php.length === 1) commands.push(`vendor/bin/phpunit ${quote(php[0]!)}`)
  if (php.length > 1) commands.push(`vendor/bin/phpunit --filter ${quote(`(${[...new Set(php.map(t => baseName(t).slice(0, -4)))].join('|')})`)}`)
  if (vitest.length > 0) commands.push(`npx vitest run ${vitest.map(quote).join(' ')}`)
  if (jest.length > 0) commands.push(`npx jest ${jest.map(quote).join(' ')}`)
  if (pytest.length > 0) commands.push(`python -m pytest ${pytest.map(quote).join(' ')}`)
  if (go.length > 0) commands.push(`go test ${go.map(quote).join(' ')}`)
  if (of('cargo').length > 0) commands.push('cargo test')
  if (commands.length === 0) return null
  return cd === null ? commands.join(' && ') : `cd ${quote(cd)} && ${commands.join(' && ')}`
}

/**
 * Where a test command must change to before it runs: the project root, when
 * the session (where Claude's shell starts) sits elsewhere, such as below an
 * ancestor project; null when they are one, or either is unknown.
 */
export function cdFor(projectRoot: string | null, sessionRoot: string | null): string | null {
  const trim = (p: string) => (p.length > 1 ? p.replace(/\/+$/, '') : p)
  return projectRoot === null || sessionRoot === null || trim(projectRoot) === trim(sessionRoot) ? null : projectRoot
}

/** Flags by which a runner is told to run some tests only. */
const NARROWING = /^(--filter|--testsuite|--group|--exclude-group|-k|-t|--testNamePattern|--run|--package|-p)(=|$)/

/**
 * Whether one of `commands` (shell commands, as a turn ran them) ran the test
 * at `path`: one that invokes its runner and names the test (its file stem,
 * as a filter does), names a directory or package the test sits in, or
 * names no test at all (the runner's whole suite).
 */
export function testsRan(path: string, commands: string[]): boolean {
  const runner = runnerOf(path)
  if (runner === null) return false
  const name = baseName(path)
  const stem = name.includes('.') ? name.slice(0, name.lastIndexOf('.')) : name
  return commands.some(command => {
    const invoked = RUNNERS[runner].invoked.exec(command)
    if (invoked === null) return false
    if (command.includes(stem)) return true
    const words = command.slice(invoked.index + invoked[0].length).split(/\s+/).filter(w => w !== '' && w !== 'run')
    const places = words.filter(w => !w.startsWith('-') && (w.includes('/') || w === '.' || runnerOf(w) !== null))
    if (places.some(w => {
      const dir = w.replace(/^\.\//, '').replace(/\.\.\.$/, '').replace(/\/+$/, '')
      return dir === '' || dir === '.' || path.startsWith(`${dir}/`) || path === dir
    })) return true
    return places.length === 0 && !words.some(w => NARROWING.test(w))
  })
}
