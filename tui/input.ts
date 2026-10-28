/**
 * What a terminal sends on its input, as the events the pane's page already
 * understands: keys under the names the browser gives them, the mouse in
 * cells, a bracketed paste, and the reply to the background colour query.
 * Bytes arrive in chunks that may end inside a sequence, so {@link Decoder}
 * keeps what it cannot finish yet.
 */

/** A key press; `key` is a browser key name (`ArrowUp`, `Enter`, ...) or the character typed. */
export type KeyEvent = { kind: 'key'; key: string; ctrlKey: boolean; altKey: boolean; shiftKey: boolean }

/** A mouse report in 0-based cells; `by` is 1 or -1 for the wheel and 0 otherwise. */
export type MouseEvent = { kind: 'mouse'; action: 'down' | 'up' | 'move' | 'wheel'; button: number; by: number; row: number; column: number }

/** Everything the decoder can hand back. */
export type TermEvent = KeyEvent | MouseEvent | { kind: 'paste'; text: string } | { kind: 'background'; rgb: [number, number, number] }

const ESC = '\x1b'
const PASTE_START = '\x1b[200~'
const PASTE_END = '\x1b[201~'

/** The key a CSI final byte names, for the cursor keys. */
const CURSOR: Record<string, string> = { A: 'ArrowUp', B: 'ArrowDown', C: 'ArrowRight', D: 'ArrowLeft', H: 'Home', F: 'End' }

/** The SS3 finals of keys the pane has no use for (F1 to F4): known, so dropped rather than read as Alt+O. */
const SS3_UNUSED = new Set(['P', 'Q', 'R', 'S'])

/** The key a control byte above Ctrl-Z stands for, with Ctrl: 28 to 31. */
const CONTROL_SIGNS = ['\\', ']', '^', '_']

/** The key a `CSI <n> ~` sequence names. */
const TILDE: Record<string, string> = { '1': 'Home', '3': 'Delete', '4': 'End', '5': 'PageUp', '6': 'PageDown', '7': 'Home', '8': 'End' }

/** Whether `char` is a capital letter: typed with Shift. */
const upper = (char: string): boolean => char !== char.toLowerCase()

const key = (name: string, mods: { ctrl?: boolean; alt?: boolean; shift?: boolean } = {}): KeyEvent => ({
  kind: 'key',
  key: name,
  ctrlKey: mods.ctrl === true,
  altKey: mods.alt === true,
  shiftKey: mods.shift === true,
})

/** The modifiers an xterm parameter (1 + shift 1, alt 2, ctrl 4) stands for. */
const modsOf = (param: string | undefined): { ctrl: boolean; alt: boolean; shift: boolean } => {
  const bits = Math.max(0, Number(param ?? 1) - 1)
  return { shift: (bits & 1) !== 0, alt: (bits & 2) !== 0, ctrl: (bits & 4) !== 0 }
}

/** The colour in an OSC 11 reply (`rgb:rrrr/gggg/bbbb`, one to four hex digits each) as 0-255 channels; null for any other reply. */
function backgroundOf(body: string): [number, number, number] | null {
  const found = /^11;rgb:([0-9a-f]{1,4})\/([0-9a-f]{1,4})\/([0-9a-f]{1,4})$/i.exec(body)
  if (found === null) return null
  const channel = (hex: string) => Math.round((parseInt(hex, 16) / (16 ** hex.length - 1)) * 255)
  return [channel(found[1]!), channel(found[2]!), channel(found[3]!)]
}

/** Whether a background is dark or light: relative luminance above 0.5 is light. */
export const themeOfBackground = (rgb: [number, number, number]): 'dark' | 'light' => {
  const [r, g, b] = rgb.map(value => {
    const c = value / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  }) as [number, number, number]
  return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.5 ? 'light' : 'dark'
}

/** Turns chunks of terminal input into events. */
export class Decoder {
  private pending = ''
  private pasting = false

  /** Decodes `chunk` after whatever the last one left unfinished; a sequence cut short waits for the next chunk. */
  push(chunk: string): TermEvent[] {
    this.pending += chunk
    const out: TermEvent[] = []
    for (;;) {
      if (this.pasting) {
        const end = this.pending.indexOf(PASTE_END)
        if (end < 0) break
        out.push({ kind: 'paste', text: this.pending.slice(0, end) })
        this.pending = this.pending.slice(end + PASTE_END.length)
        this.pasting = false
        continue
      }
      if (this.pending === '') break
      const used = this.take(out)
      if (used === 0) break
      this.pending = this.pending.slice(used)
    }
    return out
  }

  /**
   * Called once input has been quiet for a moment: a lone ESC is the Escape key, an ESC with
   * only `[`, `O` or `]` after it is that key with Alt (an OSC that never ended gives the rest
   * back as keys), and any other unfinished sequence is dropped. A paste is left to {@link endPaste}.
   */
  flush(): TermEvent[] {
    if (this.pasting) return []
    const text = this.pending
    this.pending = ''
    if (text === ESC) return [key('Escape')]
    if (text === `${ESC}[` || text === `${ESC}O`) return [key(text[1]!, { alt: true, shift: text[1] === 'O' })]
    if (text.startsWith(`${ESC}]`)) return [key(']', { alt: true }), ...this.push(text.slice(2))]
    return []
  }

