/**
 * Every glyph the pane uses to mean something, in one place: what it means,
 * the tone it is drawn in, and the word a card's legend gives it. A glyph has
 * one meaning across every tab; the few in {@link SHARED} appear in two
 * families because there they say the same thing. Colour only ever reinforces
 * a glyph. Box drawing, bars, sparklines, arrows between two names and the
 * scroll hints are drawing, not marks, and stay where they are drawn.
 */
import { ACCENT, STATUS_COLOURS } from './palette'
import type { Segment } from './rows'

/**
 * Digits in groups of three. rows.ts has the same helper, but rows.ts reads
 * this module for its swatch and kind column, so importing it back would make
 * a cycle that can leave a family undefined while the modules load.
 */
const grouped = (n: number): string => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',')

export type MarkTone = 'ok' | 'warn' | 'alert' | 'accent' | 'dim' | 'text'
export type Mark = { glyph: string; meaning: string; tone: MarkTone; legend: string; plural?: string }

const m = (glyph: string, meaning: string, tone: MarkTone, legend: string = meaning, plural?: string): Mark => ({ glyph, meaning, tone, legend, ...(plural === undefined ? {} : { plural }) })

/** How something stands. */
export const SEVERITY = {
  ok: m('✓', 'ok', 'ok'),
  warn: m('▲', 'warning', 'warn', 'warning', 'warnings'),
  error: m('✗', 'error', 'alert', 'error', 'errors'),
  note: m('◦', 'note', 'dim', 'note', 'notes'),
} as const

/** How sure the scan is that a dead-code candidate is dead. */
export const CERTAINTY = {
  probable: m('●', 'probably dead', 'text', 'probable'),
  possible: m('○', 'possibly dead', 'dim', 'possible'),
} as const

/** What reaches a component. */
export const REACH = {
  tested: m('✓', 'ok', 'ok', 'tested'),
  testsOnly: m('◐', 'only tests reach it', 'warn', 'tests only'),
  untested: m('▲', 'warning', 'warn', 'untested'),
  unused: m('∅', 'nothing reaches it', 'dim', 'unused'),
} as const

/** How a file moved, and a scan on the session's timeline. */
export const CHANGE = {
  added: m('+', 'added', 'ok'),
  changed: m('~', 'changed', 'warn'),
  removed: m('−', 'removed', 'alert'),
  scan: m('•', 'a scan', 'accent', 'scan', 'scans'),
} as const

/** What kind of component a row names, from the dashboard's node kind. */
export const KIND: Readonly<Record<string, Mark>> = {
  class: m('C', 'class', 'dim'),
  interface: m('I', 'interface', 'dim'),
  trait: m('T', 'trait', 'dim'),
  enum: m('N', 'enum', 'dim'),
  method: m('m', 'method', 'dim'),
  function: m('f', 'function', 'dim'),
  property: m('p', 'property', 'dim'),
  module: m('M', 'module', 'dim'),
  route: m('R', 'route', 'dim'),
  endpoint: m('E', 'endpoint', 'dim'),
}

/** Where something is. */
export const PLACE = {
  swatch: m('■', 'boundary', 'text'),
  centre: m('◉', 'the centre of the graph', 'accent', 'centre'),
  hub: m('◆', 'the bucket holding the hubs', 'accent', 'hubs'),
} as const

/** How a component ranks. */
export const RANKING = {
  hotspotOnly: m('✦', 'ranked a hotspot but not a hub', 'dim', 'hotspot only'),
} as const

/** The glyph in each card's title. A card icon is never a row mark, apart from {@link SHARED}. */
export const CARD_ICON: Readonly<Record<string, string>> = {
  look: '◬', turn: '▤', top: '◎', hubs: '◎', files: '≡', map: '▦', bounds: '▦', 'bounds-list': '▥', focus: '◈',
  coupling: '⇄', cycles: '↻', 'cycle-members': '↻', 'cycle-diagram': '↻', hood: '⇄', policy: '!', diag: '!', dead: '!',
  hotspots: '!', branch: '⎇', crossing: '⇄', 'branch-cycles': '↻', grown: '◎', 'branch-dead': '!', 'branch-policy': '!',
  'branch-files': '≡', find: '⌕', budget: '!', churn: '≡', 'churn-plot': '∴', 'churn-list': '≡', rings: '◎', route: '⇢',
  'route-others': '⇢', changes: '±', tests: '✓', trend: '∿', detail: '◇', used: '←', uses: '→', deps: '←', comps: '→',
  notes: '✎', diff: '±', drift: 'Δ', session: '±', composition: '▥', concentration: '◎', health: '∿',
}

/** Glyphs in two families on purpose, because there they say the same thing: ok and tested, a warning and untested, a change, a cycle. */
export const SHARED: ReadonlyArray<string> = ['✓', '▲', '±', '↻']

const toned = (tone: MarkTone): Omit<Segment, 'text'> =>
  tone === 'dim' ? { dim: true } : tone === 'text' ? {} : tone === 'accent' ? { color: ACCENT } : { color: STATUS_COLOURS[tone] }

/** A mark as a segment in its tone, `trailing` after the glyph (a space when a column follows). */
export function mark(entry: Mark, trailing = ''): Segment {
  return { text: entry.glyph + trailing, ...toned(entry.tone) }
}

/** A component's kind as a two-cell column: its glyph and a space, or two spaces for a kind the registry does not know. */
export function kindMark(kind: string | null | undefined): Segment {
  const entry = kind === null || kind === undefined ? undefined : KIND[kind]
  return entry === undefined ? { text: '  ' } : mark(entry, ' ')
}

/**
 * A card's legend: each mark it shows with its count and word, in the order
 * given, those counted zero left out. A count given as `''` names the mark
 * alone (`✦ hotspot only`). Empty when nothing is left.
 */
export function legend(entries: ReadonlyArray<readonly [Mark, number | string]>): string {
  return entries
    .filter(([, count]) => count !== 0)
    .map(([entry, count]) => {
      if (count === '') return `${entry.glyph} ${entry.legend}`
      const word = count !== 1 && entry.plural !== undefined ? entry.plural : entry.legend
      return `${entry.glyph} ${typeof count === 'number' ? grouped(count) : count} ${word}`
    })
    .join(' · ')
}

/** The legend of the kind marks a card shows: each known kind once, in the registry's order (`C class · m method`). */
export function kindLegend(kinds: ReadonlyArray<string>): string {
  const shown = new Set(kinds)
  return Object.entries(KIND)
    .filter(([kind]) => shown.has(kind))
    .map(([, entry]) => `${entry.glyph} ${entry.legend}`)
    .join(' · ')
}

/** A delta as a signed number: `+3`, `−2`, `±0`. */
export function signed(n: number): string {
  return n === 0 ? '±0' : `${n > 0 ? '+' : '−'}${grouped(Math.abs(n))}`
}
