/**
 * The pane's layout: every row it draws, fitted to the columns it has.
 *
 * Pure: a view of the dashboard and the mod's state in, rows of styled
 * segments out. The render hook only turns segments into elements, so every
 * decision about width, order and colour is made here and tested here. This
 * module arranges the cards of each tab, the detail beside a list and the
 * pane as a whole (`paneLayout`). What it arranges comes from beside it: the
 * input from `pane-input.ts`, the lists and the selection from
 * `pane-list.ts`, the status and the tiles from `pane-status.ts`, the header
 * and the footer from `chrome.ts`, the screens without a graph from
 * `empty-state.ts`. The shared primitives are in `rows.ts` (segments),
 * `text.ts` (cutting) and `table.ts` (bars, the self-fitting table); the Issues and Cycles tabs and the
 * component detail in `views.ts`.
 */
import type { HubSort, KnossosView, PaneTab } from '../../types'
import { boundariesArrangement } from './boundaries'
import { changesArrangement } from './changes'
import { driftSection, fileDetailArrangement } from './files'
import { boundaryLabel, FAINT } from './palette'
import type { Hues } from './palette'
import { baseName, placeOf } from './paths'
import { blank, clip, dimRow, rowsHeight, rowWidth, inList } from './rows'
import { numberWidth, tableHead, tableRow, tableSpec, tierOf } from './table'
import { cells, grouped } from './text'
import type { Loc, Row, Segment } from './types'
import type { TableSpec, Tier } from './table'
import { CARD_MAX, componentFigures, fileFigures, previewCard } from './hover'
import { tilesBlock } from './tiles'
import { compositionBlock, concentrationBlock, flowsBlock, healthBlock, notesLineBlock, sessionBlock } from './overview'
import { branchArrangement, branchCount } from './branch'
import { churnArrangement } from './churn'
import { notesArrangement } from './notes-view'
import { routeArrangement } from './route'
import { finderBlock } from './finder'
import { cyclesArrangement } from './cycles'
import { detailArrangement, issueCount, issuesArrangement, superscript } from './views'
import { DEFAULT_ROWS, NOTE_ROOM, arrange, besideRows, fillColumn, fitBlocks, gridColumns, moreRows, noteOf, scrolledTo, shownOf, windowOf, windowedTable } from './cards'
import type { Arrangement, Block, Section } from './cards'
import type { DetailInput } from './views'
import { RANKING, kindLegend, legend } from './marks'
import type { Item, FileHub, PaneInput } from './pane-types'
import { allowRows } from './empty-state'
import { SORTS, hubsShown, filesShown, listFor } from './pane-list'
import { titleRow, tabRows, padOf, padded, footerRows, keysBlock } from './chrome'

// What layout.spec.ts and tools/pane-preview.mjs draw with, from one module.
export { allowInput, allowRows, emptyRows, noGraphOf } from './empty-state'
export { askPrompt, couplingPair, hubList, listFor, mergeRanked, subjectOf } from './pane-list'
export { paneInput } from './pane-input'
export { paneStatus, statsOf, summaryParts } from './pane-status'
export { gitLabel, tabRows, titleRow } from './chrome'
export { rowWidth } from './rows'
export { bar, tableSpec } from './table'
export { displayName, fit, wrapWords } from './text'
export { DEFAULT_ROWS } from './cards'
export { detailInput } from './views'
export { fileDetailInput } from './files'
export { nextTarget } from './boundaries'
export { accumulate, NO_CHANGES } from './session-changes'
export type { PaneInput } from './pane-types'
export type { Row } from './types'

/** The fewest hubs the Hubs tab lists, however short the pane. */
const HUBS_MIN = 5

/** The fewest files most depended on any tab lists, however short the pane. */
const FILES_MIN = 3

/**
 * The one boundary every listed row is in, when there are two rows or more
 * and they all share it: a column repeating it on every row says nothing,
 * so the section's note says it once instead.
 */
