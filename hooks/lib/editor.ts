/**
 * Opening a place in the editor: the command the pane runs for it, and the
 * press that opens a file's detail. Pure; the mod spawns the command.
 */
import { locText } from './paths'
import type { Loc, Segment } from './types'

/** The editor command the pane runs when no other is set: VS Code's, which Cursor and the other forks answer to too. */
export const DEFAULT_EDITOR = 'code -g {file}:{line}'

/**
 * The command that opens `loc` in an editor, as its arguments: `template`
 * (the plugin's `editor` setting) split on spaces, a double-quoted part kept
 * whole, with `{file}` and `{line}` filled in. A place with no line drops a
 * `:{line}` that follows the file, so it opens as the path alone, and fills
 * a `{line}` standing on its own with the first. A template that names no
 * `{file}` gets the place appended. A
 * value that is not a usable command (not a string, blank, an unclosed
 * quote, or a placeholder where the program goes) falls back to
 * {@link DEFAULT_EDITOR}. The path is one argument whatever it holds: no
 * shell reads it.
 */
export function editorCommand(template: unknown, loc: Loc): string[] {
  const parts = typeof template === 'string' ? splitCommand(template) : null
  const usable = parts !== null && parts.length > 0 && !parts[0]!.includes('{') ? parts : splitCommand(DEFAULT_EDITOR)!
  const line = String(loc.line ?? 1)
  const named = usable.some(part => part.includes('{file}'))
  const filled = usable.map(part => (loc.line === null ? part.replaceAll('{file}:{line}', '{file}') : part).replaceAll('{file}', loc.path).replaceAll('{line}', line))
  return named ? filled : [...filled, locText(loc)]
}

/** `text` split on spaces with double-quoted parts kept whole, or null for an unclosed quote. */
function splitCommand(text: string): string[] | null {
  const parts: string[] = []
  for (const match of text.matchAll(/"([^"]*)"|(\S+)/g)) {
    if (match[2] !== undefined && match[2].includes('"')) return null
    parts.push(match[1] ?? match[2]!)
  }
  return parts
}

/** The press that opens a file's detail: the file's absolute path follows it. */
export const FILE_PRESS = 'file:'

/** A segment drawing `text` as a press that opens the detail of `loc`'s file, or as plain text when there is no place. */
export function opensFile(text: string, loc: Loc | null, style: Omit<Segment, 'text' | 'press'> = {}): Segment {
  return loc === null || text === '' ? { ...style, text } : { ...style, text, press: { id: `${FILE_PRESS}${loc.path}`, label: text } }
}
