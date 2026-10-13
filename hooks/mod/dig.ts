/**
 * Whether an agent dug past a Knossos answer this turn, and the one reminder
 * that asks it to write down what it found.
 *
 * Digging is a Knossos query followed by more lookups (a Grep, a Read, a
 * searching Bash command, another Knossos query) before any annotate_component.
 * The answer the agent then holds is what the next session would have to dig
 * for again, so it is asked once, per turn, to record it as a note. Pure: the
 * mod keeps one Dig per agent and resets it when the turn completes.
 */

/** Lookups after a Knossos answer before the reminder comes. */
export const LOOKUPS_BEFORE_REMINDER = 2

/** One agent's turn so far: the Knossos tool it started from, the lookups since, and whether it annotated or was reminded. */
export type Dig = { knossos: string | null; lookups: number; annotated: boolean; reminded: boolean }

/** A turn before any call. */
export const freshDig = (): Dig => ({ knossos: null, lookups: 0, annotated: false, reminded: false })

/** The knossos server's tools, as a user-scope install or the plugin names them: `mcp__knossos__x`, `mcp__plugin_knossos_knossos__x`. */
const KNOSSOS = /^mcp__(?:plugin_knossos_)?knossos__(.+)$/

/**
 * The tools that answer a question about the code, which is what a dig starts from and what a further lookup can be.
 * Scanning, listing projects, server info and housekeeping answer none: a turn that scans and then reads the files
 * it is about to edit has dug for nothing.
 */
const QUESTIONS = new Set([
  'list_usages', 'impact_analysis', 'explain_flow', 'find_component', 'inspect_component', 'search_architecture',
  'file_context', 'architecture_context', 'change_impact', 'changed_files_impact', 'test_impact', 'suggest_location',
  'list_boundaries', 'dependency_cycles', 'list_annotations',
])

/** A command that searches the code, in any segment of a chained or piped command line. */
const SEARCH = /^(?:git\s+grep|rg|grep|egrep|ag|find)\b/

/** The Knossos tool a call names, without its server prefix, or null. */
function knossosTool(tool: string): string | null {
  return KNOSSOS.exec(tool)?.[1] ?? null
}

/** Whether a call looks something up in the code: a Grep, a Glob, a Read, or a Bash command that searches. */
function isLookup(tool: string, input: unknown): boolean {
  if (tool === 'Grep' || tool === 'Glob' || tool === 'Read') return true
  if (tool !== 'Bash') return false
  const command = (input as { command?: unknown } | null)?.command
  return typeof command === 'string' && command.split(/&&|\|\||;|\|/).some(segment => SEARCH.test(segment.trim()))
}

/**
 * The turn after one more call, and the reminder to deliver with that call's
 * result, if this call completes the digging pattern and nothing else has
 * answered for it: no annotation yet and no reminder earlier this turn.
 */
export function observe(dig: Dig, tool: string, input: unknown): { dig: Dig; remind: string | null } {
  const name = knossosTool(tool)
  // An annotation answers only a dig already under way; one made before any digging leaves the next dig open.
  if (name === 'annotate_component') return { dig: dig.knossos === null ? dig : { ...dig, annotated: true }, remind: null }
  const question = name !== null && QUESTIONS.has(name)
  if (question && dig.knossos === null) return { dig: { ...dig, knossos: name }, remind: null }
  if (dig.knossos === null || !(question || isLookup(tool, input))) return { dig, remind: null }
  const next = { ...dig, lookups: dig.lookups + 1 }
  if (next.annotated || next.reminded || next.lookups < LOOKUPS_BEFORE_REMINDER) return { dig: next, remind: null }
  return {
    dig: { ...next, reminded: true },
    remind: `You dug past a Knossos answer this turn (${next.knossos}, then ${next.lookups} lookups). If you established a structural fact, record it with annotate_component (kind note, evidence_paths), or say why not.`,
  }
}
