/**
 * How a function is called, broken for a column too narrow for it on one
 * line. The line comes from knossos in one form for every language
 * (`src/Query/SignatureLine.php`): `name(a: T, b?: T, ...rest: T, …): R`,
 * the types as the source spelled them, `…` where the scan stopped listing,
 * and `: R` only where a return type is known.
 *
 * A line that fits stays one line. One that does not opens its list on the
 * first line, puts each parameter on its own line two cells in with a comma
 * after it, and closes the list on the last line with what it returns:
 *
 *     importNotes(
 *       positionals: array,
 *       context: Knossos\Cli\CliCommandContext,
 *     ): int
 *
 * The list is split at its own commas only, never at one inside a type
 * (`array<string, int>`, `Closure(int, int): bool`, `{a: int, b: int}`,
 * `'a,b'`). A line that does not read as `name(params)` or
 * `name(params): type` is wrapped as words, and a word too long for the
 * column is broken rather than cut, so the return type always shows.
 */
import { wrapValue } from './spacing'
import { cells } from './text'

/** A call's parts: what it is called, its parameters as written, and what follows the list (`: int`, or nothing). */
export type CallParts = { name: string; params: string[]; rest: string }

const OPEN: Readonly<Record<string, string>> = { ')': '(', ']': '[', '}': '{', '>': '<' }

/** The cells a parameter stands in from the call's name. */
const PARAM_INDENT = 2

/** `line` as a call's parts, or null where it does not read as one. */
export function callParts(line: string): CallParts | null {
  const open = line.indexOf('(')
  if (open < 1) return null
  const stack: string[] = []
  const params: string[] = []
  let start = open + 1
  let quote: string | null = null
  for (let i = open; i < line.length; i++) {
    const ch = line[i]!
    if (quote !== null) {
      if (ch === quote && line[i - 1] !== '\\') quote = null
      continue
    }
    if (ch === "'" || ch === '"' || ch === '`') quote = ch
    else if ('([{<'.includes(ch)) stack.push(ch)
    else if (ch in OPEN) {
      // An arrow's `>` (`=>`, `->`) and a lone `>` close nothing.
      if (ch === '>' && (stack.at(-1) !== '<' || line[i - 1] === '=' || line[i - 1] === '-')) continue
      if (stack.at(-1) !== OPEN[ch]) return null
      stack.pop()
      if (stack.length === 0) {
        params.push(line.slice(start, i))
        const rest = line.slice(i + 1)
        const listed = params.map(p => p.trim())
        if (listed.some(p => p === '') || !(rest === '' || /^:\s*\S/.test(rest))) return null
        return { name: line.slice(0, open), params: listed, rest }
      }
    } else if (ch === ',' && stack.length === 1) {
      params.push(line.slice(start, i))
      start = i + 1
    }
  }
  return null
}

/** `line`, a call's signature, as lines no wider than `width`: one when it fits, else one parameter a line (see the module docblock). */
export function signatureLines(line: string, width: number): string[] {
  if (cells(line) <= width) return [line]
  const parts = callParts(line)
  // Too narrow to indent a parameter and keep a word beside it: words, broken where they must be.
  if (parts === null || width < PARAM_INDENT * 2 + 4) return wrapValue(line, width)
  const indent = ' '.repeat(PARAM_INDENT)
  const param = (p: string): string[] => {
    const text = p === '…' ? p : `${p},`
    const [first = '', ...more] = wrapValue(text, width - PARAM_INDENT * 2)
    return [`${indent}${first}`, ...more.map(piece => `${indent}${indent}${piece}`)]
  }
  return [...wrapValue(`${parts.name}(`, width), ...parts.params.flatMap(param), ...wrapValue(`)${parts.rest}`, width)]
}
