/**
 * What the band and the pane draw: the pane's input from state, the laid-out
 * rows as elements, the hover cards, and the text budget that keeps the pane
 * within the engine's bounds. Drawing reads state but acts on nothing: what
 * a press does is handed in ({@link Handlers}).
 */
import type { Elements, RenderElement, RenderNode, RenderSurface, UiPressArgument } from 'claude-code'

import { bandModel } from '../lib/band'
import { dimRow, pressLabel, rowsHeight, rowWidth, textStyle } from '../lib/rows'
import { cells, fit } from '../lib/text'
import { paneHeight } from '../lib/cards'
import { paneLayout, shrinkRows } from '../lib/layout'
import type { Preview, Row, Segment } from '../lib/types'
import { CARD_BG, declaredOf, huesOf, SELECTED_BG } from '../lib/palette'
import { rasterOf, rasterTheme } from '../lib/raster'
import { FILE_PRESS } from '../lib/editor'
import type { Port } from './port'
import { fieldRooms, noteSpinners, paneSource, spunRow } from './frame'
import { cardPlace } from '../lib/place'
import type { Handlers } from './input'
import { fieldKey, mod } from './state'

/** A hover group's name for one card: the plugin's prefix and the card's key, within the engine's 64 characters. */
const scopeOf = (preview: Preview): string => `knossos:${preview.key}`.slice(0, 64)

/**
 * One segment as an element: a pressable segment is a plain Button (a
 * place opens its file's detail), a field an Input, any other a Text. A
 * Button with a background
 * (the open tab, the marked row) stands in a Box of that colour, which they
 * cannot take themselves. `scope` joins the segment to its row's hover card.
 *
 * Nothing a segment draws can wrap or push its row wider: every Text cuts
 * at its edge (`truncate-end`) inside a row exactly as wide as the pane; a
 * Button draws the segment's own text as its label ({@link pressLabel}), not
 * the whole name its press may carry, so it takes exactly the cells the
 * layout gave it; one on a tint stands in a Box of that tint and width, and
 * a field's Box takes the rest of its row (`room`). Off the terminal a
 * Button and a field are the surface's own, whose width the pane
 * cannot know in cells: the row's width and overflow clip them at its edge.
 *
 * The engine draws a pane's first 100,000 characters of text (see
 * {@link TEXT_BUDGET}); a Text carries no key and no Box of its own, and a
 * Box is keyed only where a key is needed (a tint, a hidden twin), so a tall
 * pane stays a light tree to diff.
 */
function drawSegment(act: Handlers, ui: Elements[RenderSurface], row: Row, s: Segment, i: number, terminal: boolean, room: number, scope?: string) {
  const { Box, Button, Text } = ui
  // Every surface but mobile has a text field; there the filter is shown as text.
  const Input = 'Input' in ui ? ui.Input : undefined
  const hover = scope === undefined ? {} : { hover: { scope } }
  const native = s.press !== undefined || s.field !== undefined
  const width = s.field !== undefined && Input !== undefined ? Math.max(1, room) : cells(s.text)
  const sized = !native || terminal ? { width } : {}
  // The marked row's Button or link keeps its `-bg` Box: that Box is what carries the tint.
  const framed = (element: RenderNode, key?: string, bg?: string) => (
    <Box {...(key === undefined ? {} : { key })} {...sized} flexShrink={0} {...(bg === undefined ? {} : { backgroundColor: bg })}>
      {element}
    </Box>
  )
  // A Button or link draws exactly its segment's cells: only a tint needs a Box under it.
  const ground = (key: string, element: RenderNode) => (s.bg === undefined ? element : framed(element, `${key}-bg`, s.bg))
  if (s.field && Input !== undefined) {
    return framed(
      <Input
        key={fieldKey(s.field.id)}
        value={s.field.value}
        placeholder={s.field.placeholder}
        submitLabel="keep"
        autoFocus
        onInput={(value: string) => act.type(s.field!.id, value)}
        onSubmit={(value: string) => act.submit(s.field!.id, value)}
      />,
    )
  }
  if (s.press && s.hidden) {
    // Out of sight, there only for its hotkey (a tab drawn as its digit alone).
    return (
      <Box key={s.press.id} display="none">
        <Button key={s.press.id} plain label={s.press.label} {...(s.press.hotkey === undefined ? {} : { hotkey: s.press.hotkey })} onPress={(pressed: UiPressArgument) => act.press(s.press!.id, pressed.surface)} />
      </Box>
    )
  }
  if (s.press) {
    return ground(
      s.press.id,
      <Button
        key={s.press.id}
        plain
        label={pressLabel(s)}
        {...(s.press.hotkey === undefined ? {} : { hotkey: s.press.hotkey })}
        {...(s.dim ? { dimColor: true } : {})}
        {...hover}
        onPress={(pressed: UiPressArgument) => act.press(s.press!.id, pressed.surface)}
      />,
    )
  }
  return (
    <Text wrap="truncate-end" {...textStyle(s)} {...hover}>
      {s.text}
    </Text>
  )
}

