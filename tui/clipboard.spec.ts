import { describe, expect, it } from 'vitest'
import { clipboardCommand, osc52 } from './clipboard'

describe('osc52', () => {
  it('asks the terminal to copy the text, base64 encoded', () => {
    expect(osc52('hi')).toBe('\x1b]52;c;aGk=\x07')
  })
})

describe('clipboardCommand', () => {
  it('picks the first program on the path', () => {
    expect(clipboardCommand('linux', n => n === 'xclip')).toEqual(['xclip', '-selection', 'clipboard'])
    expect(clipboardCommand('linux', () => true)).toEqual(['wl-copy'])
    expect(clipboardCommand('darwin', n => n === 'pbcopy')).toEqual(['pbcopy'])
  })

  it('finds clip.exe on linux, as WSL has it', () => {
    expect(clipboardCommand('linux', n => n === 'clip.exe')).toEqual(['clip.exe'])
  })

  it('is null with nothing on the path', () => {
    expect(clipboardCommand('linux', () => false)).toBeNull()
  })

  it('looks only for clip.exe on windows', () => {
    expect(clipboardCommand('win32', () => true)).toEqual(['clip.exe'])
  })
})
