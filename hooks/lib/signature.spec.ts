import { describe, expect, it } from 'vitest'
import { callParts, signatureLines } from './signature'
import { cells } from './text'

describe("a call's parts", () => {
  it('splits the list at its own commas, never at one inside a type', () => {
    expect(callParts('importNotes(positionals: array, options: array, context: Knossos\\Cli\\CliCommandContext): int')).toEqual({
      name: 'importNotes',
      params: ['positionals: array', 'options: array', 'context: Knossos\\Cli\\CliCommandContext'],
      rest: ': int',
    })
    expect(callParts('f(a: array<string, int>, b: Closure(int, int): bool, c: {x: int, y: int}, d: [int, int])')?.params).toEqual(['a: array<string, int>', 'b: Closure(int, int): bool', 'c: {x: int, y: int}', 'd: [int, int]'])
    // An arrow's `>` closes nothing, a quoted comma splits nothing.
    expect(callParts("on(handler: (e: Event) => void, kind: 'a,b' | \"c,d\"): void")?.params).toEqual(['handler: (e: Event) => void', "kind: 'a,b' | \"c,d\""])
    // What a worker cut from the list stays its own entry; an optional or variadic one keeps its marks.
    expect(callParts('scan(root: string, limit?: number, ...rest: string[], …): Promise<void>')?.params).toEqual(['root: string', 'limit?: number', '...rest: string[]', '…'])
    expect(callParts('capture(order: App\\Order): ?App\\Receipt')?.rest).toBe(': ?App\\Receipt')
    expect(callParts('Checkout::capture(order)')).toEqual({ name: 'Checkout::capture', params: ['order'], rest: '' })
  })

  it('is none for a line that does not read as name(params) or name(params): type', () => {
    for (const line of ['not a call', '(a, b): int', 'f(a, b', 'f(a)(b)', 'f(a) -> int', 'f(): int', 'f(a, , b)', 'f(a: array<int)']) expect(callParts(line), line).toBeNull()
  })
})

describe('a signature in a column', () => {
  const LINE = 'importNotes(positionals: array, options: array, context: Knossos\\Cli\\CliCommandContext): int'

  it('stays one line where it fits', () => {
    expect(signatureLines(LINE, cells(LINE))).toEqual([LINE])
  })

  it('breaks one parameter a line, two cells in, its list closed with what it returns', () => {
    expect(signatureLines(LINE, cells(LINE) - 1)).toEqual(['importNotes(', '  positionals: array,', '  options: array,', '  context: Knossos\\Cli\\CliCommandContext,', '): int'])
    expect(signatureLines('scan(root: string, …): void', 20)).toEqual(['scan(', '  root: string,', '  …', '): void'])
  })

  it('breaks a parameter too long for its line under itself, and never past the width (twelve of them)', () => {
    const many = `handle(${Array.from({ length: 12 }, (_, i) => `argument${i}: Knossos\\Some\\Rather\\Long\\Namespace\\Type${i}`).join(', ')}, …): Knossos\\Result\\Answer`
    for (const width of [20, 30, 47, 60]) {
      const lines = signatureLines(many, width)
      for (const line of lines) expect(cells(line), `${width}: ${line}`).toBeLessThanOrEqual(width)
      // Nothing is cut: every parameter and the return type are all there.
      const joined = lines.map(l => l.trim()).join('')
      for (let i = 0; i < 12; i++) expect(joined.replace(/\s/g, ''), `${width}`).toContain(`argument${i}:Knossos\\Some\\Rather\\Long\\Namespace\\Type${i},`)
      expect(lines.at(-1), `${width}`).toMatch(/Answer$/)
    }
    expect(signatureLines(many, 60).filter(l => /^ {2}argument\d+:/.test(l))).toHaveLength(12)
  })

  it('wraps as words a line that does not parse, breaking a word too long for the column rather than cutting it', () => {
    expect(signatureLines('a line that is not a call at all', 12)).toEqual(['a line that', 'is not a', 'call at all'])
    const word = 'Knossos\\Cli\\Command\\MaintenanceCommand'
    expect(signatureLines(word, 16).join('')).toBe(word)
    for (const line of signatureLines(word, 16)) expect(cells(line)).toBeLessThanOrEqual(16)
  })
})
