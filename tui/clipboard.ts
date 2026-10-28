/**
 * Reaching the clipboard from a terminal: the terminal's own escape sequence,
 * or the program the machine has for it.
 */

/** The escape sequence that asks the terminal to put `text` on the clipboard (OSC 52). */
export const osc52 = (text: string): string => `\x1b]52;c;${Buffer.from(text).toString('base64')}\x07`

/**
 * The command (program and arguments) that copies its input to the clipboard on
 * `platform` with the environment `env`: the first program that fits where it
 * runs and that `onPath` finds, else null. `wl-copy` needs a Wayland display,
 * `xclip` an X display, `pbcopy` a Mac, and `clip.exe` Windows, from WSL too.
 */
export function clipboardCommand(platform: NodeJS.Platform, env: Record<string, string | undefined>, onPath: (name: string) => boolean): string[] | null {
  const set = (name: string) => (env[name] ?? '') !== ''
  const unix = platform !== 'win32' && platform !== 'darwin'
  const wsl = platform === 'linux' && (set('WSL_DISTRO_NAME') || set('WSL_INTEROP'))
  const wanted = [
    ...(unix && set('WAYLAND_DISPLAY') ? [['wl-copy']] : []),
    ...(unix && set('DISPLAY') ? [['xclip', '-selection', 'clipboard']] : []),
    ...(platform === 'darwin' ? [['pbcopy']] : []),
    ...(platform === 'win32' || wsl ? [['clip.exe']] : []),
  ]
  return wanted.find(command => onPath(command[0]!)) ?? null
}
