import { describe, expect, it } from 'vitest'
import { DEFAULT_WATCH_POLL_MS, isWatching, liveAfter, liveChanged, parseWatchEvent, phaseAfter, snapshotOf, watchLines, watchPollMsOf } from './live'

describe('the watcher lines', () => {
  it('reads complete lines across pieces, keeping an unfinished one for the next', () => {
    const first = watchLines('', '{"event":"ready","snapshot_id":"s1"}\n{"event":"chan')
    expect(first.events).toEqual([{ event: 'ready', snapshot_id: 's1' }])
    expect(first.rest).toBe('{"event":"chan')
    const second = watchLines(first.rest, 'ges","changes":2}\n')
    expect(second.events).toEqual([{ event: 'changes', changes: 2 }])
    expect(second.rest).toBe('')
  })
  it('skips a line that is not an event, and reads the wrapper\'s no-binary', () => {
    expect(watchLines('', 'PHP Warning: x\n[1]\n{"status":"no-binary"}\n').events).toEqual([{ status: 'no-binary' }])
    expect(parseWatchEvent('{"other":1}')).toBeNull()
  })
  it('drops an unfinished line that grew past any real event', () => {
    expect(watchLines('', 'x'.repeat(70_000)).rest).toBe('')
  })
})

describe('the watcher phase', () => {
  it('follows the events: ready and done scans are live, a scan is scanning, another leader is following', () => {
    expect(phaseAfter({ event: 'ready' }, 'starting')).toBe('live')
    expect(phaseAfter({ event: 'scan_started' }, 'live')).toBe('scanning')
    expect(phaseAfter({ event: 'scan_completed' }, 'scanning')).toBe('live')
    expect(phaseAfter({ event: 'absorbed' }, 'live')).toBe('live')
    expect(phaseAfter({ event: 'following' }, 'starting')).toBe('following')
    expect(phaseAfter({ event: 'leading' }, 'following')).toBe('live')
    expect(phaseAfter({ event: 'snapshot' }, 'following')).toBe('following')
  })
  it('ends on a refusal or a stop; a retryable error keeps watching', () => {
    expect(phaseAfter({ event: 'refused', status: 'not-allowed' }, 'starting')).toBe('off')
    expect(phaseAfter({ event: 'stopped', reason: 'error' }, 'live')).toBe('off')
    expect(phaseAfter({ event: 'error', retryable: true }, 'scanning')).toBe('live')
    expect(phaseAfter({ event: 'error', retryable: false }, 'scanning')).toBe('scanning')
  })
  it('stays starting through a retried initial scan, and goes live at ready', () => {
    const failed = phaseAfter({ event: 'error', retryable: true, code: 'scan_timeout' }, 'starting')
    expect(failed).toBe('starting')
    expect(phaseAfter({ event: 'ready' }, failed)).toBe('live')
  })
  it("calls following this process's own earlier watcher live, not another session", () => {
    expect(phaseAfter({ event: 'following', same_process: true }, 'starting')).toBe('live')
  })
  it('keeps whether the leader stopped answering while following, until a following event says otherwise', () => {
    const stuck = liveAfter({ event: 'following', stale: true }, { phase: 'starting' })
    expect(stuck).toEqual({ phase: 'following', stale: true })
    expect(liveAfter({ event: 'snapshot', snapshot_id: 's2' }, stuck)).toEqual({ phase: 'following', stale: true })
    expect(liveAfter({ event: 'following', stale: false }, stuck)).toEqual({ phase: 'following' })
    expect(liveAfter({ event: 'leading' }, stuck)).toEqual({ phase: 'live' })
  })
  it('keeps who else is scanning from the moment the watcher sees it until it says that scan ended', () => {
    const seen = liveAfter({ event: 'external_scan', holder_pid: 1234, held_seconds: 3 }, { phase: 'live' })
    expect(seen).toEqual({ phase: 'live', external: { pid: 1234 } })
    expect(liveAfter({ event: 'snapshot', snapshot_id: 's2' }, seen)).toEqual({ phase: 'live', external: { pid: 1234 } })
    expect(liveAfter({ event: 'external_scan_ended' }, seen)).toEqual({ phase: 'live' })
    // A writer on another host, a container among them, is named by its host: its pid means nothing here.
    expect(liveAfter({ event: 'external_scan', holder_pid: null, holder_host: 'f3a9c2e1b7d0', held_seconds: 1 }, { phase: 'live' })).toEqual({
      phase: 'live',
      external: { pid: null, host: 'f3a9c2e1b7d0' },
    })
    expect(liveAfter({ event: 'stopped' }, seen)).toEqual({ phase: 'off' })
    // A lease an older knossos took names no process; a follower whose leader is stuck can still see another scan.
    expect(liveAfter({ event: 'external_scan', holder_pid: null }, { phase: 'following', stale: true })).toEqual({ phase: 'following', stale: true, external: { pid: null } })
  })
  it('says the state changed when the phase, a stuck leader, or who else scans did', () => {
    expect(liveChanged({ phase: 'live' }, { phase: 'live' })).toBe(false)
    expect(liveChanged({ phase: 'live' }, { phase: 'scanning' })).toBe(true)
    // Another host took the lock with the same (null) pid: still news.
    expect(liveChanged({ phase: 'live', external: { pid: null, host: 'a' } }, { phase: 'live', external: { pid: null, host: 'b' } })).toBe(true)
    expect(liveChanged({ phase: 'following' }, { phase: 'following', stale: true })).toBe(true)
    expect(liveChanged({ phase: 'live' }, { phase: 'live', external: { pid: 1 } })).toBe(true)
    expect(liveChanged({ phase: 'live', external: { pid: 1 } }, { phase: 'live', external: { pid: 2 } })).toBe(true)
    expect(liveChanged({ phase: 'live', external: { pid: 1 } }, { phase: 'live', external: { pid: 1 } })).toBe(false)
  })
  it('names the snapshot an event reports', () => {
    expect(snapshotOf({ event: 'scan_completed', snapshot_id: 's2' })).toBe('s2')
    expect(snapshotOf({ event: 'changes' })).toBeNull()
    expect(snapshotOf({ event: 'ready', snapshot_id: '' })).toBeNull()
  })
  it('counts following another session as watching', () => {
    expect(isWatching({ phase: 'following' })).toBe(true)
    expect(isWatching({ phase: 'starting' })).toBe(false)
  })
})

describe('the poll interval option', () => {
  it('takes a whole number of milliseconds in range, else the default', () => {
    expect(watchPollMsOf(2_000)).toBe(2_000)
    expect(watchPollMsOf(100)).toBe(DEFAULT_WATCH_POLL_MS)
    expect(watchPollMsOf(1.5)).toBe(DEFAULT_WATCH_POLL_MS)
    expect(watchPollMsOf(undefined)).toBe(DEFAULT_WATCH_POLL_MS)
    expect(watchPollMsOf(60_001)).toBe(DEFAULT_WATCH_POLL_MS)
  })
})
