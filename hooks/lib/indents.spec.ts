import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import ts from 'typescript'
import { describe, expect, it } from 'vitest'

/**
 * One way to indent: a row reaches the body, a nested level or the cells
 * between two of its parts through `spacing.ts` (`gutter`, `atBody`,
 * `bodyRow`, `nest`, `apart` and the rest), never through spaces typed into a
 * string. A string that starts with two spaces, `' '.repeat`, a direct
 * `spaces(GUTTER)` (or the margin's, the body's, a table's marker cells), a
 * bare number of spaces (`spaces(3)`), or an indent, gap or margin parameter
 * defaulted to a bare number (`indent = 3`) is a second mechanism, and the next
 * change to the spacing would miss it. Every module under `hooks/lib` is read,
 * its folders too.
 */
const HERE = new URL('.', import.meta.url).pathname
/** The spacing itself, and the text helpers it is built on. */
const OWNERS = new Set(['spacing.ts', 'text.ts'])
/** Every module under `hooks/lib`, its folders too, as a path from it. */
const modules = (dir: string, at = ''): string[] =>
  readdirSync(join(dir, at), { withFileTypes: true }).flatMap(e => (e.isDirectory() ? modules(dir, join(at, e.name)) : e.name.endsWith('.ts') && !e.name.endsWith('.spec.ts') ? [join(at, e.name)] : []))
const sources = modules(HERE).filter(f => !OWNERS.has(f))

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
      // The spacing's own cells, or a bare number of them: a width says what it is by its name.
      if (callee === 'spaces' && /^(GUTTER|MARGIN|BODY|MARK)\b|^\d+$/.test(node.arguments[0]?.getText(source) ?? '')) found.push(at(node))
    }
    // An indent, a gap or a margin defaulted to a bare number of cells (none, 0, is no indent).
    if (ts.isParameter(node) && node.initializer !== undefined && ts.isNumericLiteral(node.initializer) && node.initializer.text !== '0' && /indent|gap|pad|margin|gutter|inset/i.test(node.name.getText(source))) found.push(at(node))
    ts.forEachChild(node, visit)
  }
  visit(source)
  return found
}

describe('one way to indent', () => {
  it('finds no indent typed as spaces in any module of the pane outside the spacing', () => {
    expect(sources.length).toBeGreaterThan(40)
    expect(sources.some(f => f.includes('/')), 'the folders are scanned too').toBe(true)
    for (const file of sources) expect(indents(file, readFileSync(join(HERE, file), 'utf8')), file).toEqual([])
  })

  it('would find each kind of one', () => {
    const code = "const a = { text: '   find: ' }\nconst b = `  ${x}`\nconst c = ' · '\nconst d = ' '.repeat(n)\nconst e = spaces(GUTTER + 1)\nconst f = spaces(width)\nconst g = spaces(3)\nfunction h(rows: number, indent = 3) {}\nfunction k(count = 3) {}"
    expect(indents('probe.ts', code).map(hit => hit.split(':')[0])).toEqual(['1', '2', '4', '5', '7', '8'])
  })
})
