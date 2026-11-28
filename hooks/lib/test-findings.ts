/**
 * The Issues tab's test findings: the stale mocks and other test-double
 * findings a scan stored, as input lines, walk rows, entries and a card.
 * Definite findings come first, then warnings; each keeps the server's order.
 * What needs the Issues tab's own helpers (`locIn`, the listing) comes in as
 * an argument, so this file never imports its runtime.
 */
import type { Dashboard } from '../../types'
import { noteOf } from './cards'
import type { Block, Section } from './cards'
import { STATUS_COLOURS } from './palette'
import { legend, mark, SEVERITY } from './marks'
import { placeText } from './paths'
import { button, moreListedRow } from './rows'
import { bodyLine } from './spacing'
import { cells, fit } from './text'
import type { Loc, Row } from './types'
import type { Entry, Openable } from './views'

/** A finding as Issues lists it; `path` null for one about no file. */
export type TestFindingLine = {
  confidence: 'definite' | 'warning'
  text: string
  path: string | null
  place: string
  loc: Loc | null
}
/** `definite` and `warning` count every finding (the list is capped); `invalid` is why knossos.json's suppressions could not be read. */
export type TestFindingsInput = {
  definite: number
  warning: number
  items: TestFindingLine[]
  truncated: boolean
  invalid: string | null
}

/** The dashboard's test findings as lines, definite before warning (the sort is stable); null from a knossos that sends none. */
export function testFindingsInput(d: Dashboard, locIn: (root: string | null, path: string | null, line: number | null) => Loc | null): TestFindingsInput | null {
  const t = d.test_findings
  if (t === undefined) return null
  const items = t.list.map((f): TestFindingLine => ({
    confidence: f.confidence,
    text: f.message,
    path: f.file,
    place: placeText(f.file, f.line),
    loc: locIn(d.project_root, f.file, f.line),
  }))
  return {
    definite: t.definite,
    warning: t.warning,
    items: [...items.filter(x => x.confidence === 'definite'), ...items.filter(x => x.confidence !== 'definite')],
    truncated: t.list_truncated,
    invalid: t.suppressions_invalid,
  }
}

/** The walk's rows: a finding opens its file, and `e` goes to its line; one about no file is only marked. */
export const testFindingRows = (t: TestFindingsInput | null): Openable[] =>
  (t?.items ?? []).map((x): Openable => (x.path === null ? { name: x.text, canonical: x.text, inert: true } : { name: x.place, canonical: x.path, loc: x.loc, file: true }))

/** Each finding as an entry, marked by its confidence, the first `offset` into the walk. */
export function testFindingEntries(items: TestFindingLine[], offset: number, selected: number): Entry[] {
  return items.map((x, i) => ({
    selected: offset + i === selected,
    mark: mark(x.confidence === 'definite' ? SEVERITY.error : SEVERITY.warn),
    place: x.place,
    whole: true,
    loc: x.loc,
    need: cells(x.text),
    main: width => [button(`row:${offset + i}`, fit(x.text, width))],
  }))
}

/**
 * The test findings' card: definite and warning counts on its edge, the
 * listed findings, then what the list leaves out (the cap) and why
 * knossos.json's suppressions could not be read. `list` draws the entries.
 */
export function testFindingsBlock(
  t: TestFindingsInput | null,
  entries: Entry[],
  list: (entries: Entry[], at: { columns: number; limit: number; scrolled?: number }) => { body: Row[]; window: Section['window'] },
  min: number,
): Block {
  return {
    key: 'tests',
    grow: { length: entries.length, min },
    make: (columns, limit, scrolled) => {
      const counts =
        t === null
          ? 'not reported'
          : t.definite + t.warning === 0
            ? `${SEVERITY.ok.glyph} 0`
            : legend([
                [SEVERITY.error, t.definite],
                [SEVERITY.warn, t.warning],
              ])
      const { body, window } = list(entries, {
        columns,
        limit,
        scrolled,
      })
      if (t !== null) {
        const total = t.definite + t.warning
        body.push(...moreListedRow('tests-more', total, entries.length, columns))
        if (t.truncated && total <= entries.length) body.push(bodyLine('tests-cut', 'list cut short', columns))
        if (t.invalid !== null) body.push(bodyLine('tests-invalid', `suppressions unreadable: ${t.invalid}`, columns, { color: STATUS_COLOURS.warn }))
      }
      return {
        key: 'tests',
        title: 'Test findings',
        note: noteOf(counts),
        body,
        empty: 'none',
        ...(window === undefined ? {} : { window }),
      }
    },
  }
}
