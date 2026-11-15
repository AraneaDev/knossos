import type { Block } from './cards'
import { chip } from './chips'
import { opensFile } from './editor'
import type { Hues } from './palette'
import { signatureLines } from './signature'
import { labelledRows, wrapValue } from './spacing'
import type { Fact } from './spacing'
import { cells, pathSegments } from './text'
import type { Loc, Segment } from './types'

/** What the head card of a component's detail says about it. */
export type DetailHead = {
  name: string
  canonical: string
  kind: string
  boundary: string | null
  place: string | null
  loc: Loc | null
  /** How a function or method is called: its parameters, their types and what it returns. Absent from an older knossos and for anything that is not called. */
  signature?: string | null
}

/**
 * The card naming the component on show: its kind and boundary beside the
 * name, then its facts, each under a dim label: `where` it is declared,
 * how to `call` it when it is a function, and its full `name` when the
 * title shortens it. The signature is the one fact not dimmed: it is what a
 * reader opens a function to find out, and it breaks one parameter a line
 * rather than being cut, since a cut signature hides the return type (see
 * `signature.ts`). Every value wraps under its own column.
 */
export function detailHead(c: DetailHead, hues: Hues): Block {
  const label: Segment[] = [{ text: c.kind, dim: true }, ...(c.boundary === null ? [] : [{ text: ' · ', dim: true }, ...chip(c.boundary, hues)])]
  const dim = (text: string): Fact['lines'] => width => wrapValue(text, width).map(line => [{ text: line, dim: true }])
  return {
    key: 'detail',
    make: (columns: number) => {
      const facts: Fact[] = []
      const place = c.place
      if (place !== null) facts.push({ key: 'detail-place', label: 'where', lines: width => placeLines(place, width, c.loc) })
      const signature = c.signature ?? null
      if (signature !== null && signature !== '') facts.push({ key: 'detail-signature', label: 'call', numbered: true, lines: width => signatureLines(signature, width).map(line => [{ text: line }]) })
      if (c.canonical !== c.name) facts.push({ key: 'detail-canonical', label: 'name', lines: dim(c.canonical) })
      if (facts.length === 0) facts.push({ key: 'detail-place', label: 'where', lines: dim('declared nowhere the graph knows') })
      return { key: 'detail', title: c.name, note: label, body: labelledRows(facts, columns) }
    },
  }
}

/**
 * A place (`path:line`) as lines `width` wide: one, its folder dim and its
 * file bright, while it fits; else broken after a `/`, the folders dim and
 * the last line's file bright. The file is what opens it.
 */
function placeLines(place: string, width: number, loc: Loc | null): Segment[][] {
  if (cells(place) <= width) return [pathSegments(place, width, loc)]
  const lines = wrapValue(place, width)
  return lines.map((line, i) => {
    if (i < lines.length - 1) return [{ text: line, dim: true }]
    const cut = line.lastIndexOf('/') + 1
    return [{ text: line.slice(0, cut), dim: true }, opensFile(line.slice(cut), loc)].filter(s => s.text !== '')
  })
}
