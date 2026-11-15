import { describe, expect, it } from 'vitest'
import { cardInner, cardRows } from './cards'
import { detailHead } from './detail-head'
import { NO_HUES } from './palette'
import { rawText } from './__tests__/plain-text'
import { rowWidth } from './rows'
import { tierOf } from './table'
import { cells } from './text'
import { detailInput } from './views'

const METHOD = { name: 'Checkout::capture', canonical: 'App\\Checkout::capture', kind: 'method', boundary: 'billing', place: 'src/Checkout.php:42', loc: null }
const LINE = 'capture(order: App\\Order, retries?: int): ?App\\Receipt'

const rows = (head: Parameters<typeof detailHead>[0], columns: number) =>
  (detailHead(head, NO_HUES).make(columns, 99)?.body ?? []).map(r => ({ key: r.key, text: rawText(r), dim: r.segments.every(s => s.dim === true || s.text.trim() === '') }))
/** The card as the pane draws it at `columns`, its title rule and every row, frame and all. */
const card = (head: Parameters<typeof detailHead>[0], columns: number) => {
  const tier = tierOf(columns)
  return cardRows(detailHead(head, NO_HUES).make(cardInner(columns, tier), 99)!, columns, tier)
}

describe('the detail head card', () => {
  // The owner's sketch: where, call and name under dim labels, the signature one parameter a line where it does not fit.
  const SKETCH = { name: 'MaintenanceCommand::importNotes', canonical: 'Knossos\\Cli\\Command\\MaintenanceCommand::importNotes', kind: 'method', boundary: 'Cli', place: 'src/Cli/Command/MaintenanceCommand.php:119', loc: null, signature: 'importNotes(positionals: array, options: array, context: Knossos\\Cli\\CliCommandContext): int' }

  it('labels its facts and breaks a signature that does not fit one parameter a line (100 columns)', () => {
    expect(card(SKETCH, 100).map(r => rawText(r).trimEnd())).toEqual([
      `  ── ◇ MaintenanceCommand::importNotes ${'─'.repeat(41)} method · ■ Cli ──`,
      '',
      '     where   src/Cli/Command/MaintenanceCommand.php:119',
      '     call    importNotes(',
      '               positionals: array,',
      '               options: array,',
      '               context: Knossos\\Cli\\CliCommandContext,',
      '             ): int',
      '     name    Knossos\\Cli\\Command\\MaintenanceCommand::importNotes',
    ])
  })

  it('keeps a signature that fits on one line, and breaks a full name with no space after a separator (140 and 60 columns)', () => {
    expect(card(SKETCH, 140).map(r => rawText(r).trimEnd())).toContain(`     call    ${SKETCH.signature}`)
    const narrow = card(SKETCH, 60).map(r => rawText(r).trimEnd())
    expect(narrow).toContain('    name    Knossos\\Cli\\Command\\MaintenanceCommand::')
    expect(narrow).toContain('            importNotes')
    for (const r of card(SKETCH, 60)) expect(rowWidth(r)).toBe(60)
  })

  it('says how a function is called, between where it is and its full name, the signature alone not dimmed', () => {
    expect(rows({ ...METHOD, signature: LINE }, 80).map(r => r.key)).toEqual(['detail-place', 'detail-signature-0', 'detail-canonical'])
    expect(rows({ ...METHOD, signature: LINE }, 80)[1]).toEqual({ key: 'detail-signature-0', text: `   call    ${LINE}`, dim: false })
    expect(rows({ ...METHOD, signature: LINE }, 80)[2]!.dim).toBe(true)
    // The labels are dim, one word, lower case.
    const label = detailHead({ ...METHOD, signature: LINE }, NO_HUES).make(80, 99)!.body[0]!.segments[1]
    expect(label).toEqual({ text: 'where   ', dim: true })
  })

  it('wraps every long value under its own column at 60 columns, never under the label, never past the edge', () => {
    const inner = cardInner(60, 'narrow')
    const long = {
      ...METHOD,
      place: `src/${'nested/'.repeat(27)}Checkout.php:42`,
      canonical: `App\\${'Deeply\\'.repeat(9)}Checkout::capture`,
      signature: `capture(${Array.from({ length: 12 }, (_, i) => `p${i}: App\\Money\\Amount${i}`).join(', ')}, …): ?App\\Receipt`,
      loc: { path: '/w/src/Checkout.php', line: 42 },
    }
    expect(cells(long.place)).toBeGreaterThan(200)
    const drawn = detailHead(long, NO_HUES).make(inner, 99)!.body
    const column = '   where   '.length
    for (const r of drawn) {
      const text = rawText(r)
      expect(rowWidth(r), text).toBeLessThanOrEqual(inner)
      expect(text.trim(), r.key).not.toBe('')
      expect(text.slice(0, column).trim(), r.key).toMatch(/^(where|call|name|)$/)
      // A parameter stands two cells in from the call, inside the value column; every other value starts at it.
      expect(text.charAt(column + (/^detail-signature-[1-9]/.test(r.key) && !text.trimEnd().endsWith(': ?App\\Receipt') ? 2 : 0)), r.key).not.toBe(' ')
    }
    // The place reads whole down its column and its file still opens it; the twelve parameters each on a line, then `…` and the return type.
    const value = (prefix: string) => drawn.filter(r => r.key.startsWith(prefix)).map(r => rawText(r).slice(column))
    expect(value('detail-place').join('')).toBe(long.place)
    expect(drawn.flatMap(r => r.segments).filter(s => s.press?.id === 'file:/w/src/Checkout.php').map(s => s.text)).toEqual(['Checkout.php:42'])
    expect(value('detail-signature').filter(l => /^ {2}p\d+:/.test(l))).toHaveLength(12)
    expect(value('detail-signature').slice(-2)).toEqual(['  …', '): ?App\\Receipt'])
    expect(value('detail-canonical').join('')).toBe(long.canonical)
  })

  it('has no signature row for a component that has none', () => {
    for (const signature of [undefined, null, '']) {
      expect(rows({ ...METHOD, kind: 'class', signature }, 80).map(r => r.key)).toEqual(['detail-place', 'detail-canonical'])
    }
  })

  it('still says so when the graph knows no place, and shows a signature without one', () => {
    const nowhere = { ...METHOD, canonical: METHOD.name, place: null }

    expect(rows(nowhere, 80)).toEqual([{ key: 'detail-place', text: '   where   declared nowhere the graph knows', dim: true }])
    expect(rows({ ...nowhere, signature: LINE }, 80).map(r => r.key)).toEqual(['detail-signature-0'])
  })
})

describe('the detail input', () => {
  const answer = (signature?: string | null) => ({
    status: 'ok' as const,
    path: '/w',
    name: 'capture',
    project_id: 'p1',
    snapshot_id: 's1',
    component: { name: 'App\\Checkout::capture', display_name: 'capture', kind: 'method', path: 'src/Checkout.php', line: 42, boundary: 'billing', boundaries: ['billing'], used_by: { count: 0, truncated: false, names: [] }, uses: { count: 0, truncated: false, names: [] }, ...(signature === undefined ? {} : { signature }) },
    candidates: [],
  })
  const input = (signature?: string | null) => detailInput({ name: 'App\\Checkout::capture', label: 'capture' }, { snapshot_id: 's1', name: 'App\\Checkout::capture', phase: 'done', detail: answer(signature) }, '/w')

  it('carries the signature knossos answered, and null from one that sends none', () => {
    expect(input(LINE).component?.signature).toBe(LINE)
    expect(input(null).component?.signature).toBeNull()
    expect(input().component?.signature).toBeNull()
  })
})
