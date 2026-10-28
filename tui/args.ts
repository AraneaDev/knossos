/**
 * `knossos`'s arguments for the terminal pane: one directory at most, and the
 * flags it knows, each value given after `=` or as the next argument. Anything
 * else is refused with the usage line rather than read as a directory.
 */

/** What the terminal pane was asked to do. */
export type TuiArgs = { dir: string | null; editor: string | null; theme: 'dark' | 'light' | null }

export const USAGE = 'usage: knossos [path] [--editor=<command>] [--theme=dark|light]'

/** The flags that take a value. */
const VALUED = ['--editor', '--theme'] as const

/** `argv` (the arguments after the script) as {@link TuiArgs}, or why they are not. */
export function argsOf(argv: readonly string[]): TuiArgs | { error: string } {
  const out: TuiArgs = { dir: null, editor: null, theme: null }
  const values: Partial<Record<(typeof VALUED)[number], string>> = {}
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i]!
    const cut = arg.indexOf('=')
    const name = (cut < 0 ? arg : arg.slice(0, cut)) as (typeof VALUED)[number]
    if (VALUED.includes(name)) {
      const value = cut < 0 ? argv[++i] : arg.slice(cut + 1)
      if (value === undefined || value === '') return { error: `${name} needs a value` }
      values[name] = value
      continue
    }
    if (arg.startsWith('-')) return { error: `unknown option ${arg}` }
    if (out.dir !== null) return { error: `one directory at most, not ${out.dir} and ${arg}` }
    out.dir = arg
  }
  const theme = values['--theme']
  if (theme !== undefined) {
    if (theme !== 'dark' && theme !== 'light') return { error: `--theme must be dark or light, not ${theme}` }
    out.theme = theme
  }
  out.editor = values['--editor'] ?? null
  return out
}
