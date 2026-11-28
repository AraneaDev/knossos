import { describe, expect, it } from 'vitest'
import { testFindingsReadLine, testFindingsTurnNote } from './notes'

const item = (i: number, stale = true) => ({ file: `tests/a${i}.ts`, line: i, message: `stale ${i}`, stale })

describe('testFindingsReadLine', () => {
  it('says one double in the singular', () => {
    expect(testFindingsReadLine('src/payment.ts', { doubles: 1, target: 'PaymentGateway', reasons: ['charge(): arity'] })).toBe('knossos: 1 test double of PaymentGateway no longer matches it (charge(): arity).')
  })

  it('says two doubles in the plural with every reason', () => {
    expect(testFindingsReadLine('src/payment.ts', { doubles: 2, target: 'PaymentGateway', reasons: ['charge(): arity', 'refund(): missing'] })).toBe('knossos: 2 test doubles of PaymentGateway no longer match it (charge(): arity, refund(): missing).')
  })
})

describe('testFindingsTurnNote', () => {
  it('is null when nothing was introduced', () => {
    expect(testFindingsTurnNote([], 0, 0)).toBeNull()
  })

  it('names one stale mock', () => {
    expect(testFindingsTurnNote([item(1)], 1, 1)).toBe('knossos: this turn introduced 1 stale mock. Fix it before finishing:\n- tests/a1.ts:1 stale 1')
  })

  it('lists three', () => {
    expect(testFindingsTurnNote([item(1), item(2), item(3)], 3, 3)).toBe('knossos: this turn introduced 3 stale mocks. Fix them before finishing:\n- tests/a1.ts:1 stale 1\n- tests/a2.ts:2 stale 2\n- tests/a3.ts:3 stale 3')
  })

  it('lists ten and counts the rest', () => {
    const note = testFindingsTurnNote(Array.from({ length: 12 }, (_, i) => item(i + 1)), 12, 12)!
    const lines = note.split('\n')
    expect(lines[0]).toBe('knossos: this turn introduced 12 stale mocks. Fix them before finishing:')
    expect(lines).toHaveLength(12)
    expect(lines.at(-1)).toBe('- …and 2 more (run audit_tests)')
  })

  it('names one other test finding, not a stale mock', () => {
    expect(testFindingsTurnNote([item(1, false)], 1, 0)).toBe('knossos: this turn introduced 1 test finding. Fix it before finishing:\n- tests/a1.ts:1 stale 1')
  })

  it('pluralises other test findings', () => {
    expect(testFindingsTurnNote([item(1, false), item(2, false)], 2, 0)!.split('\n')[0]).toBe('knossos: this turn introduced 2 test findings. Fix them before finishing:')
  })

  it('says both when a stale mock and another finding were introduced', () => {
    expect(testFindingsTurnNote([item(1), item(2, false)], 2, 1)!.split('\n')[0]).toBe('knossos: this turn introduced 1 stale mock and 1 other test finding. Fix them before finishing:')
    expect(testFindingsTurnNote([item(1), item(2), item(3, false)], 3, 2)!.split('\n')[0]).toBe('knossos: this turn introduced 2 stale mocks and 1 other test finding. Fix them before finishing:')
  })
})