export function sharedBoundary(rows: { boundary: string | null }[]): string | null {
  const first = rows[0]?.boundary ?? null
  return rows.length > 1 && first !== null && rows.every(r => r.boundary === first) ? first : null
}

/** A component's numbers as a table shows them: in, out, cross and (wide, when known) the files depending on it. */
const degreesOf = (item: Item, withFiles: boolean): number[] => [item.in, item.out, item.cross, ...(withFiles ? [item.files ?? 0] : [])]

const degreeTitles = (withFiles: boolean): string[] => ['in', 'out', 'cross', ...(withFiles ? ['files'] : [])]

/** Whether a table of `items` at `tier` adds the dependent files: wide, and only when the dashboard counts them. */
const filesColumn = (items: Item[], tier: Tier): boolean => tier === 'wide' && items.some(i => i.files !== null)

/** Where a component is declared, as a table's file column shows it: its file's name and the line. */
const placeIn = (item: Item): string => (item.loc === null ? '' : placeOf(item.loc.path, item.loc.line))

/**
 * How a table of components fits `columns`: without the boundary column when
 * every row shares one, from the medium tier on with the file each is
 * declared in, and wide with each one's kind and dependent files too.
 */
function componentSpec(items: Item[], columns: number, hues: Hues, tier: Tier): TableSpec {
  const withFiles = filesColumn(items, tier)
  const widths = degreeTitles(withFiles).map((t, i) => numberWidth(t, items.map(item => degreesOf(item, withFiles)[i] ?? 0), tier))
  const labels = sharedBoundary(items) === null ? items.map(i => boundaryLabel(i.boundary, hues)) : []
  const places = tier === 'narrow' ? [] : items.map(placeIn)
  const kinds = items.map(i => i.kind)
  return tableSpec(columns, items.map(i => i.name), labels, widths, undefined, { tier, places, kinds })
}

/** A section's note with the boundary every row shares said once, in front: `all in core · in`. */
function sharedNote(items: Item[], note: string, hues: Hues): string {
  const shared = sharedBoundary(items)
  return shared === null ? note : [`all in ${boundaryLabel(shared, hues)}`, note].filter(s => s !== '').join(' · ')
}

/**
 * The listed components in `window` as table rows laid out by `spec`, with
 * the selection marker on `selected` (an index into the walkable list, the
 * first item being `offset`); the bar draws `sort`.
 */
function componentRows(prefix: string, items: Item[], selected: number, spec: TableSpec, hues: Hues, sort: HubSort, offset: number, window: { start: number; end: number }, room = CARD_MAX, lit: ReadonlySet<string> = new Set()): Row[] {
  // The spec has a number column per title: a fourth is the dependent files.
  const withFiles = spec.numbers.length > 3
  const titles = degreeTitles(withFiles)
  const max = Math.max(0, ...items.map(i => i[sort]))
  return [
    tableHead(`${prefix}-head`, spec, { name: 'name', boundary: 'boundary', numbers: titles }),
    ...items.slice(window.start, window.end).map((item, n) => {
      const i = window.start + n
      return tableRow(
        `${prefix}-${i}`,
        {
          name: item.name,
          boundary: item.boundary,
          values: degreesOf(item, withFiles),
          barValue: item[sort],
          kind: item.kind,
          max,
          selected: offset + i === selected,
          hotspotOnly: item.hotspotOnly,
          press: `row:${offset + i}`,
          place: placeIn(item),
          placeLoc: item.loc,
          preview: previewCard(`card-${prefix}-${i}`, { name: item.name, boundary: item.boundary, figures: componentFigures(item.files, item.in, item.out), top: item.top }, room, hues),
          lit: lit.has(`hub:${item.canonical}`),
          sorted: SORTS.indexOf(sort),
        },
        spec,
        hues,
      )
    }),
  ]
}

