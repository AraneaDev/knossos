import { describe, expect, it } from 'vitest'
import { freshDig, LOOKUPS_BEFORE_REMINDER, observe, type Dig } from './dig'

/** Feeds calls in order, returning the last state and every reminder raised. */
function run(calls: [string, unknown?][]): { dig: Dig; reminders: string[] } {
  let dig = freshDig()
  const reminders: string[] = []
  for (const [tool, input] of calls) {
    const seen = observe(dig, tool, input ?? {})
    dig = seen.dig
    if (seen.remind !== null) reminders.push(seen.remind)
  }
  return { dig, reminders }
}

describe('the dig reminder', () => {
  it('reminds once a Knossos answer is followed by two more lookups', () => {
    expect(LOOKUPS_BEFORE_REMINDER).toBe(2)
    const { reminders } = run([['mcp__knossos__list_usages'], ['Grep'], ['Read']])
    expect(reminders).toEqual([
      'You dug past a Knossos answer this turn (list_usages, then 2 lookups). If you established a structural fact, record it with annotate_component (kind note, evidence_paths), or say why not.',
    ])
  })

  it('counts a Bash call only when it searches, and another Knossos query as a lookup', () => {
    expect(run([['mcp__knossos__impact_analysis'], ['Bash', { command: 'git status' }], ['Bash', { command: 'npm test' }]]).reminders).toEqual([])
    expect(run([['mcp__knossos__impact_analysis'], ['Bash', { command: 'rg -n foo src' }], ['mcp__knossos__inspect_component']]).reminders).toHaveLength(1)
    expect(run([['mcp__knossos__impact_analysis'], ['Bash', { command: '  grep -rn foo .' }], ['Read']]).reminders).toHaveLength(1)
  })

  it('stays quiet when the agent already annotated', () => {
    const { reminders, dig } = run([['mcp__knossos__list_usages'], ['Grep'], ['mcp__knossos__annotate_component'], ['Read'], ['Read']])
    expect(reminders).toEqual([])
    expect(dig.annotated).toBe(true)
  })

  it('reminds at most once, however long the digging goes on', () => {
    expect(run([['mcp__knossos__list_usages'], ['Grep'], ['Read'], ['Read'], ['Grep']]).reminders).toHaveLength(1)
  })

  it('says nothing about lookups no Knossos answer came before', () => {
    expect(run([['Grep'], ['Read'], ['Read']]).reminders).toEqual([])
  })

  it('ignores calls that are neither a lookup nor Knossos', () => {
    expect(run([['mcp__knossos__list_usages'], ['Edit'], ['TodoWrite'], ['Grep']]).reminders).toEqual([])
  })

  it('starts only from a question to the graph, never from scanning or housekeeping', () => {
    expect(run([['mcp__knossos__scan_project'], ['Read'], ['Read']]).reminders).toEqual([])
    expect(run([['mcp__knossos__list_projects'], ['mcp__knossos__server_info'], ['Read'], ['Read']]).reminders).toEqual([])
  })

  it('counts searches however the command is chained, and Glob', () => {
    expect(run([['mcp__knossos__list_usages'], ['Bash', { command: 'cd src && rg -n total' }], ['Glob']]).reminders).toHaveLength(1)
    expect(run([['mcp__knossos__list_usages'], ['Bash', { command: 'git grep -n total' }], ['Bash', { command: 'cat a.php | grep total' }]]).reminders).toHaveLength(1)
    expect(run([['mcp__knossos__list_usages'], ['Bash', { command: 'find . -name "*.php"' }], ['Bash', { command: 'ag total' }]]).reminders).toHaveLength(1)
  })

  it("matches the plugin's knossos server only, not a server merely named like it", () => {
    expect(run([['mcp__knossos-docs__search'], ['Read'], ['Read']]).reminders).toEqual([])
    expect(run([['mcp__plugin_knossos_knossos__list_usages'], ['Read'], ['Read']]).reminders).toHaveLength(1)
  })

  it('does not let an annotation before the digging silence it', () => {
    expect(run([['mcp__knossos__annotate_component'], ['mcp__knossos__list_usages'], ['Read'], ['Read']]).reminders).toHaveLength(1)
  })
})
