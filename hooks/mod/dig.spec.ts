import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { freshDig, LOOKUPS_BEFORE_REMINDER, observe, QUESTIONS, type Dig } from './dig'

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
      'You dug past a Knossos answer this turn (list_usages, then 2 lookups). That is the case the standing instruction in the session brief covers: before you answer, record what the answer left out (a caller, a hop, a dispatch the graph could not see, a name it did not resolve) with annotate_component on the component it is about, by its full name even if Knossos did not find it (kind note, under 1000 characters, evidence_paths, execute true), so a fresh session with the same question starts from your note instead of digging again. Write nothing only if the answer was complete and you merely confirmed it.',
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

/**
 * Every tool the server offers is either a question a dig starts from (QUESTIONS) or named here with why it is
 * not one, so a tool added to the catalog is classified on purpose rather than left out by default.
 */
const NOT_QUESTIONS: Record<string, string> = {
  server_info: 'reports the server itself',
  diagnose_runtime: 'reports the runtime and its workers',
  list_projects: 'lists projects, nothing about their code',
  scan_project: 'scans; the files read after it are the ones about to be edited',
  remove_project: 'housekeeping',
  move_project: 'housekeeping',
  cleanup_stale_scans: 'housekeeping',
  maintain_database: 'housekeeping',
  list_snapshots: 'lists scans, not the code',
  snapshot_diff: 'compares two scans; reading the changed files after it reviews them, it does not dig past an answer',
  architecture_trends: "a project's figures over time, not an answer about its code",
  quality_gate: 'a pass or fail verdict on budgets',
  list_diagnostics: "the scan's own warnings",
  architecture_summary: 'counts the graph by kind; the lookups after it explore the project rather than check an answer',
  export_agent_brief: 'writes a brief for an instructions file, an export rather than an answer',
  export_diagram: 'draws the graph, an export rather than an answer',
  annotate_component: 'records what a dig found: it ends a dig rather than starting one',
}

describe('the server catalog', () => {
  it('has every tool classified: a question, or not one for a stated reason', () => {
    const catalog = readFileSync(fileURLToPath(new URL('../../docs/reference/mcp-tools.md', import.meta.url)), 'utf8')
    const tools = [...catalog.matchAll(/^## `([a-z_]+)`$/gm)].map(m => m[1]!)
    expect(tools.length).toBeGreaterThan(30)
    expect(tools.filter(tool => !QUESTIONS.has(tool) && !(tool in NOT_QUESTIONS))).toEqual([])
    expect([...QUESTIONS].filter(tool => tool in NOT_QUESTIONS)).toEqual([])
    // Neither list names a tool the catalog no longer has.
    expect([...QUESTIONS, ...Object.keys(NOT_QUESTIONS)].filter(tool => !tools.includes(tool))).toEqual([])
  })
})