/** The hubs tab: one card with the filter field or line, and the filtered, sorted table, as long as the pane allows. */
function hubsBlock(input: PaneInput, list: Item[], selected: number, tier: Tier): Block {
  const hotspots = input.items.some(i => i.hotspotOnly) ? legend([[RANKING.hotspotOnly, '']]) : ''
  const range = input.degree
  const note = sharedNote(list, [hotspots, input.partial ? 'partial' : ''].filter(s => s !== '').join(' · '), input.hues)
  return {
    key: 'hubs',
    grow: { length: list.length, min: HUBS_MIN },
    make: (columns, limit, scrolled) => {
      const rows: Row[] = []
      if (input.filtering) {
        rows.push({ key: 'filter-row', segments: [{ text: '   filter: ', dim: true }, { text: input.filter, field: { id: 'filter', value: input.filter, placeholder: 'part of a name' } }] })
      } else if (input.filter !== '') {
        rows.push(dimRow('filter-row', `   filter "${input.filter}" · ${list.length} of ${input.items.length}`, columns))
      }
      if (range !== null) {
        // The list holds the most depended on only: the bucket may count many more than it lists.
        const counted = range.components === null ? '' : ` of ${grouped(range.components)}`
        rows.push(dimRow('degree-row', `   in-degree ${range.to === null ? `${range.from}+` : range.from === range.to ? `${range.from}` : `${range.from}–${range.to}`} · ${list.length} listed${counted}`, columns))
      }
      // The kinds the rows show join the note where the top edge has room; a note too long is dropped whole.
      const kinds = kindLegend(list.map(i => i.kind))
      const keyed = [note, kinds].filter(s => s !== '').join(' · ')
      const told = cells(`Hubs and hotspots sorted by ${input.sort}`) + cells(keyed) + NOTE_ROOM <= columns ? keyed : note
      const section = (body: Row[]): Section => ({ key: 'hubs', title: 'Hubs and hotspots', subtitle: `sorted by ${input.sort}`, note: noteOf(told), body })
      if (list.length === 0) return section([...rows, dimRow('hubs-none', input.filter === '' ? (range === null ? '   none' : '   none of the listed hubs is in this range') : `   no hub matches "${input.filter}"`, columns)])
      const spec = componentSpec(list, columns, input.hues, tier)
      const local = selected < list.length ? selected : -1
      const window = windowOf(list.length, limit, local, scrolled)
      return { ...section([...rows, ...componentRows('hub', list, selected, spec, input.hues, input.sort, 0, window, columns, input.lit), ...moreRows('hub-window', window, list.length, columns, 0)]), window: shownOf(window, list.length) }
    },
  }
}

/** The Hubs tab: the components most depended on and, beside them when wide, the files; the filter narrows both. */
function hubsArrangement(input: PaneInput, selected: number, tier: Tier): Arrangement {
  const list = hubsShown(input)
  const hubs = hubsBlock(input, list, selected, tier)
  const files = fileHubsBlock(filesShown(input), selected, list.length, tier, input.hues, input.filter === '' ? '' : `filter "${input.filter}"`, input.lit)
  // The components' table has more columns than the files': it takes the larger share, in a grid row of fixed columns.
  return files === null ? { left: [hubs] } : { left: [hubs], right: [files], rows: [[hubs, files]], split: 0.6 }
}

/**
 * The files most depended on, as a card: each file's path (cut from the
 * front, so its name stays), its boundary, a bar and how many files depend
 * on it; `limit` of them around the marker, each a row it walks from
 * `offset`. Null when the dashboard lists none.
 */
