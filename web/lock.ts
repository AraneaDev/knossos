/**
 * One `knossos web` per project: the server that runs for a project holds a
 * lock file in the data directory, `web/<sha256 of the project's real
 * path>.json`, holding its pid and URL. A second start finds it and points
 * at the running server instead of starting another. A lock whose process
 * is gone (killed, or its machine restarted) holds nothing: the next start
 * takes it over.
 */
import { createHash } from 'node:crypto'
import { mkdirSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

export type Claim = { kind: 'mine'; record(url: string): void; release(): void } | { kind: 'running'; url: string }

/** The lock file of the project at `root`, by its real path, so a link to it is the same project. */
export function lockFile(dataDir: string, root: string): string {
  let real = root
  try {
    real = realpathSync(root)
  } catch {
    // A project that is not there has only the path it was given.
  }
  return join(dataDir, 'web', `${createHash('sha256').update(real).digest('hex')}.json`)
}

/** Whether a process `pid` runs: one this user may not signal runs too. */
function alive(pid: unknown): boolean {
  if (typeof pid !== 'number' || !Number.isInteger(pid) || pid <= 0) return false
  try {
    process.kill(pid, 0)
    return true
  } catch (error) {
    return (error as NodeJS.ErrnoException).code === 'EPERM'
  }
}

/** The lock's `{ pid, url }`, or null for none or one that cannot be read. */
function held(file: string): { pid: unknown; url: unknown } | null {
  try {
    const parsed: unknown = JSON.parse(readFileSync(file, 'utf8'))
    return parsed !== null && typeof parsed === 'object' ? (parsed as { pid: unknown; url: unknown }) : null
  } catch {
    return null
  }
}

/**
 * The project at `root` for this process (`mine`: `record` its URL once the
 * server listens, `release` when it stops), or the URL of the server that
 * already runs for it. The file is created exclusively, so of two starts at
 * once only one takes it.
 */
export function claim(dataDir: string, root: string): Claim {
  const file = lockFile(dataDir, root)
  mkdirSync(join(dataDir, 'web'), { recursive: true })
  const write = (url: string, flag: 'wx' | 'w') => writeFileSync(file, JSON.stringify({ pid: process.pid, url }), { flag })
  for (let attempt = 0; attempt < 2; attempt++) {
    try {
      write('', 'wx')
      let released = false
      return {
        kind: 'mine',
        record: url => write(url, 'w'),
        release: () => {
          if (released) return
          released = true
          rmSync(file, { force: true })
        },
      }
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code !== 'EEXIST') throw error
    }
    const other = held(file)
    if (other !== null && other.pid !== process.pid && alive(other.pid)) return { kind: 'running', url: typeof other.url === 'string' ? other.url : '' }
    // Its process is gone: the lock is stale.
    rmSync(file, { force: true })
  }
  throw new Error(`could not take the lock ${file}`)
}
