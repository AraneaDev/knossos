/**
 * The pane's motion: short, and only ever after something happened. When a
 * scan lands the live dot breathes and the Overview's bars grow in, for
 * {@link MOTION_MS}; while a scan runs the status turns a spinner. Between
 * those the pane is still, and nothing redraws for motion's sake.
 *
 * Pure: when the last scan landed and how many frames have been drawn since
 * in, what a frame shows out. The mod keeps the time and the frames.
 */

/** How long the motion after a scan lasts: the same moment a changed row stays lit. */
export const MOTION_MS = 3_000

/** How long a line under way spins before it stands still: an answer lost on its way never keeps the pane redrawing. */
export const BUSY_SPIN_MS = 60_000

/** How often a frame is drawn while something moves. */
export const MOTION_FRAME_MS = 125

/** The frames a bar takes to grow to its width. */
const GROW_FRAMES = 6

/** When the last scan landed (mod clock, ms; null before any), and the frames drawn since. */
export type Motion = { since: number | null; frame: number }

export const NO_MOTION: Motion = { since: null, frame: 0 }

/** Whether the motion after a scan is still running at `now`. */
export const moving = (motion: Motion, now: number): boolean => motion.since !== null && now >= motion.since && now < motion.since + MOTION_MS

/** The live dot: full and hollow by turns while the motion runs, full and still otherwise. */
export const dotGlyph = (motion: Motion, now: number): string => (moving(motion, now) && motion.frame % 2 === 1 ? '◌' : '◍')

/** A bar's width at this frame: grown to `width` over the first frames of the motion, whole otherwise. */
export function grown(width: number, motion: Motion, now: number): number {
  if (!moving(motion, now) || motion.frame >= GROW_FRAMES) return width
  return Math.min(width, Math.ceil((width * (motion.frame + 1)) / (GROW_FRAMES + 1)))
}

const SPINNER = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧'] as const

/** The spinner at `frame`, one step a frame round its cycle. */
export const spinGlyph = (frame: number): string => SPINNER[((frame % SPINNER.length) + SPINNER.length) % SPINNER.length]!

/**
 * The spinner's place in a line that says something is on its way: its
 * first frame, which the drawing turns with the frames ({@link spun}). The
 * layout stays pure and needs no frame, and every frame is one cell wide,
 * so what fits the line still fits it.
 */
export const BUSY = SPINNER[0]

/** `text` as a line under way: the spinner before it. */
export const busy = (text: string): string => `${BUSY} ${text}`

/** Whether `text` holds a spinner's place. */
export const isBusy = (text: string): boolean => text.includes(BUSY)

/** `text` with each spinner's place turned to `frame`. */
export const spun = (text: string, frame: number): string => (isBusy(text) ? text.replaceAll(BUSY, spinGlyph(frame)) : text)
