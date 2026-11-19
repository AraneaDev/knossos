import { describe, expect, it } from 'vitest'
import type { Dashboard } from '../../types'
import { findRow, plainText, rawText } from './__tests__/plain-text'
import { issueRows } from './__tests__/tabs'
import { cells } from './text'
import { issueCount, issuesInput, issuesList, issuesWalk } from './views'

type Finding = NonNullable<Dashboard['test_findings']>['list'][number]

const finding = (over: Partial<Finding>): Finding => ({
  key: 'k',
  category: 'stale_mock',
  sub_rule: 'arity',
  confidence: 'definite',
  message: 'charge() takes 2 arguments',
  file: 'tests/PayTest.php',
  line: 7,
  target_file: 'src/Pay.php',
  ...over,
})

/** A dashboard with no issues but the given test findings; the counts follow the list unless given. */
const dash = (list: Finding[], over: Partial<NonNullable<Dashboard['test_findings']>> = {}): Dashboard =>
  ({
    status: 'ok',
    project_root: '/work/p',
    dead_code_candidates: 0,
    dead_code_truncated: false,
    diagnostics: {
      total: 1,
      errors: 1,
      warnings: 0,
      infos: 0,
      items: [
        {
          severity: 'error',
          code: 'X1',
          message: 'broken',
          path: 'src/B.php',
          line: 2,
        },
      ],
    },
    test_findings: {
      definite: list.filter(f => f.confidence === 'definite').length,
      warning: list.filter(f => f.confidence === 'warning').length,
      by_production_file: {},
      by_production_file_truncated: false,
      list,
      list_truncated: false,
      suppressions_invalid: null,
      ...over,
    },
  }) as unknown as Dashboard

const MIXED = [
  finding({
    key: 'w1',
    confidence: 'warning',
    message: 'maybe stale',
    file: 'tests/WarnTest.php',
    line: 3,
  }),
  finding({ key: 'd1', message: 'charge() takes 2 arguments', line: 7 }),
  finding({
    key: 'd2',
    message: 'refund() was removed',
    file: 'tests/RefundTest.php',
    line: 11,
  }),
]

describe('the Issues tab test findings', () => {
  it('lists definite findings before warnings, each in the server order, after the diagnostics', () => {
    const issues = issuesInput(dash(MIXED))
    expect(issues.testFindings?.items.map(x => x.text)).toEqual(['charge() takes 2 arguments', 'refund() was removed', 'maybe stale'])
    const text = issueRows(issues, 0, 100).map(plainText)
    const at = (needle: string) => text.findIndex(t => t.includes(needle))
    expect(at('Test findings')).toBeGreaterThan(at('Diagnostics'))
    expect(at('charge() takes 2')).toBeLessThan(at('refund() was removed'))
    expect(at('refund() was removed')).toBeLessThan(at('maybe stale'))
    expect(text.find(t => t.includes('Test findings'))).toMatch(/2.*1/)
  })

  it('marks definite with the error mark and a warning with the warning mark, then says file:line', () => {
    const rows = issueRows(issuesInput(dash(MIXED)), 0, 100)
    expect(rawText(findRow(rows, 'tests-0')!)).toMatch(/✗ charge\(\) takes 2 arguments +tests\/PayTest\.php:7/)
    expect(rawText(findRow(rows, 'tests-2')!)).toMatch(/▲ maybe stale +tests\/WarnTest\.php:3/)
  })

  it('walks violations, diagnostics, test findings, dead code, hotspots, files over budget', () => {
    const walk = issuesWalk(issuesInput(dash(MIXED)))
    expect(walk).toMatchObject({
      violations: { offset: 0, length: 0 },
      diagnostics: { offset: 0, length: 1 },
      tests: { offset: 1, length: 3 },
      dead: { offset: 4 },
    })
  })

  it('opens a finding at its file and line, the row pressing its place in the walk', () => {
    const issues = issuesInput(dash(MIXED))
    expect(issuesList(issues)[1]).toEqual({
      name: 'tests/PayTest.php:7',
      canonical: 'tests/PayTest.php',
      loc: { path: '/work/p/tests/PayTest.php', line: 7 },
      file: true,
    })
    const press = findRow(issueRows(issues, 1, 100), 'tests-0')!.segments.find(s => s.press !== undefined)?.press?.id
    expect(press).toBe('row:1')
  })

  it('only marks a finding about no file', () => {
    const issues = issuesInput(dash([finding({ file: null, line: null })]))
    expect(issuesList(issues)[1]).toMatchObject({ inert: true })
  })

  it('counts the definite findings, and no warning, in the tab label', () => {
    expect(issueCount(issuesInput(dash(MIXED)))).toEqual({
      n: 3,
      plus: false,
    })
    expect(issueCount(issuesInput(dash([MIXED[0]!])))).toEqual({
      n: 1,
      plus: false,
    })
  })

  it('says when the list is cut, and counts what it leaves out', () => {
    const rows = issueRows(issuesInput(dash([MIXED[1]!], { definite: 201, list_truncated: true })), 0, 100)
    expect(plainText(findRow(rows, 'tests-more')!).trim()).toBe('+200 more')
  })

  it('shows why knossos.json could not be read', () => {
    const rows = issueRows(
      issuesInput(
        dash(MIXED, {
          suppressions_invalid: 'unexpected token at line 3',
        }),
      ),
      0,
      100,
    )
    expect(rawText(findRow(rows, 'tests-invalid')!)).toContain('suppressions unreadable: unexpected token at line 3')
  })

  it('reads none when nothing is found, and draws no card text for a knossos that sends none', () => {
    expect(
      issueRows(issuesInput(dash([])), 0, 100)
        .map(plainText)
        .find(t => t.includes('Test findings')),
    ).toMatch(/✓ 0/)
    const older = {
      ...dash([]),
      test_findings: undefined,
    } as unknown as Dashboard
    expect(issuesInput(older).testFindings).toBeNull()
    expect(issueCount(issuesInput(older)).n).toBe(1)
  })

  it('fits every row to the pane at narrow widths', () => {
    const long = finding({
      message: 'a very long message about a stale mock that keeps going well past any narrow pane',
      file: 'tests/Deeply/Nested/Folder/ThePayTest.php',
      line: 1234,
    })
    for (const columns of [24, 30, 40, 60]) {
      for (const row of issueRows(
        issuesInput(
          dash([long, MIXED[0]!], {
            suppressions_invalid: 'x'.repeat(80),
            list_truncated: true,
            definite: 5,
          }),
        ),
        0,
        columns,
      )) {
        expect(cells(rawText(row)), `${row.key} at ${columns}`).toBeLessThanOrEqual(columns)
      }
    }
  })

  it('draws the same rows for the same input', () => {
    const rows = () => issueRows(issuesInput(dash(MIXED)), 0, 90).map(rawText)
    expect(rows()).toEqual(rows())
  })
})
