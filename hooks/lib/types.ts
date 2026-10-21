/**
 * The shapes every view of the pane draws with: styled segments, the rows
 * they make up, and what a segment can carry (a press, a field, a place, a
 * hover card). Types only; `rows.ts`, `text.ts`, `table.ts` and `chips.ts` hold
 * the primitives that build them.
 */
/** A pressable segment: drawn as a plain Button, `hotkey: label` when it has a hotkey. */
export type Press = { id: string; label: string; hotkey?: string }
/**
 * A place in a file: an absolute path and, when known, a line. A segment
 * that carries one is drawn as a link the person can click to open it.
 */
export type Loc = { path: string; line: number | null }
/** A one-line text field: drawn as an Input keyed `id`, holding `value`. */
export type Field = { id: string; value: string; placeholder: string }
/**
 * How a segment draws as cells of a terminal `Raster` instead of as text:
 * each of its cells shows `glyph` in `fg` (else the segment's colour) on
 * `bg` (else the terminal's own); colours are theme keys the Raster
 * resolves, or `#rrggbb`.
 */
export type Cell = { glyph: string; fg?: string; bg?: string }
/**
 * A card that shows while the pointer rests on the row it belongs to (where
 * the surface has a pointer): its rows, and how wide it is. It never takes a
 * row of the pane: it is drawn over the rows below its own.
 */
export type Preview = { key: string; rows: Row[]; width: number }

/**
 * A run of text in one style. `color` is a Claude Code theme key (see
 * `palette.ts`); `dim` marks secondary text, drawn in the theme's `inactive`;
 * `bg` a theme key laid under it. `hidden` marks a pressable with no text
 * that is drawn out of sight: it exists only so its `press.hotkey` keeps
 * working. `preview` hangs a hover card off the segment's row.
 */
export type Segment = { text: string; color?: string; dim?: boolean; bold?: boolean; bg?: string; press?: Press; field?: Field; cell?: Cell; hidden?: true; preview?: Preview }
/**
 * One line of the pane. Consecutive rows with the same `raster` key form a
 * grid the terminal may draw as one `Raster`; every surface can draw their
 * segments as text instead.
 */
export type Row = {
  key: string
  segments: Segment[]
  raster?: string
  /** In a row of the wide grid, the index of the first segment of the right card's row: everything before it is the left card's and the gap. */
  split?: number
  /** Drawn as the engine's diff element instead of its segments: unified-diff hunks, and the file they are of (its language). */
  code?: { source: string; path: string }
  /** The background the whole row is tinted with (the marked row): a card's frame carries it to its inner edge. */
  tint?: string
  /** The cells a card's gutter lays before the row's own content (two in a medium or wide card); in a row of the wide grid, its left card's. */
  inset?: number
  /** In a row of the wide grid, the cells the right card's gutter lays before its row's own content. */
  insetRight?: number
  /** The lists the wheel can scroll whose card this row belongs to, each over the cells it covers (see {@link ListSpan}). */
  lists?: ListSpan[]
}

/**
 * A card's list as the wheel finds it under the pointer: the card's key,
 * the cells `from` (inclusive) to `to` (exclusive) the card covers in the
 * row, and the window it shows: its first item, how many it shows, and how
 * many there are.
 */
export type ListSpan = { key: string; from: number; to: number; start: number; shown: number; length: number }
