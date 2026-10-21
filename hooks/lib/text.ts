/**
 * Text as the pane prints it: measured in display columns, cut, padded and
 * wrapped to a width, counts written short, paths cut in their directory,
 * and the short names components go by. Pure; nothing here reads state.
 *
 * Widths are display columns, measured per grapheme: East Asian wide and
 * emoji graphemes take two, a grapheme of only combining marks none, the
 * rest one. Text is cut only between graphemes, so a cut never splits an
 * emoji sequence or strips a mark from its base.
 */
import { opensFile } from './editor'
import type { Loc, Segment } from './types'

const graphemes = new Intl.Segmenter(undefined, { granularity: 'grapheme' })
const WIDE =
  /[ᄀ-ᅟ⺀-〾ぁ-㏿㐀-䶿一-鿿ꀀ-꓏가-힣豈-﫿︰-﹏＀-｠￠-￦]|[\u{1f300}-\u{1faff}\u{20000}-\u{3fffd}]|\p{Extended_Pictographic}/u
const MARKS_ONLY = /^\p{M}+$/u

/** Display columns a grapheme takes in a terminal. */
const graphemeCells = (g: string): number => (MARKS_ONLY.test(g) ? 0 : WIDE.test(g) ? 2 : 1)

/** The graphemes of a string, in order. */
const split = (text: string): string[] => Array.from(graphemes.segment(text), s => s.segment)

/** Text of printable ASCII only: one cell a code unit, no grapheme to split. */
const ASCII = /^[\x20-\x7e]*$/

export const cells = (text: string): number => (ASCII.test(text) ? text.length : cellsOf(text))
const cellsOf = (text: string): number => split(text).reduce((n, g) => n + graphemeCells(g), 0)

/** The leading graphemes that fit in `room` cells, joined. */
function head(chars: string[], room: number): string {
  let used = 0
  let out = ''
  for (const g of chars) {
    used += graphemeCells(g)
    if (used > room) break
    out += g
  }
  return out
}

/** The trailing graphemes that fit in `room` cells, joined. */
function tail(chars: string[], room: number): string {
  let used = 0
  let out = ''
  for (const g of [...chars].reverse()) {
    used += graphemeCells(g)
    if (used > room) break
    out = g + out
  }
  return out
}

/** `text` cut to `width` cells, the last one an ellipsis when anything was cut. */
export function fit(text: string, width: number): string {
  if (width <= 0) return ''
  return cells(text) <= width ? text : `${head(split(text), width - 1)}…`
}

/** `text` cut to `width` cells from the front, the first one an ellipsis: a path keeps its file name. */
export function fitStart(text: string, width: number): string {
  if (width <= 0) return ''
  return cells(text) <= width ? text : `…${tail(split(text), width - 1)}`
}

/**
 * A path fitted to `width` cells as its directory and its file name, for
 * drawing in two tones. A path that does not fit is cut in the middle of
 * its directory, never in the file name: whole folders first (the first
 * one, `…`, then as many of the last as fit: `src/…/Query/`; then `…` and
 * the folder the file is in; then the first folder and `…`), then the
 * directory's own middle; a file name wider than the whole width alone is
 * cut at its end.
 */
export function pathParts(path: string, width: number): { dir: string; base: string } {
  const cut = path.lastIndexOf('/') + 1
  const dir = path.slice(0, cut)
  const base = path.slice(cut)
  if (width <= 0) return { dir: '', base: '' }
  if (cells(path) <= width) return { dir, base }
  const room = width - cells(base)
  if (room < 2) return { dir: '', base: fit(base, width) }
  return { dir: middleCut(dir, room), base }
}

/** A path fitted to `width` cells as one text, cut as {@link pathParts} cuts it: where one tone is all there is. */
export const pathText = (path: string, width: number): string => {
  const { dir, base } = pathParts(path, width)
  return dir + base
}

/** A path as two segments fitted to `width`: its directory dim, its file name in `style` (opening the file's detail when `loc` is given). */
export function pathSegments(path: string, width: number, loc: Loc | null = null, style: Omit<Segment, 'text' | 'press'> = {}): Segment[] {
  const { dir, base } = pathParts(path, width)
  return [{ text: dir, dim: true }, opensFile(base, loc, style)].filter(s => s.text !== '')
}

