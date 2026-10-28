import { describe, expect, it } from 'vitest'
import { Decoder, themeOfBackground } from './input'
import type { KeyEvent, TermEvent } from './input'

const key = (name: string, mods: Partial<Pick<KeyEvent, 'ctrlKey' | 'altKey' | 'shiftKey'>> = {}): KeyEvent => ({ kind: 'key', key: name, ctrlKey: false, altKey: false, shiftKey: false, ...mods })
const decode = (text: string): TermEvent[] => new Decoder().push(text)

describe('Decoder keys', () => {
  it('reads plain and named keys', () => {
    expect(decode('a')).toEqual([key('a')])
    expect(decode('\x1b[A')).toEqual([key('ArrowUp')])
    expect(decode('\x1bOB')).toEqual([key('ArrowDown')])
    expect(decode('\r')).toEqual([key('Enter')])
    expect(decode('\t')).toEqual([key('Tab')])
    expect(decode('\x1b[Z')).toEqual([key('Tab', { shiftKey: true })])
    expect(decode('\x7f')).toEqual([key('Backspace')])
    expect(decode('\x1b[3~\x1b[5~\x1b[6~\x1b[H\x1b[F')).toEqual([key('Delete'), key('PageUp'), key('PageDown'), key('Home'), key('End')])
    expect(decode('\x1b[1;5C')).toEqual([key('ArrowRight', { ctrlKey: true })])
  })

  it('reads a control byte as ctrl with its letter, and ESC before a character as alt', () => {
    expect(decode('\x03')).toEqual([key('c', { ctrlKey: true })])
    expect(decode('\x1bx')).toEqual([key('x', { altKey: true })])
  })

  it('reads several keys and a character beyond the basic plane in one chunk', () => {
    expect(decode('ab\u{1f600}')).toEqual([key('a'), key('b'), key('\u{1f600}')])
  })

  it('keeps a sequence cut short for the next chunk', () => {
    const decoder = new Decoder()
    expect(decoder.push('\x1b[')).toEqual([])
    expect(decoder.push('B')).toEqual([key('ArrowDown')])
  })

  it('turns a lone ESC into Escape on flush, and drops a sequence that never finished', () => {
    const decoder = new Decoder()
    expect(decoder.push('\x1b')).toEqual([])
    expect(decoder.flush()).toEqual([key('Escape')])
    expect(decoder.push('\x1b[')).toEqual([])
    expect(decoder.flush()).toEqual([])
    expect(decoder.push('a')).toEqual([key('a')])
  })

  it('reads two ESC bytes as Escape and goes on', () => {
    expect(decode('\x1b\x1b[A')).toEqual([key('Escape'), key('ArrowUp')])
  })
})

describe('Decoder mouse', () => {
  it('reads SGR reports as cells counted from 0', () => {
    expect(decode('\x1b[<0;5;3M')).toEqual([{ kind: 'mouse', action: 'down', button: 0, by: 0, row: 2, column: 4 }])
    expect(decode('\x1b[<0;5;3m')).toEqual([{ kind: 'mouse', action: 'up', button: 0, by: 0, row: 2, column: 4 }])
    expect(decode('\x1b[<35;5;3M')).toEqual([{ kind: 'mouse', action: 'move', button: 3, by: 0, row: 2, column: 4 }])
    expect(decode('\x1b[<64;1;1M')).toEqual([{ kind: 'mouse', action: 'wheel', button: 0, by: -1, row: 0, column: 0 }])
    expect(decode('\x1b[<66;1;1M')).toEqual([])
    expect(decode('\x1b[<67;1;1M')).toEqual([])
    expect(decode('\x1b[<128;1;1M')).toEqual([])
    expect(decode('\x1b[<129;1;1m')).toEqual([])
    expect(decode('\x1b[<;5;M')).toEqual([])
    expect(decode('\x1b[<1:2;3;4M')).toEqual([])
    expect(decode('\x1b[<65;1;1M')).toEqual([{ kind: 'mouse', action: 'wheel', button: 1, by: 1, row: 0, column: 0 }])
  })
})

describe('Decoder paste', () => {
  it('gives a bracketed paste as one event, keeping its newlines', () => {
    expect(decode('\x1b[200~ab\ncd\x1b[201~')).toEqual([{ kind: 'paste', text: 'ab\ncd' }])
  })

  it('waits for the end of a paste, also when a marker is split', () => {
    const decoder = new Decoder()
    expect(decoder.push('\x1b[20')).toEqual([])
    expect(decoder.push('0~ab\x1b[20')).toEqual([])
    expect(decoder.push('1~c')).toEqual([{ kind: 'paste', text: 'ab' }, key('c')])
  })
})

describe('background reply', () => {
  it('reads an OSC 11 reply ended by BEL or ST as 0-255 channels', () => {
    expect(decode('\x1b]11;rgb:ffff/ffff/ffff\x07')).toEqual([{ kind: 'background', rgb: [255, 255, 255] }])
    expect(decode('\x1b]11;rgb:00/80/ff\x1b\\')).toEqual([{ kind: 'background', rgb: [0, 128, 255] }])
  })

  it('ignores another OSC string and waits for a split one', () => {
    expect(decode('\x1b]0;title\x07x')).toEqual([key('x')])
    const decoder = new Decoder()
    expect(decoder.push('\x1b]11;rgb:0000/00')).toEqual([])
    expect(decoder.push('00/0000\x07')).toEqual([{ kind: 'background', rgb: [0, 0, 0] }])
  })

  it('names the theme by luminance', () => {
    expect(themeOfBackground([255, 255, 255])).toBe('light')
    expect(themeOfBackground([0, 0, 0])).toBe('dark')
    expect(themeOfBackground([30, 30, 46])).toBe('dark')
  })
})
