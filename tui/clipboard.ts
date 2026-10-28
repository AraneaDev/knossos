/**
 * Reaching the clipboard from a terminal: the terminal's own escape sequence,
 * or the program the machine has for it.
 */

/** The escape sequence that asks the terminal to put `text` on the clipboard (OSC 52). */
export const osc52 = (text: string): string => `\x1b]52;c;${Buffer.from(text).toString('base64')}\x07`

/** The programs that copy to the clipboard, in the order tried. `clip.exe` is Windows' own, reachable from WSL. */
const COMMANDS: string[][] = [['wl-copy'], ['xclip', '-selection', 'clipboard'], ['pbcopy'], ['clip.exe']]

/** The command (program and arguments) that copies its input to the clipboard on `platform`: the first of the known ones `onPath` finds, else null. */
export function clipboardCommand(platform: NodeJS.Platform, onPath: (name: string) => boolean): string[] | null {
  const wanted = platform === 'win32' ? [['clip.exe']] : COMMANDS
  return wanted.find(command => onPath(command[0]!)) ?? null
}
