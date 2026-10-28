import { describe, expect, it } from 'vitest'
import { argsOf } from './args'

describe('argsOf', () => {
  it('takes a directory, the editor and the theme with = or as the next argument', () => {
    expect(argsOf([])).toEqual({ dir: null, editor: null, theme: null })
    expect(argsOf(['/p', '--editor=code -g', '--theme=light'])).toEqual({ dir: '/p', editor: 'code -g', theme: 'light' })
    expect(argsOf(['--theme', 'dark', '--editor', 'zed', 'shop'])).toEqual({ dir: 'shop', editor: 'zed', theme: 'dark' })
  })

  it('refuses a theme that is none, an option it does not know, a value left out and a second directory', () => {
    expect(argsOf(['--theme=blue'])).toEqual({ error: '--theme must be dark or light, not blue' })
    expect(argsOf(['--port=1'])).toEqual({ error: 'unknown option --port=1' })
    expect(argsOf(['--editor='])).toEqual({ error: '--editor needs a value' })
    expect(argsOf(['--theme'])).toEqual({ error: '--theme needs a value' })
    expect(argsOf(['a', 'b'])).toEqual({ error: 'one directory at most, not a and b' })
  })
})