function fileHubsBlock(files: FileHub[], selected: number, offset: number, tier: Tier, hues: Hues, note = '', lit: ReadonlySet<string> = new Set()): Block | null {
  if (files.length === 0) return null
  return {
    key: 'files',
    grow: { length: files.length, min: FILES_MIN },
    make: (columns, limit, scrolled) => {
      const shared = sharedBoundary(files)
      const labels = shared === null ? files.map(f => boundaryLabel(f.boundary, hues)) : []
      const spec = tableSpec(columns, files.map(f => f.path), labels, [numberWidth('', files.map(f => f.dependents), tier)], 56, { tier })
      const max = Math.max(0, ...files.map(f => f.dependents))
      const { rows, window } = windowedTable('files-window', files, { columns, limit, local: inList(selected - offset, files.length), scrolled, walk: offset }, (shown, start) =>
        shown.map((f, n) => {
          const i = start + n
          const preview = previewCard(`card-files-${i}`, { name: baseName(f.path), boundary: f.boundary, figures: fileFigures(f.dependents), top: f.top }, columns, hues)
          return tableRow(`files-${i}`, { name: f.path, boundary: f.boundary, values: [f.dependents], max, path: true, selected: offset + i === selected, press: `row:${offset + i}`, preview, lit: lit.has(`file:${f.path}`) }, spec, hues)
        }),
      )
      const said = [shared === null ? '' : `all in ${boundaryLabel(shared, hues)}`, note, 'dependent files'].filter(t => t !== '').join(' · ')
      return { key: 'files', title: 'Files most depended on', note: noteOf(said), body: rows, window }
    },
  }
}

/**
 * The Overview: the stat tiles across the top, then the charts. Wide, a
 * fixed grid of two equal columns, the cards of a row drawn equally tall:
 * this session across the pane, composition beside dependency
 * concentration, health over time beside the cross-boundary flows.
 * Narrower, one column in the same order. The marker walks the way to
 * Changes, the buckets and the flows, in that order; the lists themselves
 * are on Hubs.
 */
function overviewArrangement(input: PaneInput, selected: number, tier: Tier): Arrangement {
  const data = input.overview
  const session = input.changes.files.length > 0 ? 1 : 0
  const tiles = input.stats.length > 0 ? [tilesBlock(input.stats, tier)] : []
  const sessionCard = sessionBlock(input.changes, input.lookAt, selected, input.host)
  const composition = compositionBlock(data)
  const concentration = concentrationBlock(data, selected, session)
  const health = healthBlock(data)
  const flows = flowsBlock(data, input.hues, selected, session + data.buckets.length)
  const notes = notesLineBlock(data, selected, session + data.buckets.length + data.flows.length)
  const present = (blocks: (Block | null)[]): Block[] => blocks.filter((b): b is Block => b !== null)
  // A card with nothing to show is one line: it stands on a row of its own rather than beside a card it cannot match in height.
  const quiet = data.flows.length === 0
  const rows: Block[][] = [[sessionCard], present([composition, concentration]), present([health, ...(quiet ? [] : [flows])]), ...(quiet ? [[flows]] : []), present([notes])].filter(line => line.length > 0)
  // A tall pane's spare rows go to the files a change is riskiest in and the files most depended on, rather than to blank space.
  const extra = present([spotsBlock(input), topFilesBlock(input)])
  return { top: tiles, left: [], rows, split: 0.5, order: [...tiles, sessionCard, ...present([composition, concentration, health, flows, notes])], ...(extra.length > 0 ? { extra: [extra] } : {}) }
}

/** The fewest rows a secondary list on the Overview shows when it shows at all. */
const SECONDARY_MIN = 3

/**
 * A secondary list of files on the Overview: each file's path in two tones
 * (a link that opens it), a bar and its figures. Not walked by the marker:
 * it shows only when the pane has room to spare, and the walk must not
 * depend on the height. Null with nothing to list.
 */
function fileListBlock(key: string, title: string, note: string, files: { path: string; values: number[]; bar: number; loc: Loc | null }[], titles: string[], tier: Tier, hues: Hues): Block | null {
  if (files.length === 0) return null
  return {
    key,
    grow: { length: files.length, min: SECONDARY_MIN },
    make: (columns, limit, scrolled) => {
      const max = Math.max(0, ...files.map(f => f.bar))
      const { rows, window } = windowedTable(`${key}-window`, files, { columns, limit, scrolled }, (shown, start) => {
        const spec = tableSpec(columns, shown.map(f => f.path), [], titles.map((t, i) => numberWidth(t, shown.map(f => f.values[i] ?? 0), tier)), 56, { tier })
        return [
          tableHead(`${key}-cols`, spec, { name: 'file', boundary: '', numbers: titles }),
          ...shown.map((f, n) => tableRow(`${key}-${start + n}`, { name: f.path, boundary: null, values: f.values, barValue: f.bar, max, path: true, opens: f.loc }, spec, hues)),
        ]
      })
      return { key, title, note: noteOf(note), body: rows, window }
    },
  }
}

