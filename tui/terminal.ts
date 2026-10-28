/**
 * What the terminal pane does to the terminal it owns: the alternate screen,
 * a hidden cursor, the mouse and bracketed paste on, and raw input, all put
 * back by one `leave` however the process ends.
 */

/** Enters the alternate screen, hides the cursor, reports the mouse (press, motion, SGR coordinates) and brackets pastes. */
export const ENTER = '\x1b[?1049h\x1b[?25l\x1b[?1000h\x1b[?1003h\x1b[?1006h\x1b[?2004h'
/** Undoes {@link ENTER}, in the reverse order, and resets the colours. */
export const LEAVE = '\x1b[?2004l\x1b[?1006l\x1b[?1003l\x1b[?1000l\x1b[?25h\x1b[0m\x1b[?1049l'
/** Asks the terminal for its background colour (OSC 11); the reply arrives as input. */
export const ASK_BACKGROUND = '\x1b]11;?\x07'

/** `enter` takes the terminal; `leave` gives it back, and does so once however often it is called. */
export function terminalMode(o: { stdin: NodeJS.ReadStream; write(bytes: string): void }): { enter(): void; leave(): void } {
  let entered = false
  return {
    enter() {
      if (entered) return
      entered = true
      o.stdin.setRawMode(true)
      o.write(ENTER)
    },
    leave() {
      if (!entered) return
      entered = false
      o.write(LEAVE)
      o.stdin.setRawMode(false)
    },
  }
}
