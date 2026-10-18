/**
 * `knossos web`'s arguments: one directory at most, and the flags it knows,
 * each value given after `=` or as the next argument. Anything else is
 * refused with the usage line rather than read as a directory or passed by.
 */

/** What `knossos web` was asked to do. */
export type WebArgs = { dir: string | null; port: number | null; open: boolean; editor: string | null }

export const USAGE = 'usage: knossos web [path] [--port=N] [--no-open] [--editor=<command>]'

/** The flags that take a value. */
const VALUED = ['--port', '--editor'] as const

/** `argv` (the arguments after the script) as {@link WebArgs}, or why they are not. */
export function argsOf(argv: readonly string[]): WebArgs | { error: string } {
  const out: WebArgs = { dir: null, port: null, open: true, editor: null }
  const values: Partial<Record<(typeof VALUED)[number], string>> = {}
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i]!
    if (arg === '--no-open') {
      out.open = false
      continue
    }
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
  if (values['--port'] !== undefined) {
    const port = Number(values['--port'])
    if (!/^\d+$/.test(values['--port']) || port > 65_535) return { error: `--port must be a port number, not ${values['--port']}` }
    out.port = port
  }
  out.editor = values['--editor'] ?? null
  return out
}
