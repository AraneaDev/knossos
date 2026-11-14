import type { Block } from './cards'
import { chip } from './chips'
import type { Hues } from './palette'
import { dimRow } from './rows'
import { pathSegments, wrapWords } from './text'
import type { Loc, Row, Segment } from './types'

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
 * name, then where it is declared, how it is called when it is a function,
 * and its full name when the title shortens it. The signature is the one row
 * not dimmed: it is what a reader opens a function to find out, and it wraps
 * rather than being cut, since a cut signature hides the return type.
 */
export function detailHead(c: DetailHead, hues: Hues): Block {
  const label: Segment[] = [{ text: c.kind, dim: true }, ...(c.boundary === null ? [] : [{ text: ' · ', dim: true }, ...chip(c.boundary, hues)])]
  return {
    key: 'detail',
    make: (columns: number) => {
      const body: Row[] = []
      if (c.place !== null) body.push({ key: 'detail-place', segments: pathSegments(c.place, columns, c.loc) })
      const signature = c.signature ?? null
      if (signature !== null && signature !== '') body.push(...wrapWords(signature, columns).map((line, i): Row => ({ key: `detail-signature-${i}`, segments: [{ text: line }] })))
      if (c.canonical !== c.name) body.push(dimRow('detail-canonical', c.canonical, columns))
      if (body.length === 0) body.push(dimRow('detail-place', 'declared nowhere the graph knows', columns))
      return { key: 'detail', title: c.name, note: label, body }
    },
  }
}
