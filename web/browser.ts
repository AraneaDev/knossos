/**
 * Opening the pane's URL in the person's browser: the commands to try, in
 * turn, until one starts. Under WSL `wslview` (or Windows' own `start`)
 * reaches the Windows browser, where `xdg-open` finds none.
 */
import { spawn } from 'node:child_process'

/** The commands that open `url` on `platform`, in the order to try them. */
export function browserCommands(platform: NodeJS.Platform, url: string): string[][] {
  const windows = ['cmd.exe', '/c', 'start', '""', url]
  if (platform === 'darwin') return [['open', url]]
  if (platform === 'win32') return [windows]
  return [['wslview', url], ['xdg-open', url], windows]
}

/** Opens `url` with the first command that runs and exits 0; false when none did. */
export async function openBrowser(url: string, platform: NodeJS.Platform = process.platform): Promise<boolean> {
  for (const [command, ...args] of browserCommands(platform, url)) {
    const opened = await new Promise<boolean>(resolve => {
      const child = spawn(command!, args, { stdio: 'ignore', windowsVerbatimArguments: command === 'cmd.exe' })
      child.on('error', () => resolve(false))
      child.on('exit', code => resolve(code === 0))
    })
    if (opened) return true
  }
  return false
}
