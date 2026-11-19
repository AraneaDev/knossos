import { beforeEach, describe, expect, it } from 'vitest'
import type { Dashboard, TurnBrief } from '../../types'
import { noteRead, turnEndNote } from './agent'
import type { Port } from './port'
import { mod, reset } from './state'

const ROOT = '/work/app'
const options = { fanInThreshold: 5, enforcePolicies: true, agentNotes: true, testChecks: true, watch: false, watchPollMs: 1000, notifications: true }
const READ_LINE = 'knossos: 2 test doubles of PaymentGateway no longer match it (charge(): arity).'

function dashboard(): Dashboard {
  return {
    status: 'ok',
    path: ROOT,
    project_root: ROOT,
    fan_in: [],
    test_findings: { by_production_file: { 'src/payment.ts': { doubles: 2, target: 'PaymentGateway', reasons: ['charge(): arity'], keys: ['k1', 'k2'] } } },
  } as unknown as Dashboard
}

function fakePort(): Port {
  return {
    session: { root: async () => ROOT },
    fs: { stat: async (path: string) => ({ realPath: path }) },
    state: { dashboard: { read: async () => dashboard() } },
  } as unknown as Port
}

const finding = (key: string, line: number, stale = true) => ({ key, file: 'tests/pay.test.ts', line, message: `stale mock ${key}`, stale })

function brief(over: { introduced?: ReturnType<typeof finding>[]; status?: string; total?: number; totalStale?: number; policy?: boolean; tests?: boolean } = {}): TurnBrief {
  const introduced = over.introduced ?? [finding('k1', 10)]
  return {
    status: 'ok',
    tests: over.tests === true ? [{ path: 'tests/a.test.ts', distance: 1 }] : [],
    policy: { status: 'evaluated', total: over.policy === true ? 1 : 0, violations: over.policy === true ? [{ policy_id: 'p', source: 'A', target: 'B' }] : [], truncated: false },
    test_findings: { status: over.status ?? 'ok', introduced, total: over.total ?? introduced.length, total_stale: over.totalStale ?? introduced.filter(f => f.stale).length, truncated: false, suppressions_invalid: null },
  } as unknown as TurnBrief
}

beforeEach(() => reset(options))

describe('the Read note for stale mocks', () => {
  it('says the line on a Read of the file, once per loop', async () => {
    expect(await noteRead(fakePort(), `${ROOT}/src/payment.ts`, 'main')).toBe(READ_LINE)
    expect(await noteRead(fakePort(), `${ROOT}/src/payment.ts`, 'main')).toBeNull()
  })

  it('says it again to another loop', async () => {
    await noteRead(fakePort(), `${ROOT}/src/payment.ts`, 'main')
    expect(await noteRead(fakePort(), `${ROOT}/src/payment.ts`, 'sub')).toBe(READ_LINE)
  })

  it('stays quiet with testChecks off', async () => {
    reset({ ...options, testChecks: false })
    expect(await noteRead(fakePort(), `${ROOT}/src/payment.ts`, 'main')).toBeNull()
  })

  it('stays quiet with agentNotes off', async () => {
    reset({ ...options, agentNotes: false })
    expect(await noteRead(fakePort(), `${ROOT}/src/payment.ts`, 'main')).toBeNull()
  })
})

