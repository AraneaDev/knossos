/**
 * How `knossos web` ends, whatever ends it: a signal, a second signal while
 * the first is still stopping it, or a failure nothing caught. Each way ends
 * the watcher and removes the lock before the process exits, so neither is
 * left behind for the next start to find.
 */

/** What stopping needs: the server's close (null before it listens), the watcher's end without waiting, the lock's release, the exit, and stderr. */
export type Ending = {
  close: (() => Promise<void>) | null
  stopWatcher(): void
  release(): void
  exit(code: number): void
  write(text: string): void
  /** What the messages are prefixed with: `knossos web` by default. */
  name?: string
  /** The time in ms, `Date.now` by default: what tells a repeated signal from a second request. */
  clock?: () => number
}

/**
 * How soon the same signal again is the same request: a terminal signals the whole
 * foreground group, so a process under `bin/knossos` gets it directly and again as PHP
 * passes it on, a moment later.
 */
export const REPEAT_MS = 500

const messageOf = (error: unknown): string => (error instanceof Error ? error.message : String(error))

/**
 * `stop(code)`: the server closed (its watcher ended), then the lock removed,
 * then exit `code`. Asked again while that runs (a second Ctrl-C, or a close
 * that hangs), it waits no longer: the watcher is ended, the lock removed,
 * and it exits 130. The signal that asked (`signal`) again within
 * {@link REPEAT_MS} is the same request delivered twice, and is let be.
 * Before the server listens there is nothing to close.
 * `crashed(error)` says why on stderr and stops with exit 1 (at once when
 * it was stopping already).
 */
export function ending(o: Ending): { stop(code: number, signal?: string): void; crashed(error: unknown): void } {
  const name = o.name ?? 'knossos web'
  const clock = o.clock ?? Date.now
  let stopping = false
  /** The signal that started the stop, and when. */
  let asked: { signal: string | undefined; at: number } = { signal: undefined, at: 0 }
  const now = (code: number) => {
    o.stopWatcher()
    o.release()
    o.exit(code)
  }
  const stop = (code: number, signal?: string) => {
    if (stopping) {
      if (signal !== undefined && signal === asked.signal && clock() - asked.at < REPEAT_MS) return
      return now(130)
    }
    stopping = true
    asked = { signal, at: clock() }
    const close = o.close
    if (close === null) return now(code)
    void close()
      .catch((error: unknown) => o.write(`${name}: stopping: ${messageOf(error)}\n`))
      .finally(() => {
        o.release()
        o.exit(code)
      })
  }
  return {
    stop,
    crashed: error => {
      o.write(`${name}: ${messageOf(error)}\n`)
      // A failure while it stops (in the close itself) waits no longer either, and still exits 1.
      if (stopping) return now(1)
      stop(1)
    },
  }
}
