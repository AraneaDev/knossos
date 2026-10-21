/**
 * The presses on the detail's diff: a folded hunk opened whole, and the page
 * of hunks moved.
 */
import type { DiffFold } from '../../types'
import { diffPage, diffView } from '../lib/diff'
import type { Port } from './port'

/**
 * A press on the detail's diff: `change` given how far it is opened now,
 * for the diff on show. Opened on another diff (another file, commit or
 * snapshot), it starts closed. Nothing without a diff that has landed.
 */
async function foldDiff(io: Port, change: (fold: DiffFold) => DiffFold): Promise<void> {
  const shown = (await io.state.view.read()).inspect
  const state = await io.state.fileDiff.read()
  if (shown === null || shown.file !== true || state === null || state.name !== shown.name || state.phase !== 'done') return
  const fresh: DiffFold = { name: state.name, rev: state.rev, snapshot: state.snapshot, open: [], from: 0 }
  await io.state.diffFold.update(f => change(f !== null && f.name === fresh.name && f.rev === fresh.rev && f.snapshot === fresh.snapshot ? f : fresh))
}

/**
 * "N more lines" under a folded hunk: the hunk shows whole. Opened, it may
 * no longer fit the page after the hunks before it; the page then starts
 * at it, so the press never hides what it opened.
 */
export async function openHunk(io: Port, index: number): Promise<void> {
  if (!Number.isInteger(index) || index < 0) return
  const shown = (await io.state.view.read()).inspect
  const state = await io.state.fileDiff.read()
  const rev = await io.state.sessionRev.read()
  await foldDiff(io, fold => {
    const opened: DiffFold = { ...fold, open: fold.open.includes(index) ? fold.open : [...fold.open, index] }
    const view = shown === null ? null : diffView(shown, state, rev, opened)
    if (view?.phase !== 'diff' || index >= view.hunks.length) return fold
    return diffPage(view).drawn.some(d => d.index === index) ? opened : { ...opened, from: index }
  })
}

/** "N more changes" or "N earlier changes": the page starts at the hunk named. */
export async function pageDiff(io: Port, from: number): Promise<void> {
  if (!Number.isInteger(from) || from < 0) return
  await foldDiff(io, fold => ({ ...fold, from }))
}