/** The Overview's secondary card of complexity hotspots (as Issues lists them). */
const spotsBlock = (input: PaneInput): Block | null =>
  fileListBlock('hotspots', 'Complexity hotspots', 'lines × dependents', input.issues.hotspots.map(f => ({ path: f.path, values: [f.lines, f.dependents], bar: f.score, loc: f.loc })), ['lines', 'deps'], 'medium', input.hues)

/** The Overview's secondary card of the files most depended on (as Hubs lists them). */
const topFilesBlock = (input: PaneInput): Block | null =>
  fileListBlock('files', 'Files most depended on', 'dependent files', input.fileHubs.map(f => ({ path: f.path, values: [f.dependents], bar: f.dependents, loc: f.loc })), ['deps'], 'medium', input.hues)

/**
 * The pane laid out: its rows from the header down (`body`) and the footer
 * bar (`footer`). When the two fit the height, the body is filled with blank
 * rows so the bar sits at the bottom of the pane; when they do not (`pinned`),
 * the bar is drawn over the window's last rows wherever the pane is scrolled
 * to, and the body ends in as many blank rows as the bar has, so its last row
 * can still be scrolled clear of it. `offset` is how far the pane is scrolled:
 * once the header is out of view, the bar says the graph's state.
 *
 * The header has room around it: a blank row above the name and another
 * between it and the tabs, both padded in from the edges, then a blank row
 * and a rule before the first card. Narrow, the row above the name goes; on
 * a short pane (24 rows or fewer) the blank rows go first, never a line that
 * says something.
 */