  /** Ends a paste whose end marker never came, after a long quiet: what it gathered is the paste, and input is read as keys again. */
  endPaste(): TermEvent[] {
    if (!this.pasting) return []
    this.pasting = false
    const text = this.pending
    this.pending = ''
    return [{ kind: 'paste', text }]
  }

  /** Decodes one event from the start of the pending input into `out`; the length it used, or 0 when more input is needed. */
  private take(out: TermEvent[]): number {
    const text = this.pending
    if (text[0] !== ESC) {
      const char = String.fromCodePoint(text.codePointAt(0)!)
      const code = char.charCodeAt(0)
      if (char === '\r' || char === '\n') out.push(key('Enter'))
      else if (char === '\t') out.push(key('Tab'))
      else if (char === '\x7f' || char === '\b') out.push(key('Backspace'))
      else if (code === 0) out.push(key(' ', { ctrl: true }))
      else if (code <= 26) out.push(key(String.fromCharCode(code + 96), { ctrl: true }))
      else if (code >= 28 && code <= 31) out.push(key(CONTROL_SIGNS[code - 28]!, { ctrl: true }))
      else if (code >= 32) out.push(key(char, { shift: upper(char) }))
      return char.length
    }
    if (text.length === 1) return 0
    const next = text[1]!
    if (next === '[') return this.csi(out)
    if (next === ']') return this.osc(out)
    if (next === 'O') {
      if (text.length < 3) return 0
      const named = CURSOR[text[2]!]
      if (named !== undefined) out.push(key(named))
      if (named !== undefined || SS3_UNUSED.has(text[2]!)) return 3
      // Not a key a terminal sends this way: Alt+O, typed just before what follows it.
      out.push(key('O', { alt: true, shift: true }))
      return 2
    }
    if (next === ESC) {
      out.push(key('Escape'))
      return 1
    }
    const char = String.fromCodePoint(text.codePointAt(1)!)
    out.push(key(char, { alt: true, shift: upper(char) }))
    return 1 + char.length
  }

  /** A `CSI` sequence: parameters, then one final byte. */
  private csi(out: TermEvent[]): number {
    const text = this.pending
    if (PASTE_START.startsWith(text) && text.length < PASTE_START.length) return 0
    if (text.startsWith(PASTE_START)) {
      this.pasting = true
      return PASTE_START.length
    }
    const found = /^..([<=>?]?[0-9;:]*)([ -/]*[@-~])?/s.exec(text)!
    const final = found[2]
    if (final === undefined) {
      // Cut short, it waits for the rest; broken by a byte no sequence has there, it is dropped up to that byte.
      const stop = /^..[<=>?]?[0-9;:]*[ -/]*/s.exec(text)![0].length
      return stop < text.length ? stop : 0
    }
    const params = found[1]!
    const used = found[0].length
    if (params.startsWith('<') && (final === 'M' || final === 'm')) {
      const fields = params.slice(1).split(';')
      // A malformed report (not three whole numbers), or the sideways wheel (66, 67): no event.
      if (fields.length !== 3 || !fields.every(field => /^\d+$/.test(field))) return used
      const [cb, x, y] = fields.map(Number) as [number, number, number]
      // The extra buttons (128 and up) mean nothing here.
      if ((cb & 128) !== 0) return used
      if ((cb & 64) !== 0 && (cb & 2) !== 0) return used
      const wheel = (cb & 64) !== 0
      out.push({
        kind: 'mouse',
        action: wheel ? 'wheel' : (cb & 32) !== 0 ? 'move' : final === 'M' ? 'down' : 'up',
        button: cb & 3,
        by: wheel ? ((cb & 1) !== 0 ? 1 : -1) : 0,
        row: y - 1,
        column: x - 1,
      })
      return used
    }
    const parts = params.split(';')
    if (final === 'Z') out.push(key('Tab', { shift: true }))
    else if (final === '~') {
      const named = TILDE[parts[0]!]
      if (named !== undefined) out.push(key(named, modsOf(parts[1])))
    } else if (CURSOR[final] !== undefined) out.push(key(CURSOR[final], modsOf(parts[1])))
    return used
  }

  /** An `OSC` string, ended by BEL or `ESC \`: only the background colour reply is kept. */
  private osc(out: TermEvent[]): number {
    const text = this.pending
    const bell = text.indexOf('\x07')
    const stop = text.indexOf('\x1b\\')
    const end = bell >= 0 && (stop < 0 || bell < stop) ? bell : stop
    if (end < 0) return 0
    const rgb = backgroundOf(text.slice(2, end))
    if (rgb !== null) out.push({ kind: 'background', rgb })
    return end + (end === bell ? 1 : 2)
  }
}
