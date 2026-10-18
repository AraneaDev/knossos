import { describe, expect, it } from 'vitest'
import { argsOf } from './args'

describe('argsOf', () => {
  it('takes a directory, the port and the editor with = or as the next argument, and --no-open', () => {
    expect(argsOf([])).toEqual({ dir: null, port: null, open: true, editor: null })
    expect(argsOf(['~/shop', '--port=8123', '--no-open', '--editor=idea --line {line} {file}'])).toEqual({ dir: '~/shop', port: 8123, open: false, editor: 'idea --line {line} {file}' })
    expect(argsOf(['--port', '8123', '--editor', 'zed', 'shop'])).toEqual({ dir: 'shop', port: 8123, open: true, editor: 'zed' })
  })

  it('refuses an option it does not know, a value left out, a second directory, and a port that is none', () => {
    expect(argsOf(['--open'])).toEqual({ error: 'unknown option --open' })
    expect(argsOf(['-p', '8123'])).toEqual({ error: 'unknown option -p' })
    expect(argsOf(['--port'])).toEqual({ error: '--port needs a value' })
    expect(argsOf(['--editor='])).toEqual({ error: '--editor needs a value' })
    expect(argsOf(['a', 'b'])).toEqual({ error: 'one directory at most, not a and b' })
    expect(argsOf(['--port=80x'])).toEqual({ error: '--port must be a port number, not 80x' })
    expect(argsOf(['--port=70000'])).toEqual({ error: '--port must be a port number, not 70000' })
  })
})