/**
 * One laid-out row as elements (see {@link drawSegment}): a Box exactly
 * `columns` wide that clips what passes its edge, so a row can neither wrap
 * nor widen the pane. A row that hangs a hover card joins only the name the
 * card is about to the card's group: the pointer on that name shows it.
 *
 * Without `places` (a lean tree, off the marked row) a place that opens its
 * file's detail is drawn as text: each Button weighs on the engine's bounds.
 */
function drawRow(act: Handlers, ui: Elements[RenderSurface], line: Row, columns: number, terminal: boolean, places = true, hovers = false) {
  const { Box, Code } = ui
  const spinning = spunRow(line, mod.motion.spinFrame)
  const row = places ? spinning : { ...spinning, segments: spinning.segments.map(({ press, ...s }) => (press === undefined || press.id.startsWith(FILE_PRESS) ? s : { ...s, press })) }
  // A change's hunks: the engine's own diff, gutters, markers and colours as Claude Code draws them.
  if (row.code !== undefined) {
    return (
      <Box key={row.key} flexDirection="column" width={columns} overflow="hidden">
        <Code key={`${row.key}-code`} source={row.code.source} path={row.code.path} format="diff" wrap="truncate-end" />
      </Box>
    )
  }
  const at = hovers ? row.segments.findIndex(s => s.preview !== undefined) : -1
  const preview = at < 0 ? undefined : row.segments[at]!.preview!
  // The name the card is about joins its group: resting the pointer on it shows the card.
  const scoped = (i: number) => (preview !== undefined && i === at ? scopeOf(preview) : undefined)
  const { Text } = ui
  // A run of plain text (no press or field, no hover group) is one Text that cuts at the row's edge,
  // its styled pieces nested in it and the rest bare strings: a tall pane stays far inside the engine's bounds.
  const plain = (s: Segment): boolean => s.press === undefined && s.field === undefined
  const drawn: RenderNode[] = []
  const rooms = fieldRooms(row.segments, columns)
  let x = 0
  for (let i = 0; i < row.segments.length; i++) {
    const s = row.segments[i]!
    const field = rooms.get(i)
    if (field !== undefined) {
      // A field takes the blank cells laid out after it, and stops where the row's next drawn segment (a card's frame) begins.
      drawn.push(drawSegment(act, ui, row, s, i, terminal, field.room, scoped(i)))
      x += field.room
      i = field.next - 1
      continue
    }
    if (!plain(s)) {
      drawn.push(drawSegment(act, ui, row, s, i, terminal, columns - x, scoped(i)))
      x += s.hidden === true ? 0 : cells(s.text)
      continue
    }
    const run: Segment[] = [s]
    // One hover group per run: the outer Text joins it (a nested Text follows its group but cannot light it).
    const scope = scoped(i)
    while (i + 1 < row.segments.length && plain(row.segments[i + 1]!) && scoped(i + 1) === scope) run.push(row.segments[++i]!)
    x += run.reduce((n, r) => n + cells(r.text), 0)
    drawn.push(textRun(Text, run, scope))
  }
  return (
    <Box key={row.key} flexDirection="row" width={columns} overflow="hidden">
      {drawn}
    </Box>
  )
}

/**
 * A run of plain segments as one Text that cuts at its edge: a lone segment
 * styled itself, else its styled pieces nested in it and the rest bare
 * strings. `scope` joins it to a hover group.
 */
function textRun(Text: Elements[RenderSurface]['Text'], run: Segment[], scope?: string): RenderNode {
  const hover = scope === undefined ? {} : { hover: { scope } }
  const pieces = mergedRun(run)
  if (pieces.length === 1) {
    return (
      <Text wrap="truncate-end" {...textStyle(pieces[0]!)} {...hover}>
        {pieces[0]!.text}
      </Text>
    )
  }
  return (
    <Text wrap="truncate-end" {...hover}>
      {pieces.map(r => (Object.keys(textStyle(r)).length === 0 ? r.text : <Text {...textStyle(r)}>{r.text}</Text>))}
    </Text>
  )
}

