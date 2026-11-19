import { describe, test, expect } from 'claude-code/testing'
import { ROOT, START, TURN, brief, edit, world } from './__tests__/world'

const STALE = { status: 'ok', introduced: [{ key: 'k1', file: 'tests/PayTest.php', line: 7, message: 'charge() arity' }], total: 1, truncated: false, suppressions_invalid: null }

describe('the testChecks setting', () => {
  test('lets a stale mock reach the model after a turn', async ($, on) => {
    const w = world(on, { brief: [{ stdout: brief({ test_findings: STALE }) }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/A.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.logs.filter(l => l.text.includes('this turn introduced 1 stale mock. Fix it before finishing:'))).toHaveLength(1)
  })

  test('off keeps stale mocks off the model', { options: { testChecks: false } }, async ($, on) => {
    const w = world(on, { brief: [{ stdout: brief({ test_findings: STALE }) }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/A.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.logs.filter(l => l.text.includes('stale mock'))).toHaveLength(0)
  })
})
