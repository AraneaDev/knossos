/**
 * The pane's input as the pane reads it from state, apart from drawing: what a
 * press hands back ({@link Handlers}), the input every tab draws from, and the
 * rows the selection walks. Plain TypeScript, so a host without the engine's
 * bundler can load it.
 */
import type { RenderSurface } from 'claude-code'

import { diffView } from '../lib/diff'
import { detailInput } from '../lib/views'
import { fileDetailInput } from '../lib/files'
import { listFor } from '../lib/pane-list'
import { paneInput } from '../lib/pane-input'
import type { Openable } from '../lib/views'
import type { PaneInput } from '../lib/pane-types'
import { huesOf } from '../lib/palette'
import { shownChanges } from './port'
import type { Port } from './port'
import { mod } from './state'

/**
 * What the pane's elements do: a press (a Button, by its id, from the surface
 * it came from), a keystroke or Enter in a field (by the field's id), and a
 * plain click on a link. Handed in by the hooks module, so drawing imports
 * none of the actions; each one swallows its own failure, as a handler that
 * outlives the session (a teardown under it) must.
 */
export type Handlers = {
  press: (id: string, surface?: RenderSurface) => void
  type: (field: string, value: string) => void
  submit: (field: string, value: string) => void
}

/** Everything the pane draws, from state; null when there is no dashboard to draw. */
export async function currentInput(io: Port, terminal: boolean): Promise<PaneInput | null> {
  const d = await io.state.dashboard.read()
  if (d?.status !== 'ok') return null
  // The pane's input carries the host: the layout and the diff's words are told it here, and never read `mod` themselves.
  const host = mod.host === 'web' ? ('web' as const) : undefined
  const v = await io.state.view.read()
  const stored = await io.state.detail.read()
  const shown = v.inspect === null ? null : v.inspect.file ? fileDetailInput(v.inspect, stored, d.project_root, huesOf(d)) : detailInput(v.inspect, stored, d.project_root)
  // The full detail opens its diff as far as the person pressed; the panel beside a tab draws it closed.
  if (shown !== null && v.inspect !== null) shown.diff = diffView(v.inspect, await io.state.fileDiff.read(), await io.state.sessionRev.read(), await io.state.diffFold.read(), host)
  const extras = {
    git: await io.state.gitHead.read(),
    feedback: await io.state.feedback.read(),
    couplings: await io.state.couplings.read(),
    flash: await io.state.flash.read(),
    motion: await io.state.motion.read(),
    search: await io.state.search.read(),
    branch: await io.state.branch.read(),
    churn: await io.state.churn.read(),
    notes: await io.state.notes.read(),
    rings: await io.state.rings.read(),
    route: await io.state.route.read(),
    note: await io.state.note.read(),
    untestedOnly: await io.state.untestedOnly.read(),
    ...(host === undefined ? {} : { host }),
  }
  // The marked row's detail beside the tab: only while the pane is drawn wide.
  const peeked = mod.paneWide ? await io.state.peek.read() : null
  const side = peeked === null ? null : peeked.shown.file === true ? fileDetailInput(peeked.shown, peeked.detail, d.project_root, huesOf(d)) : detailInput(peeked.shown, peeked.detail, d.project_root)
  if (side !== null && peeked !== null) side.diff = diffView(peeked.shown, await io.state.fileDiff.read(), await io.state.sessionRev.read(), null, host)
  return paneInput({ dashboard: d, brief: await io.state.brief.read(), refresh: await io.state.refresh.read(), rescan: await io.state.rescan.read(), view: v, now: await io.clock.now(), terminal, detail: shown, allow: await io.state.allow.read(), session: await shownChanges(io, d.project_root), sessionRoot: await io.state.sessionRoot.read(), live: await io.state.live.read(), extras, peek: side })
}

/** The rows the selection walks on what the pane shows, from state. */
export async function currentList(io: Port): Promise<Openable[]> {
  const input = await currentInput(io, true)
  return input === null ? [] : listFor(input)
}