describe('the turn-end note for stale mocks', () => {
  it('names an introduced mock, and not the same key on the next turn even when it moved', () => {
    expect(turnEndNote(brief(), null)).toBe('knossos: this turn introduced 1 stale mock. Fix it before finishing:\n- tests/pay.test.ts:10 stale mock k1')
    expect(turnEndNote(brief({ introduced: [finding('k1', 11)] }), null)).toBeNull()
  })

  it('names a new key after an old one', () => {
    turnEndNote(brief(), null)
    expect(turnEndNote(brief({ introduced: [finding('k1', 11), finding('k2', 20)], total: 2 }), null)).toContain('introduced 1 stale mock.')
  })

  it('says nothing when every listed finding was said and only the uncapped total is over ten', () => {
    const listed = Array.from({ length: 10 }, (_, i) => finding(`k${i}`, i + 1))
    expect(turnEndNote(brief({ introduced: listed, total: 14, totalStale: 14 }), null)).toContain('introduced 14 stale mocks.')
    expect(turnEndNote(brief({ introduced: listed, total: 14, totalStale: 14 }), null)).toBeNull()
  })

  it('counts only what is said when some listed findings were already said', () => {
    const listed = Array.from({ length: 10 }, (_, i) => finding(`k${i}`, i + 1))
    turnEndNote(brief({ introduced: listed.slice(0, 4), total: 4 }), null)
    const note = turnEndNote(brief({ introduced: listed, total: 14, totalStale: 14 }), null)!
    expect(note).toContain('introduced 10 stale mocks.')
    expect(note.split('\n')).toHaveLength(1 + 6 + 1)
    expect(note).toContain('- …and 4 more (run audit_tests)')
  })

  it('says a tautology is a test finding, not a stale mock', () => {
    expect(turnEndNote(brief({ introduced: [finding('t1', 3, false)] }), null)).toBe('knossos: this turn introduced 1 test finding. Fix it before finishing:\n- tests/pay.test.ts:3 stale mock t1')
  })

  it('says both counts when a ghost and a tautology arrive together', () => {
    const note = turnEndNote(brief({ introduced: [finding('k1', 10), finding('t1', 3, false)] }), null)!
    expect(note.split('\n')[0]).toBe('knossos: this turn introduced 1 stale mock and 1 other test finding. Fix them before finishing:')
  })

  it('counts the stale and the other over what is said, per key, once a session', () => {
    turnEndNote(brief({ introduced: [finding('k1', 10)] }), null)
    const note = turnEndNote(brief({ introduced: [finding('k1', 10), finding('t1', 3, false)], total: 2, totalStale: 1 }), null)!
    expect(note.split('\n')[0]).toBe('knossos: this turn introduced 1 test finding. Fix it before finishing:')
    expect(turnEndNote(brief({ introduced: [finding('k1', 10), finding('t1', 3, false)], total: 2, totalStale: 1 }), null)).toBeNull()
  })

  it('splits the unlisted rest of an over-cap total by total_stale', () => {
    const listed = Array.from({ length: 10 }, (_, i) => finding(`k${i}`, i + 1))
    const note = turnEndNote(brief({ introduced: listed, total: 14, totalStale: 12 }), null)!
    expect(note.split('\n')[0]).toBe('knossos: this turn introduced 12 stale mocks and 2 other test findings. Fix them before finishing:')
  })

  it('keeps the policy and tests parts and drops this one with testChecks off', () => {
    reset({ ...options, testChecks: false })
    const note = turnEndNote(brief({ policy: true, tests: true }), null)!
    expect(note).toContain('boundary-policy violation')
    expect(note).toContain('test reaches')
    expect(note).not.toContain('stale mock')
    expect(note).not.toContain('test finding')
  })

  it('orders policy, stale mocks, then tests', () => {
    const note = turnEndNote(brief({ policy: true, tests: true }), null)!
    expect(note.indexOf('boundary-policy')).toBeLessThan(note.indexOf('stale mock'))
    expect(note.indexOf('stale mock')).toBeLessThan(note.indexOf('test reaches'))
  })

  it('says nothing with agentNotes off', () => {
    reset({ ...options, agentNotes: false })
    expect(turnEndNote(brief(), null)).toBeNull()
  })

  it('adds nothing for a not_evaluated brief, and logs once', () => {
    const lines: string[] = []
    expect(turnEndNote(brief({ status: 'not_evaluated' }), null, l => lines.push(l))).toBeNull()
    expect(turnEndNote(brief({ status: 'not_evaluated' }), null, l => lines.push(l))).toBeNull()
    expect(lines).toHaveLength(1)
    expect(mod.agent.testFindingsSeen.size).toBe(0)
  })
})
