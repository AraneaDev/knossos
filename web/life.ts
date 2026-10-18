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
}

const messageOf = (error: unknown): string => (error instanceof Error ? error.message : String(error))

/**
 * `stop(code)`: the server closed (its watcher ended), then the lock removed,
 * then exit `code`. Asked again while that runs (a second Ctrl-C, or a close
 * that hangs), it waits no longer: the watcher is ended, the lock removed,
 * and it exits 130. Before the server listens there is nothing to close.
 * `crashed(error)` says why on stderr and stops with exit 1 (at once when
 * it was stopping already).
 */
export function ending(o: Ending): { stop(code: number): void; crashed(error: unknown): void } {
  let stopping = false
  const now = (code: number) => {
    o.stopWatcher()
    o.release()
    o.exit(code)
  }
  const stop = (code: number) => {
    if (stopping) return now(130)
    stopping = true
    const close = o.close
    if (close === null) return now(code)
    void close()
      .catch((error: unknown) => o.write(`knossos web: stopping: ${messageOf(error)}\n`))
      .finally(() => {
        o.release()
        o.exit(code)
      })
  }
  return {
    stop,
    crashed: error => {
      o.write(`knossos web: ${messageOf(error)}\n`)
      // A failure while it stops (in the close itself) waits no longer either, and still exits 1.
      if (stopping) return now(1)
      stop(1)
    },
  }
}