/** A run of segments with neighbours of one style joined, so each style change is one piece; blank cells take any style. */
function mergedRun(run: Segment[]): Segment[] {
  const out: Segment[] = []
  // Blank cells without a ground look the same in any colour: they carry no style, and join their neighbours.
  for (const piece of run) {
    const r = piece.bg === undefined && /^ *$/.test(piece.text) ? { text: piece.text } : piece
    const last = out[out.length - 1]
    const same = last !== undefined && JSON.stringify(textStyle(last)) === JSON.stringify(textStyle(r))
    if (same) out[out.length - 1] = { ...last, text: last.text + r.text }
    else out.push(r)
  }
  return out
}

/**
 * How much text the pane lets one drawing hold. The engine refuses no tree
 * for its size: it draws the first 100,000 characters of its texts (children,
 * labels, link texts, code) and cuts the rest, so the pane stays under that,
 * with room to spare, rather than see its last rows cut mid-line.
 */
export const TEXT_BUDGET = 90_000

/** The fewest rows the pane lays its lists out for when it gives rows back to fit its budget. */
const SHORT_ROWS = 16

/**
 * A drawn tree's size as the engine bounds it: the characters of every text
 * it draws (children, labels, link texts, code sources, alts). The engine's
 * element keeps its children beside its props; a JSX element, as the specs
 * build one, inside them: both are read.
 */
export function drawnText(tree: unknown): number {
  if (typeof tree === 'string') return tree.length
  if (Array.isArray(tree)) return tree.reduce((n: number, t) => n + drawnText(t), 0)
  if (tree === null || typeof tree !== 'object') return 0
  const { props = {}, children } = tree as { props?: Record<string, unknown>; children?: unknown }
  const own = ['label', 'text', 'source', 'alt'].reduce((n, k) => n + (typeof props[k] === 'string' ? (props[k] as string).length : 0), 0)
  return own + drawnText(children) + drawnText(props.children)
}

/** The most hover cards a pane hangs: those on the rows nearest the marked one. */
const CARDS_MAX = 8

/**
 * The hover cards the rows hang, drawn out of the flow over the rows below
 * their own (`position: absolute`), hidden until the pointer rests on a
 * segment of their group. Nothing crosses to the mod when one shows: the
 * surface reveals it alone. Only where the surface has a pointer. A card is
 * one Text of its lines on the card's ground, as wide as the card and cut at
 * its edge: two nodes, however many rows hang one, so a tall list keeps the
 * tree far inside the engine's bounds.
 */
function drawCards(ui: Elements[RenderSurface], rows: Row[], columns: number) {
  const { Box, Text } = ui
  const cards = []
  // At most CARDS_MAX cards, on the rows nearest the marked one: a tall list cannot grow the tree past its bounds.
  const marked = Math.max(0, rows.findIndex(r => r.tint === SELECTED_BG))
  const hung = rows.map((r, y) => ({ y, has: r.segments.some(s => s.preview !== undefined) })).filter(r => r.has).sort((a, b) => Math.abs(a.y - marked) - Math.abs(b.y - marked) || a.y - b.y).slice(0, CARDS_MAX).map(r => r.y)
  for (const y of hung.sort((a, b) => a - b)) {
    const row = rows[y]!
    let x = 0
    for (const s of row.segments) {
      const preview = s.preview
      if (preview !== undefined) {
        const place = cardPlace(y, x, { rows: preview.rows.length, columns: preview.width }, { rows: rows.length, columns })
        const width = Math.min(preview.width, columns)
        cards.push(
          <Box key={preview.key} position="absolute" top={place.row} left={place.column} width={width} height={preview.rows.length} display="none" backgroundColor={CARD_BG} hover={{ scope: scopeOf(preview), display: 'flex' }}>
            <Text wrap="truncate-end">{preview.rows.map(line => line.segments.map(c => c.text).join('')).join('\n')}</Text>
          </Box>,
        )
        break
      }
      x += cells(s.text)
    }
  }
  return cards
}