export function paneLayout(input: PaneInput, columns: number, height: number = DEFAULT_ROWS, offset = 0): { body: Row[]; footer: Row[]; pinned: boolean } {
  const width = Math.max(1, columns)
  const tier = tierOf(width)
  const list = listFor(input)
  const selected = Math.min(Math.max(0, input.selected), Math.max(0, list.length - 1))
  const short = height <= SHORT_PANE
  const pad = padOf(tier)
  const inner = Math.max(1, width - 2 * pad)
  const rows: Row[] = []
  if (!short && tier !== 'narrow') rows.push(blank('head-top'))
  rows.push(padded(titleRow(input, inner, tier, width), pad, width))
  if (input.detail === null && input.route === null) {
    const count = issueCount(input.issues)
    const touched = input.changes.files.length
    const branched = branchCount(input.branch)
    const badges: Partial<Record<PaneTab, string>> = {
      ...(count.n > 0 ? { issues: superscript(count.n, count.plus) } : {}),
      ...(touched > 0 ? { changes: superscript(touched, input.changes.truncated) } : {}),
      ...(branched > 0 ? { branch: superscript(branched) } : {}),
    }
    if (!short) rows.push(blank('head-gap'))
    rows.push(...tabRows(input.tab, inner, input.terminal, badges, tier === 'narrow').map(row => padded(row, pad, width)))
  }
  if (!short) rows.push(blank('head-end'))
  rows.push({ key: 'head-rule', segments: [{ text: '─'.repeat(width), color: FAINT }] })
  const header = rows.length
  if (input.allow !== null) rows.push(blank('gap-allow-top'), ...allowRows(input.allow, width))
  const { bar } = footerRows(input, width, list.length > 0, offset >= header)
  const footer = [...(short ? [] : [blank('gap-keys')]), ...bar]
  const room = () => height - rows.length - footer.length
  if (input.showKeys) {
    // The key list stands in for whatever is open; closing it puts that back as it was.
    rows.push(...fillColumn(fitBlocks([keysBlock(input.host)], width, tier, room()), width, room(), tier))
  } else if (input.finder !== null) {
    // The finder stands in for the tab or the detail while it is open; closing it puts them back as they were.
    rows.push(...fillColumn(fitBlocks(scrolledTo({ left: [finderBlock(input.finder, selected, tier, input.hues)] }, input.scrolls).left, width, tier, room()), width, room(), tier))
  } else if (input.route !== null) {
    rows.push(...arrange(scrolledTo(routeArrangement(input.route, selected, input.hues), input.scrolls), width, room(), true))
  } else if (input.detail !== null) {
    rows.push(...arrange(scrolledTo(detailOf(input.detail, tier, input.hues, selected, input.host), input.scrolls), width, room(), true))
  } else {
    // Listed under the tabs, the drifted files hold the marker; the tab below shows none. They take a third of the room.
    const drift = input.drift
    if (input.driftOpen && drift !== null) {
      const min = Math.max(3, Math.floor(room() / 3))
      rows.push(...fitBlocks([{ key: 'drift', grow: { length: drift.items.length, min }, make: (cards, limit) => driftSection(drift, cards, limit, input.hues, selected, input.scrolls.drift) }], width, tier, 0))
    }
    const tabSelected = input.driftOpen ? -1 : selected
    const peek = input.driftOpen ? null : peekShown(input, tier)
    if (peek === null) {
      rows.push(...arrange(scrolledTo(tabArrangement(input, tabSelected, tier), input.scrolls), width, room(), true))
    } else {
      // Wide, master-detail: the tab on the left, the marked row's detail beside it, both to the pane's foot.
      // Each panel is one framed column, as a medium pane draws it, whatever its own width.
      const [left, right] = gridColumns(width, PEEK_SPLIT)
      const height = Math.max(0, room())
      const master = fillColumn(arrange(scrolledTo(tabArrangement(input, tabSelected, PANEL), input.scrolls), left, height, true, PANEL), left, height, PANEL, 'stretch-m')
      const detail = fillColumn(peekRows(peek, right, height, input.hues, input.host), right, master.length, PANEL, 'stretch-d')
      rows.push(...besideRows(master.length >= detail.length ? master : fillColumn(master, left, detail.length, PANEL, 'stretch-m'), detail, left))
    }
  }
  const body = rows.map(row => (row.code !== undefined || rowWidth(row) <= width ? row : { ...row, segments: clip(row.segments, width) }))
  // Counted as drawn: a diff element takes a row for each of its lines, so a long change pins the bar and scrolls.
  const fill = height - rowsHeight(body) - footer.length
  if (fill >= 0) return { body: [...body, ...Array.from({ length: fill }, (_, i) => blank(`fill-${i}`))], footer, pinned: false }
  return { body, footer, pinned: true }
}

/**
 * `after`, keeping the windows the wheel scrolled (`scrolls`) only while the
 * view stands as it was: once the marker moves, the tab changes, or a
 * detail, a route, the finder or the drifted files open or close, each list
 * goes back to the window round the marker.
 */
export function withScrollsKept(before: KnossosView, after: KnossosView): KnossosView {
  if (after.scrolls === undefined) return after
  const stood =
    before.selected === after.selected &&
    before.tab === after.tab &&
    before.inspect?.name === after.inspect?.name &&
    (before.route ?? null) === (after.route ?? null) &&
    (before.finding ?? false) === (after.finding ?? false) &&
    (before.drift ?? false) === (after.drift ?? false)
  if (stood) return after
  const rest = { ...after }
  delete rest.scrolls
  return rest
}

/** The tabs whose marked row shows its detail beside the list on a wide pane. */
export const PEEK_TABS: readonly PaneTab[] = ['hubs', 'changes', 'issues', 'cycles', 'branch', 'churn', 'notes']

/** The list's share of a wide pane beside the marked row's detail. */
const PEEK_SPLIT = 0.55

/** How each panel of master-detail is laid out: one column of framed cards. */
const PANEL: Tier = 'medium'

