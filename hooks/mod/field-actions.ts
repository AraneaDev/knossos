/**
 * The pane's text fields: moving the focus into one once it is drawn, and a
 * tab's narrowing field (the Hubs filter, the Notes text), which opens on its
 * tab, narrows the list as it is typed and keeps the text on Enter.
 */
import type { KnossosView, PaneTab } from '../../types'
import type { Port } from './port'
import { fieldKey, PANE, revealThenFocus } from './state'

/**
 * Moves the focus into the field `id` once it is drawn, under its own key
 * ({@link fieldKey}): the call waits for the drawing that brings the field,
 * so it runs on a timer, never inside the press or a render.
 */
export function focusField(io: Port, id: string, reveal = true): void {
  io.clock.after(0, () => void (reveal ? revealThenFocus(io, fieldKey(id)) : io.ui.focus({ requestId: PANE, key: fieldKey(id) })).catch(() => undefined))
}

/** A narrowing field's presses: `open` it on its tab, `type` into it, `submit` it with Enter. */
export type Narrowing = {
  open: (io: Port) => Promise<void>
  type: (io: Port, text: string) => Promise<void>
  submit: (io: Port, text: string) => Promise<void>
}

/**
 * The narrowing field `id` of `tab`, whose text the view keeps in `text`.
 * Typing narrows at once, the marker back at the top of the shorter list;
 * Enter keeps the text (an empty one clears it) and closes the field.
 * `reread`, when given, runs after each change: for a list read again for
 * its text rather than narrowed in place.
 */
export function narrowing(tab: PaneTab, id: string, text: 'filter' | 'notesText', reread?: (io: Port) => Promise<unknown>): Narrowing {
  const after = async (io: Port): Promise<void> => {
    if (reread !== undefined) await reread(io)
  }
  return {
    open: async io => {
      await io.state.view.update((v): KnossosView => ({ ...v, tab, filtering: true }))
      focusField(io, id)
    },
    type: async (io, typed) => {
      await io.state.view.update(v => ({ ...v, [text]: typed, selected: 0 }))
      await after(io)
    },
    submit: async (io, typed) => {
      await io.state.view.update(v => ({ ...v, [text]: typed.trim(), filtering: false, selected: 0 }))
      await after(io)
    },
  }
}