/** A directory (ending in `/`) in at most `room` cells, cut in its middle: by whole folders while they fit, else by characters. */
function middleCut(dir: string, room: number): string {
  const folders = dir.split('/').filter(f => f !== '')
  // The first folder, `…`, then the last `kept` folders, as many as fit.
  for (let kept = folders.length - 2; kept >= 1; kept--) {
    const text = `${folders[0]}/…/${folders.slice(folders.length - kept).map(f => `${f}/`).join('')}`
    if (cells(text) <= room) return text
  }
  // The folder the file is in says more than the first one.
  const last = folders.at(-1)
  if (last !== undefined && folders.length > 1 && cells(`…/${last}/`) <= room) return `…/${last}/`
  if (folders.length > 1 && cells(`${folders[0]}/…/`) <= room) return `${folders[0]}/…/`
  if (room < 4) return '…/'
  const chars = split(dir)
  const left = Math.ceil((room - 1) / 2)
  const right = room - 1 - left
  return `${head(chars, left)}…${right > 0 ? tail(chars, right) : ''}`
}

export const spaces = (n: number): string => ' '.repeat(Math.max(0, n))
export const padEnd = (text: string, width: number): string => text + spaces(width - cells(text))
export const padStart = (text: string, width: number): string => spaces(width - cells(text)) + text
export const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`
/** A count with thousands separated: 7,878. */
export const grouped = (n: number): string => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',')

/**
 * A count in at most five cells, for a narrow column: as it is below a
 * thousand, then in thousands or millions with one decimal while that is
 * under a hundred (`31.7k`, `1.2M`), whole past it (`317k`). A trailing `.0`
 * is dropped. Tiles and details keep the full {@link grouped} figure.
 */
export function compact(n: number): string {
  const sign = n < 0 ? '-' : ''
  const a = Math.abs(n)
  if (a < 1_000) return `${sign}${a}`
  const [scaled, unit] = a < 999_500 ? [a / 1_000, 'k'] : a < 999_500_000 ? [a / 1_000_000, 'M'] : [a / 1_000_000_000, 'G']
  const text = scaled < 99.95 ? (Math.round(scaled * 10) / 10).toFixed(1).replace(/\.0$/, '') : String(Math.round(scaled))
  return `${sign}${text}${unit}`
}

/** Words wrapped onto as many rows as they need, none wider than `columns`. */
export function wrapWords(text: string, columns: number): string[] {
  const lines: string[] = []
  let line = ''
  for (const word of text.split(/\s+/).filter(w => w !== '')) {
    const next = line === '' ? word : `${line} ${word}`
    if (cells(next) <= columns) {
      line = next
      continue
    }
    if (line !== '') lines.push(line)
    line = fit(word, columns)
  }
  if (line !== '') lines.push(line)
  return lines
}

/**
 * A component's name as the pane prints it: a method as `Class::method`
 * from its canonical name, anything else by its own short name. Display
 * only; lookups go by the canonical name.
 */
export function displayName(item: { name: string; canonical_name: string; kind: string }): string {
  if (item.kind !== 'method') return item.name
  const cut = item.canonical_name.lastIndexOf('::')
  if (cut < 0) return item.name
  const owner = item.canonical_name.slice(0, cut)
  // An anonymous class is named after where it is declared: keep the marker, not the path.
  const at = owner.indexOf('@')
  const scoped = at > 0 ? owner.slice(0, at) : owner
  return `${lastSegment(scoped)}::${item.canonical_name.slice(cut + 2)}`
}

/**
 * The last segment of a qualified name, whatever the language separates it
 * with: a namespace (`\\`), a path (`/`), a file's export (`#`), a module
 * (`.`) or a Rust path (`::`).
 */
export function lastSegment(name: string): string {
  const cut = Math.max(name.lastIndexOf('\\'), name.lastIndexOf('/'), name.lastIndexOf('#'), name.lastIndexOf('.'))
  const rust = name.lastIndexOf('::')
  return rust > cut ? name.slice(rust + 2) : name.slice(cut + 1)
}

/**
 * A short name from a canonical one alone, as a policy violation names its
 * two ends: `Class::member` for a member, the last segment otherwise.
 */
export function shortName(canonical: string): string {
  const cut = canonical.lastIndexOf('::')
  if (cut >= 0) return displayName({ name: canonical.slice(cut + 2), canonical_name: canonical, kind: 'method' })
  return lastSegment(canonical)
}