/** A component's or a file's detail arrangement. */
const detailOf = (detail: DetailInput, tier: Tier, hues: Hues, selected: number, host?: 'web'): Arrangement =>
  detail.file === undefined ? detailArrangement(detail, tier, hues, selected, host) : fileDetailArrangement(detail, tier, hues, selected, host)

/** The detail drawn beside the tab: on a wide pane, on a tab that has one, while the finder is shut and something is marked. */
function peekShown(input: PaneInput, tier: Tier): DetailInput | null {
  if (tier !== 'wide' || input.peek === null || input.finder !== null || !PEEK_TABS.includes(input.tab)) return null
  return input.peek
}

/**
 * The marked row's detail as the panel beside the tab draws it: the detail's
 * own cards at the panel's width, its presses renamed `peek:N` (they open
 * what the panel lists, not what the tab lists), and no marker of its own.
 */
function peekRows(peek: DetailInput, width: number, height: number, hues: Hues, host?: 'web'): Row[] {
  const rows = arrange(detailOf({ ...peek, panel: true }, PANEL, hues, -1, host), width, height, true, PANEL)
  // Its lists' presses open what it lists; the line under a cut one is text, since no marker walks the panel.
  const pressOf = (seg: Segment): Segment => {
    if (seg.press === undefined) return seg
    if (/^(rel|row):\d+$/.test(seg.press.id)) return { ...seg, press: { ...seg.press, id: `peek:${seg.press.id.slice(seg.press.id.indexOf(':') + 1)}` } }
    if (seg.press.id.startsWith('more:')) return { text: seg.text, dim: true }
    return seg
  }
  return rows.map(row => ({ ...row, key: `peek-${row.key}`, segments: row.segments.map(pressOf) }))
}

/** A pane this many rows tall or shorter keeps its header tight: no blank rows around it. */
export const SHORT_PANE = 24

/** The open tab's cards. */
function tabArrangement(input: PaneInput, selected: number, tier: Tier): Arrangement {
  if (input.tab === 'overview') return overviewArrangement(input, selected, tier)
  if (input.tab === 'changes') return changesArrangement(input.changes, selected, tier, input.hues, input.lit, input.host)
  if (input.tab === 'hubs') return hubsArrangement(input, selected, tier)
  if (input.tab === 'issues') return issuesArrangement(input.issues, selected, tier, input.hues)
  if (input.tab === 'cycles') return cyclesArrangement(input.cycles, tier, input.hues, selected)
  if (input.tab === 'branch') return branchArrangement(input.branch, selected, input.hues)
  if (input.tab === 'churn') return churnArrangement(input.churn, selected, tier, input.hues)
  if (input.tab === 'notes') return notesArrangement(input.notes, selected, input.hues)
  return boundariesArrangement(input.boundaries, tier, input.hues, selected, input.target, input.couplings, input.lit)
}

/** How many proportional tries {@link shrinkRows} makes before it lays the lists out at their fewest rows. */
const SHRINK_TRIES = 4

/**
 * A pane drawn with fewer rows until its weight (`draw`'s, as the engine
 * bounds a tree) is within `budget`: each try lays out fewer rows than the
 * last, in proportion to the budget, and once those tries are spent with the
 * lists still above `fewest` rows, one more draw at `fewest` decides. So at
 * most {@link SHRINK_TRIES} + 1 draws, and the caller falls back to less
 * only when even `fewest` rows are too heavy. `first` is the draw already made
 * at `rows`.
 */
export function shrinkRows<T>(rows: number, fewest: number, budget: number, first: { tree: T; weight: number }, draw: (rows: number) => { tree: T; weight: number }): { tree: T; weight: number; rows: number } {
  let drawn = { ...first, rows }
  for (let tries = 0; drawn.weight > budget && drawn.rows > fewest; tries++) {
    const next = tries < SHRINK_TRIES ? Math.max(fewest, Math.min(drawn.rows - 2, Math.floor((drawn.rows * budget) / drawn.weight) - 2)) : fewest
    drawn = { ...draw(next), rows: next }
  }
  return drawn
}
