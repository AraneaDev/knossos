import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import ts from 'typescript'
import { describe, expect, it } from 'vitest'

/**
 * One way to indent: a row reaches the body, a nested level or the cells
 * between two of its parts through `spacing.ts` (`gutter`, `atBody`,
 * `bodyRow`, `nest`, `apart` and the rest), never through spaces typed into a
 * string. A string that starts with two spaces, `' '.repeat`, or a direct
 * `spaces(GUTTER)` (or the margin's, the body's, a table's marker cells) is a
 * second mechanism, and the next change to the spacing would miss it.
 */
const HERE = new URL('.', import.meta.url).pathname
/** The spacing itself, and the text helpers it is built on. */
const OWNERS = new Set(['spacing.ts', 'text.ts'])
const sources = readdirSync(HERE).filter(f => f.endsWith('.ts') && !f.endsWith('.spec.ts') && !OWNERS.has(f))

/** Each typed indent in the source `text` of `file`, as `line: text`. */
function indents(file: string, text: string): string[] {
  const source = ts.createSourceFile(file, text, ts.ScriptTarget.Latest, true)
  const found: string[] = []
  const at = (node: ts.Node) => `${source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1}: ${node.getText(source).slice(0, 60)}`
  const visit = (node: ts.Node): void => {
    // A string, or the start of a template, that begins with two spaces.
    if ((ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node) || ts.isTemplateHead(node)) && /^ {2}/.test(node.text)) found.push(at(node))
    if (ts.isCallExpression(node)) {
      const callee = node.expression.getText(source)
      if (/^(['"`]) \1\.repeat$/.test(callee)) found.push(at(node))
      if (callee === 'spaces' && /^(GUTTER|MARGIN|BODY|MARK)\b/.test(node.arguments[0]?.getText(source) ?? '')) found.push(at(node))
    }
    ts.forEachChild(node, visit)
  }
  visit(source)
  return found
}

describe('one way to indent', () => {
  it('finds no indent typed as spaces in any module of the pane outside the spacing', () => {
    expect(sources.length).toBeGreaterThan(40)
    for (const file of sources) expect(indents(file, readFileSync(join(HERE, file), 'utf8')), file).toEqual([])
  })

  it('would find each kind of one', () => {
    const code = "const a = { text: '   find: ' }\nconst b = `  ${x}`\nconst c = ' · '\nconst d = ' '.repeat(n)\nconst e = spaces(GUTTER + 1)\nconst f = spaces(width)"
    expect(indents('probe.ts', code).map(hit => hit.split(':')[0])).toEqual(['1', '2', '4', '5'])
  })
})