/**
 * The laid-out rows as elements. On the terminal, consecutive rows that share
 * a `raster` key (the heat map) are one `Raster` of coloured cells; every
 * other surface draws them as text, glyphs and colours alike. Where the
 * surface has a pointer (all but mobile), the hover cards follow the rows.
 */
function drawRows(act: Handlers, ui: Elements[RenderSurface], surface: RenderSurface, themeName: string, rows: Row[], columns: number, lean = false) {
  const terminal = surface === 'terminal'
  // Lean (a tree near the engine's bounds): no hover cards, and only the marked row's places open their files.
  const hovers = surface !== 'mobile' && !lean
  const drawn = []
  for (let i = 0; i < rows.length; i++) {
    const block = rows[i]!.raster
    if (!terminal || block === undefined) {
      drawn.push(drawRow(act, ui, rows[i]!, columns, terminal, !lean || rows[i]!.tint === SELECTED_BG, hovers))
      continue
    }
    let end = i
    while (end + 1 < rows.length && rows[end + 1]!.raster === block) end++
    const grid = rows.slice(i, end + 1)
    const { Raster } = ui as Elements['terminal']
    const raster = rasterOf(grid, Math.max(1, ...grid.map(rowWidth)), rasterTheme(themeName))
    drawn.push(<Raster key={`raster-${block}`} columns={raster.columns} rows={raster.rows} cells={raster.cells} />)
    i = end
  }
  return hovers ? [...drawn, ...drawCards(ui, rows, columns)] : drawn
}

/** What the band's buttons do: open the pane, copy the command it offers, hide it. A press waits on what they return. */
export type BandHandlers = {
  details: () => unknown
  copy: (command: string, surface?: RenderSurface) => void
  hide: () => unknown
}

/**
 * The band above the prompt: what the latest turn did to the graph, with its
 * buttons; null when it has nothing to say (or is hidden), and the engine
 * draws its own. `resolve` gives the surface's elements, asked for only when
 * there is something to draw.
 */
export async function drawBand(io: Port, resolve: () => Elements[RenderSurface], props: { hasSurvey: boolean; bodyColumns: number }, act: BandHandlers): Promise<RenderElement | null> {
  if (mod.session.disabled || props.hasSurvey || (await io.state.view.read()).isBandHidden) {
    mod.pane.bandText = null
    return null
  }
  const d = await io.state.dashboard.read()
  const model = bandModel(await io.state.brief.read(), await io.state.job.read(), await io.clock.now(), d?.status === 'ok' ? declaredOf(d) : undefined, d?.status === 'ok' ? huesOf(d) : undefined)
  mod.pane.bandText = model?.text ?? null
  if (model === null) return null
  const { Box, Button, Text } = resolve()
  const color = model.tone === 'alert' ? 'error' : model.tone === 'warn' ? 'warning' : 'inactive'
  // The text gives way to the buttons, each drawn `[ label ]` and a space: a cut line still names what happened.
  const labels = [...(model.showDetails ? ['details'] : []), ...(model.copy === undefined ? [] : ['copy']), 'hide']
  const room = Math.max(1, props.bodyColumns - labels.reduce((n, l) => n + cells(l) + 5, 0) - 1)
  const copy = model.copy
  return (
    <Box key="band" width={props.bodyColumns} overflow="hidden">
      <Box key="band-text" width={cells(fit(model.text, room)) + 1} flexShrink={0} overflow="hidden">
        <Text color={color} wrap="truncate-end">
          {fit(model.text, room)}{' '}
        </Text>
      </Box>
      {model.showDetails && <Button key="details" label="details" onPress={() => act.details()} />}
      {copy !== undefined && <Button key="copy" label="copy" onPress={(pressed: UiPressArgument) => act.copy(copy, pressed.surface)} />}
      <Button key="hide" label="hide" onPress={() => act.hide()} />
    </Box>
  )
}

/**
 * The architecture pane, for a pane `bodyColumns` wide scrolled as `scroll`
 * says. The engine draws only a tree's first 100,000 characters of text: past {@link TEXT_BUDGET} the
 * pane drops its hover cards and most places' presses, then gives its lists fewer
 * rows, until it fits; past it even at {@link SHORT_ROWS}, one line says so.
 */
