import { describe, test, expect } from 'claude-code/testing'
import { ROOT, START, TURN, brief, edit, world } from './__tests__/world'

const STALE = { status: 'ok', introduced: [{ key: 'k1', file: 'tests/PayTest.php', line: 7, message: 'charge() arity', stale: true }], total: 1, total_stale: 1, truncated: false, suppressions_invalid: null }

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

  test('says an edited test file that cannot fail is a test finding, not a stale mock', async ($, on) => {
    const tautology = { ...STALE, introduced: [{ key: 'k2', file: 'tests/PayTest.php', line: 9, message: 'assertion cannot fail', stale: false }], total_stale: 0 }
    const w = world(on, { brief: [{ stdout: brief({ test_findings: tautology }) }] })
    await $.session.start(START)
    await w.clock.settle()
    await edit($, `${ROOT}/src/A.php`)
    await $.turn.complete(TURN)
    await w.clock.settle()
    expect(w.logs.filter(l => l.text.includes('this turn introduced 1 test finding. Fix it before finishing:'))).toHaveLength(1)
    expect(w.logs.filter(l => l.text.includes('stale mock'))).toHaveLength(0)
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
