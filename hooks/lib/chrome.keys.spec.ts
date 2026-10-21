/**
 * The key list `h` opens and the key table in the pane's documentation say
 * the same thing: the same keys in the same order, each with the same words.
 * The documentation may link a phrase or set a key in code; the words stay
 * the pane's.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { keysBlock } from './chrome'

const DOCS = fileURLToPath(new URL('../../docs/claude-code/pane/index.md', import.meta.url))

/** The pane's key list for the Claude Code host, one [key, action] a key, on a pane wide enough that nothing wraps. */
function paneRows(): [string, string][] {
  const section = keysBlock().make(10_000, 10_000)
  expect(section).not.toBeNull()
  const body = section!.body as { segments: { text: string }[] }[]
  return body.map(row => [row.segments[1]!.text.trim(), row.segments[2]!.text] as [string, string]).filter(([key]) => key !== '')
}

/** The rows of the documentation's key table, with links reduced to their text and code marks dropped. */
function docRows(): [string, string][] {
  const text = readFileSync(DOCS, 'utf8')
  const start = text.indexOf('| key ')
  expect(start).toBeGreaterThan(-1)
  const plain = (cell: string): string => cell.replace(/\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/`/g, '').trim()
  return text
    .slice(start)
    .split('\n')
    .slice(2)
    .filter((_, i, lines) => lines.slice(0, i + 1).every(line => line.startsWith('|')))
    .map(line => line.split('|').slice(1, -1).map(plain) as [string, string])
}

describe('the key list and the key table in the documentation', () => {
  it('name the same keys in the same order, each with the same words', () => {
    const pane = paneRows()
    expect(pane.length).toBeGreaterThan(15)
    expect(docRows()).toEqual(pane)
  })

  it('say the back press leaves a route as well as a detail', () => {
    expect(paneRows().find(([key]) => key === 'b')?.[1]).toBe('back from a detail or a route')
  })
})
