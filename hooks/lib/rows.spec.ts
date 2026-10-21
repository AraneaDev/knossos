import { describe, expect, it } from 'vitest'
import { cells, pathParts, rowHeight, rowsHeight, tableRow, tableSpec } from './rows'
import { editorCommand } from './editor'

const PATH = 'src/Query/Drift/GitDriftOracle.php'

describe('a path in two tones', () => {
  it('keeps the whole path when it fits, split at its last slash', () => {
    expect(pathParts(PATH, 60)).toEqual({ dir: 'src/Query/Drift/', base: 'GitDriftOracle.php' })
    expect(pathParts('README.md', 20)).toEqual({ dir: '', base: 'README.md' })
  })

  it('cuts the middle of the directory, by whole folders first, never the file name', () => {
    expect(pathParts(PATH, 30)).toEqual({ dir: 'src/…/Drift/', base: 'GitDriftOracle.php' })
    expect(pathParts(PATH, 27)).toEqual({ dir: '…/Drift/', base: 'GitDriftOracle.php' })
    expect(pathParts(PATH, 22)).toEqual({ dir: 'sr…/', base: 'GitDriftOracle.php' })
    expect(pathParts(PATH, 20)).toEqual({ dir: '…/', base: 'GitDriftOracle.php' })
    // Only a file name wider than the width is cut, at its end.
    expect(pathParts(PATH, 12)).toEqual({ dir: '', base: 'GitDriftOra…' })
  })

  it('never draws wider than the width it has', () => {
    for (let width = 1; width <= 40; width++) {
      const { dir, base } = pathParts(PATH, width)
      expect(cells(dir) + cells(base), `${width}`).toBeLessThanOrEqual(width)
      if (width >= cells('GitDriftOracle.php') + 2) expect(base).toBe('GitDriftOracle.php')
    }
  })

  it('draws the directory dim and the file name in the text tone in a table, padded to the column', () => {
    const spec = tableSpec(40, [PATH], [], [3])
    const row = tableRow('r', { name: PATH, boundary: null, values: [7], max: 7, path: true, press: 'row:0' }, spec)
    const dir = row.segments.find(s => s.text.endsWith('/'))
    const base = row.segments.find(s => s.press !== undefined)
    expect(dir?.dim).toBe(true)
    expect(base?.text).toBe('GitDriftOracle.php')
    expect(base?.dim).toBeUndefined()
    expect(base?.press).toEqual({ id: 'row:0', label: PATH })
  })
})

describe('the rows a row takes on screen', () => {
  it('is one for a row of text, and one per changed or context line for a diff element, its @@ header drawn as no row', () => {
    expect(rowHeight({ key: 'a', segments: [{ text: 'x' }] })).toBe(1)
    // As the engine drew it in a live session: a hunk of seven lines took seven rows, the header none.
    const hunk = { key: 'h', segments: [], code: { source: '@@ -5,6 +5,7 @@\n a\n b\n c\n+d\n e\n f\n g', path: 'x.ts' } }
    expect(rowHeight(hunk)).toBe(7)
    expect(rowsHeight([hunk, { key: 'b', segments: [{ text: 'y' }] }])).toBe(8)
  })
})

describe('display width', () => {
  it('counts wide characters as two cells', () => expect(cells('漢字')).toBe(4))
  it('counts an emoji as two cells', () => expect(cells('a😀')).toBe(3))
  it('counts a combining mark as nothing', () => expect(cells('e\u0301')).toBe(1))
  it('never draws a wide path wider than the width, and never splits a grapheme', () => {
    const path = 'src/漢字/データ/ファイル👩\u200d💻.ts'
    for (let width = 1; width <= 40; width++) {
      const { dir, base } = pathParts(path, width)
      const drawn = dir + base
      expect(cells(drawn)).toBeLessThanOrEqual(width)
      expect(drawn.includes('\u200d') ? drawn.includes('👩\u200d💻') : true).toBe(true)
    }
  })
})

describe('the editor command', () => {
  const at = { path: '/work/app/src/My Router.php', line: 42 }
  it('fills the file and line into the default, one argument each, spaces in the path intact', () => {
    expect(editorCommand(undefined, at)).toEqual(['code', '-g', '/work/app/src/My Router.php:42'])
    expect(editorCommand('', at)).toEqual(['code', '-g', '/work/app/src/My Router.php:42'])
    expect(editorCommand('   ', at)).toEqual(['code', '-g', '/work/app/src/My Router.php:42'])
  })

  it('fills a template of its own, a quoted program whole, a place without a line as the path alone', () => {
    expect(editorCommand('idea --line {line} {file}', at)).toEqual(['idea', '--line', '42', '/work/app/src/My Router.php'])
    expect(editorCommand('"/opt/Sublime Text/subl" {file}:{line}', at)).toEqual(['/opt/Sublime Text/subl', '/work/app/src/My Router.php:42'])
    expect(editorCommand('zed {file}:{line}', { path: '/a.ts', line: null })).toEqual(['zed', '/a.ts'])
    expect(editorCommand('idea --line {line} {file}', { path: '/a.ts', line: null })).toEqual(['idea', '--line', '1', '/a.ts'])
  })

  it('puts the place last when the template names no file', () => {
    expect(editorCommand('cursor -g', at)).toEqual(['cursor', '-g', '/work/app/src/My Router.php:42'])
    expect(editorCommand('cursor -g', { path: '/a.ts', line: null })).toEqual(['cursor', '-g', '/a.ts'])
  })

  it('falls back to the default for a value that is not a usable string', () => {
    for (const odd of [42, null, ['code'], '"unclosed {file}', '{file}']) expect(editorCommand(odd, at)[0], JSON.stringify(odd)).toBe('code')
  })
})
