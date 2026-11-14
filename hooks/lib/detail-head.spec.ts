import { describe, expect, it } from 'vitest'
import { detailHead } from './detail-head'
import { NO_HUES } from './palette'
import { detailInput } from './views'

const METHOD = { name: 'Checkout::capture', canonical: 'App\\Checkout::capture', kind: 'method', boundary: 'billing', place: 'src/Checkout.php:42', loc: null }
const LINE = 'capture(order: App\\Order, retries?: int): ?App\\Receipt'

const rows = (head: Parameters<typeof detailHead>[0], columns: number) =>
  (detailHead(head, NO_HUES).make(columns, 99)?.body ?? []).map(r => ({ key: r.key, text: r.segments.map(s => s.text).join(''), dim: r.segments.every(s => s.dim === true) }))

describe('the detail head card', () => {
  it('says how a function is called, between where it is and its full name', () => {
    expect(rows({ ...METHOD, signature: LINE }, 80).map(r => r.key)).toEqual(['detail-place', 'detail-signature-0', 'detail-canonical'])
    expect(rows({ ...METHOD, signature: LINE }, 80)[1]).toEqual({ key: 'detail-signature-0', text: LINE, dim: false })
  })

  it('wraps a signature the pane is too narrow for, so the return type is never cut off', () => {
    const wrapped = rows({ ...METHOD, signature: LINE }, 30).filter(r => r.key.startsWith('detail-signature-'))

    expect(wrapped.length).toBeGreaterThan(1)
    expect(wrapped.map(r => r.text).join(' ')).toBe(LINE)
    for (const row of wrapped) expect(row.text.length).toBeLessThanOrEqual(30)
  })

  it('has no signature row for a component that has none', () => {
    for (const signature of [undefined, null, '']) {
      expect(rows({ ...METHOD, kind: 'class', signature }, 80).map(r => r.key)).toEqual(['detail-place', 'detail-canonical'])
    }
  })

  it('still says so when the graph knows no place, and shows a signature without one', () => {
    const nowhere = { ...METHOD, canonical: METHOD.name, place: null }

    expect(rows(nowhere, 80)).toEqual([{ key: 'detail-place', text: expect.stringContaining('declared nowhere the graph knows'), dim: true }])
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
