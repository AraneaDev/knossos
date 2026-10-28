import { describe, expect, it } from 'vitest'
import { clipboardCommand, osc52 } from './clipboard'

describe('osc52', () => {
  it('asks the terminal to copy the text, base64 encoded', () => {
    expect(osc52('hi')).toBe('\x1b]52;c;aGk=\x07')
  })
})

describe('clipboardCommand', () => {
  const all = () => true

  it('takes wl-copy only with a Wayland display, and before xclip', () => {
    expect(clipboardCommand('linux', { WAYLAND_DISPLAY: 'wayland-0', DISPLAY: ':0' }, all)).toEqual(['wl-copy'])
    expect(clipboardCommand('linux', { DISPLAY: ':0' }, all)).toEqual(['xclip', '-selection', 'clipboard'])
  })

  it('takes xclip only with an X display', () => {
    expect(clipboardCommand('linux', { WAYLAND_DISPLAY: 'wayland-0' }, n => n === 'xclip')).toBeNull()
    expect(clipboardCommand('linux', { WAYLAND_DISPLAY: 'wayland-0', DISPLAY: ':0' }, n => n === 'xclip')).toEqual(['xclip', '-selection', 'clipboard'])
  })

  it('takes pbcopy on a Mac, whatever the displays say', () => {
    expect(clipboardCommand('darwin', { DISPLAY: ':0' }, all)).toEqual(['pbcopy'])
  })

  it('takes clip.exe on WSL, after a display program WSLg provides', () => {
    expect(clipboardCommand('linux', { WSL_DISTRO_NAME: 'Ubuntu' }, all)).toEqual(['clip.exe'])
    expect(clipboardCommand('linux', { WSL_INTEROP: '/run/WSL/1_interop' }, all)).toEqual(['clip.exe'])
    expect(clipboardCommand('linux', { WSL_DISTRO_NAME: 'Ubuntu', WAYLAND_DISPLAY: 'wayland-0' }, n => n === 'clip.exe')).toEqual(['clip.exe'])
  })

  it('is null on a plain Linux console, even with every program on the path', () => {
    expect(clipboardCommand('linux', {}, all)).toBeNull()
    expect(clipboardCommand('linux', { DISPLAY: '' }, all)).toBeNull()
  })

  it('is null when the fitting program is not on the path', () => {
    expect(clipboardCommand('darwin', {}, () => false)).toBeNull()
  })

  it('takes clip.exe on Windows', () => {
    expect(clipboardCommand('win32', { DISPLAY: ':0' }, all)).toEqual(['clip.exe'])
  })
})