export async function drawPane(io: Port, ui: Elements[RenderSurface], surface: RenderSurface, props: { bodyColumns: number; scroll?: { offset: number; bodyRows: number } }, act: Handlers): Promise<RenderElement> {
  const { Box } = ui
  const columns = Math.max(1, props.bodyColumns)
  const terminal = surface === 'terminal'
  const source = await paneSource(io, columns, terminal)
  if (source.kind === 'off') {
    await noteSpinners(io, [])
    return <Box key="off" />
  }
  if (source.kind === 'empty') {
    await noteSpinners(io, source.rows)
    if (source.rows.length === 0) return <Box key="empty" />
    return (
      <Box key="empty" flexDirection="column">
        {source.rows.map(row => drawRow(act, ui, row, columns, terminal))}
      </Box>
    )
  }
  const { input, key } = source
  // No loop variable may be called `h`: JSX compiles to h(...), and a
  // parameter of that name shadows the element factory inside its callback.
  const height = paneHeight(props.scroll)
  const offset = Math.max(0, props.scroll?.offset ?? 0)
  const themeName = await io.state.theme.read()
  // How many rows the last layout drew with something in them (the blank fill under a short tab left out).
  let used = height
  // The rows of the last tree drawn: shrinkRows returns the last one it drew, so these are the returned tree's.
  let shown: Row[] = []
  const layout = (rows: number) => {
    const laidOut = paneLayout(input, columns, rows, offset)
    used = rowsHeight(laidOut.body.filter(r => !r.key.startsWith('fill-'))) + laidOut.footer.length
    // Kept for the wheel: the last layout drawn is the one on screen.
    mod.drawn.rows = laidOut.body
    mod.drawn.offset = offset
    return laidOut
  }
  const draw = (laidOut: ReturnType<typeof layout>, lean: boolean): RenderElement => {
    shown = [...laidOut.body, ...laidOut.footer]
    return build(laidOut, lean)
  }
  const build = (laidOut: ReturnType<typeof layout>, lean: boolean): RenderElement => {
    if (!laidOut.pinned) {
      return (
        <Box key={key} flexDirection="column">
          {drawRows(act, ui, surface, themeName, [...laidOut.body, ...laidOut.footer], columns, lean)}
        </Box>
      )
    }
    // Taller than the window: the bar is drawn over its last rows wherever it is scrolled to, and the body ends in room for it.
    // Counted in rows as drawn (a diff element one per line), so it follows the window to the last line.
    const reserve = laidOut.footer.map((_, i) => ({ key: `bar-room-${i}`, segments: [{ text: ' ' }] }))
    const top = Math.min(offset, rowsHeight(laidOut.body) + reserve.length - height) + height - laidOut.footer.length
    return (
      <Box key={key} flexDirection="column">
        {drawRows(act, ui, surface, themeName, [...laidOut.body, ...reserve], columns, lean)}
        <Box key="bar" position="absolute" top={Math.max(0, top)} left={0} width={columns} flexDirection="column">
          {laidOut.footer.map(row => drawRow(act, ui, row, columns, terminal))}
        </Box>
      </Box>
    )
  }
  const first = layout(height)
  // The spinners of the layout about to be drawn: the frame they spin at is set before any row is.
  await noteSpinners(io, [...first.body, ...first.footer])
  let tree = draw(first, false)
  if (drawnText(tree) <= TEXT_BUDGET) return tree
  tree = draw(layout(height), true)
  // Lists make most of the weight: fewer rows each try, in proportion to the budget, down to SHORT_ROWS at the last.
  const lean = { tree, weight: drawnText(tree) }
  const fitted = shrinkRows(Math.min(height, used), SHORT_ROWS, TEXT_BUDGET, lean, rows => {
    const smaller = draw(layout(rows), true)
    return { tree: smaller, weight: drawnText(smaller) }
  })
  if (fitted.weight <= TEXT_BUDGET) {
    // The fewer rows drawn under the budget may hold other spinners than the full layout did.
    await noteSpinners(io, shown)
    return fitted.tree
  }
  await noteSpinners(io, [])
  const { weight, rows } = fitted
  // Still past the budget at the fewest rows: one line says so, rather than a tree the engine would refuse.
  if (!mod.pane.tooLargeLogged) {
    mod.pane.tooLargeLogged = true
    io.ui.log(`knossos: the pane was too large to draw (${weight} characters at ${rows} rows), so it drew one line instead`, { to: 'debug' })
  }
  return (
    <Box key={key} flexDirection="column">
      {drawRows(act, ui, surface, themeName, [dimRow('too-large', ' Too large to draw here: close the detail, or make the pane narrower.', columns)], columns, true)}
    </Box>
  )
}
