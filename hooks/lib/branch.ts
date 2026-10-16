/**
 * The Branch tab: what the checked-out branch changed against its default
 * branch, and whether it broke anything (`knossos branch-diff`): the graph
 * now against the snapshot taken where it left the default branch.
 *
 * Pure. The answer in, cards out. The first card names the branch, how far
 * ahead it is and where it left; then the verdict: one line of all-clear
 * checks when nothing broke, else how many things to look at and a row for
 * each, the worst first (policy violations, cycles, dependencies crossing
 * into another boundary, hubs more depended on, dead code). Where nothing
 * near the merge base can be compared with, it says so plainly in the
 * verdict's place. The second card, what changed, totals the touched files
 * and lists them by boundary, the one whose changes reach the most files
 * first and the files outside the graph last; a boundary is folded until
 * `o` opens it. Every finding, boundary and file is a row the marker walks.
 */
import type { BranchDiff, BranchItem, BranchState } from '../../types'
import { stamp } from './changes'
import { noteOf, windowOf, moreRows } from './cards'
import type { Arrangement, Block, Section } from './cards'
import { ACCENT, boundaryColour, FAINT, NO_HUES, SELECTED_BG, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { bar, button, cells, chip, clip, displayName, fit, grouped, padEnd, padStart, placeOf, plural, segmentsWidth, shortName, spaces, SWATCH, tinted, wrapWords } from './rows'
import type { Loc, Row, Segment } from './rows'
import { locIn } from './views'
import type { Openable } from './views'
import { CARD_ICON, CHANGE, SEVERITY, mark } from './marks'

/** One component the Branch tab lists: what it shows, what it opens, where it is. */
export type BranchLine = { name: string; canonical: string; boundary: string | null; place: string; loc: Loc | null }

/** A file the branch touched: the lines it added and deleted, and how many files depend on it. */
export type BranchFile = { path: string; added: number; deleted: number; dependents: number; loc: Loc | null }

/** A boundary's touched files (`name` null for those outside the graph), their lines, and the files their changes reach. */
export type BranchGroup = { name: string | null; files: BranchFile[]; count: number; added: number; deleted: number; reach: number; open: boolean }

/** A thing the branch broke, as its row says it; `kind` orders them, the worst first. */
export type BranchFinding =
  | { kind: 'policy'; line: BranchLine; policy: string; target: string }
  | { kind: 'cycle'; line: BranchLine; size: number; members: BranchLine[] }
  | { kind: 'crossing'; line: BranchLine; target: BranchLine }
  | { kind: 'hub'; line: BranchLine; before: number; after: number }
  | { kind: 'dead'; line: BranchLine }

/** The Branch tab's view of the comparison. */
export type BranchInput = {
  /** The branch against its default one, how far ahead, and where it left: null when nothing was answered. */
  heading: { title: string; ahead: number | null; left: string | null } | null
  /** Where the comparison stands, as sentences, when they say more than the heading: how near the snapshot is, or why there is none. */
  said: string[]
  /** Said in the warning tone: the comparison is partial, or none could be made. */
  warn: boolean
  loading: boolean
  /** Whether anything broke: all clear, what to look at, or nothing to compare (the sentences say why). */
  verdict: { state: 'clean' | 'findings' | 'none'; checks: string[] }
  /** What broke, the worst first; how many of each kind went unlisted. */
  findings: BranchFinding[]
  unlisted: { kind: BranchFinding['kind']; count: number }[]
  /** Null when the project declares no policies, or the comparison holds no check: no violations to rule out. */
  policies: boolean
  /** The touched files, by boundary; null on the default branch, or when git could not say. */
  changed: { count: number; added: number; deleted: number; reach: number; truncated: boolean; groups: BranchGroup[] } | null
}

/** A git commit as the tab names it: its first seven characters. */
const short = (rev: string): string => rev.slice(0, 7)

/** The order findings come in, the worst first, and how an unlisted rest of each is named. */
const WORST: Record<BranchFinding['kind'], { rank: number; many: string }> = {
  policy: { rank: 0, many: 'policy violations' },
  cycle: { rank: 1, many: 'cycles' },
  crossing: { rank: 2, many: 'cross-boundary dependencies' },
  hub: { rank: 3, many: 'hubs that grew' },
  dead: { rank: 4, many: 'dead code candidates' },
}

/** A component of the answer as the tab lists it; `root` places it on disk. */
function lineOf(item: BranchItem, root: string | null): BranchLine {
  return { name: displayName({ name: item.name, canonical_name: item.canonical_name, kind: item.kind }), canonical: item.canonical_name, boundary: item.boundary, place: placeOf(item.path, item.line), loc: locIn(root, item.path, item.line) }
}

/** The fold key of a boundary's group (`branch:` for the files outside the graph). */
const foldOf = (name: string | null): string => `branch:${name ?? ''}`

/** Where the comparison stands beyond the heading, and whether that is a warning. */
function standing(answer: BranchDiff): { said: string[]; warn: boolean } {
  const target = answer.default_branch ?? 'the default branch'
  const branch = answer.branch === null || answer.branch === undefined ? 'This detached checkout' : `${answer.branch}`
  if (answer.status === 'on-default') return { said: [`${branch} is ${target} itself: there is no branch to compare.`], warn: false }
  if (answer.status === 'no-git') return { said: ['No git repository, or git did not answer: there is no branch to compare.'], warn: true }
  if (answer.status === 'no-default') return { said: ['No default branch (origin/HEAD, main or master) to compare with.'], warn: true }
  if (answer.status !== 'ok' && answer.status !== 'no-snapshot') return { said: ['knossos did not compare the branch.'], warn: true }
  const base = answer.base ?? null
  if (base === null) return { said: [`No snapshot near the merge base is retained, so there is nothing to compare with. A scan of ${target} (with snapshots kept) gives this tab its base.`], warn: true }
  const at = `${short(base.rev)}, ${base.at.replace('T', ' ').replace(/:\d\dZ$/, ' UTC')}`
  if (base.match === 'exact') return { said: [], warn: false }
  if (base.match === 'before') return { said: [`Compared with the nearest snapshot before the merge base (${at}, ${plural(base.commits, 'commit', 'commits')} earlier): what ${target} changed in between counts as the branch's.`], warn: false }
  return {
    said: [`No snapshot near the merge base is retained. Compared with the oldest one after it (${at}), which already holds ${grouped(base.commits)} of the branch's ${grouped(answer.ahead ?? base.commits)} commits: what those changed is not shown.`],
    warn: true,
  }
}

/** The touched files by boundary: the answer's own groups, or, from a knossos that sends none, grouped here (reach unknown, 0). */
function changedOf(f: NonNullable<BranchDiff['files']>, root: string | null, folds: string[]): NonNullable<BranchInput['changed']> {
  const file = (i: (typeof f.items)[number]): BranchFile => ({ path: i.path, added: i.added, deleted: i.deleted, dependents: i.dependents, loc: locIn(root, i.path) })
  const inGraph = (i: (typeof f.items)[number]) => i.in_graph !== false
  const keyOf = (i: (typeof f.items)[number]) => (inGraph(i) ? i.boundary : null)
  const sent =
    f.boundaries ??
    [...new Set(f.items.map(keyOf))].map(name => {
      const of = f.items.filter(i => keyOf(i) === name)
      return { boundary: name, files: of.length, added: of.reduce((s, i) => s + i.added, 0), deleted: of.reduce((s, i) => s + i.deleted, 0), reach: 0 }
    })
  const groups = sent.map(
    (g): BranchGroup => ({ name: g.boundary, files: f.items.filter(i => keyOf(i) === g.boundary).map(file), count: g.files, added: g.added, deleted: g.deleted, reach: g.reach, open: folds.includes(foldOf(g.boundary)) }),
  )
  return { count: f.count, added: f.items.reduce((s, i) => s + i.added, 0), deleted: f.items.reduce((s, i) => s + i.deleted, 0), reach: f.reach ?? 0, truncated: f.truncated === true, groups }
}

/** The tab's view of the stored comparison; `root` places each component on disk, `folds` names the boundaries opened. */
export function branchInput(state: BranchState | null, root: string | null, folds: string[] = []): BranchInput {
  const nothing = { heading: null, findings: [], unlisted: [], policies: false, changed: null }
  // A comparison for a newer graph on its way keeps the last one on show, saying so.
  const loading = state === null || state.phase === 'loading'
  const answer = state?.answer ?? null
  if (answer === null && loading) return { said: ['Comparing the branch with its merge base…'], warn: false, loading: true, verdict: { state: 'none', checks: [] }, ...nothing }
  if (answer === null) return { said: ['knossos did not answer; the tab asks again when the graph moves.'], warn: true, loading: false, verdict: { state: 'none', checks: [] }, ...nothing }
  const { said, warn } = standing(answer)
  const target = answer.default_branch ?? 'the default branch'
  const mb = answer.merge_base
  const heading = answer.status === 'on-default' || answer.status === 'no-git' || answer.status === 'no-default' ? null : {
    title: `${answer.branch ?? 'This detached checkout'} against ${target}`,
    ahead: answer.ahead ?? null,
    left: mb === null || mb === undefined ? null : `left ${target} at ${short(mb.rev)} (${stamp(mb.at * 1000)})`,
  }
  const changed = answer.files === null || answer.files === undefined ? null : changedOf(answer.files, root, folds)
  const c = answer.comparison ?? null
  if (c === null) return { heading, said, warn, loading, verdict: { state: 'none', checks: [] }, findings: [], unlisted: [], policies: false, changed }
  const line = (item: BranchItem) => lineOf(item, root)
  const findings: BranchFinding[] = [
    ...(c.violations?.items ?? []).map((v): BranchFinding => ({ kind: 'policy', line: { name: shortName(v.source), canonical: v.source, boundary: null, place: '', loc: null }, policy: v.policy_id, target: shortName(v.target) })),
    ...c.cycles.items.flatMap((i): BranchFinding[] => (i.members[0] === undefined ? [] : [{ kind: 'cycle', line: line(i.members[0]), size: i.size, members: i.members.map(line) }])),
    ...c.crossing.items.map((i): BranchFinding => ({ kind: 'crossing', line: line(i.source), target: line(i.target) })),
    ...c.hubs.items.map((i): BranchFinding => ({ kind: 'hub', line: line(i.component), before: i.before, after: i.after })),
    ...c.dead_code.items.map((i): BranchFinding => ({ kind: 'dead', line: line(i) })),
  ]
  const counted: [BranchFinding['kind'], number][] = [['policy', c.violations?.count ?? 0], ['cycle', c.cycles.count], ['crossing', c.crossing.count], ['hub', c.hubs.count], ['dead', c.dead_code.count]]
  const unlisted = counted.map(([kind, count]) => ({ kind, count: count - findings.filter(f => f.kind === kind).length })).filter(u => u.count > 0)
  const policies = c.violations !== null
  const checks = ['no new cycles', 'no hubs grew', 'no dead code', ...(policies ? ['no violations'] : [])]
  const total = counted.reduce((s, [, n]) => s + n, 0)
  return { heading, said, warn, loading, verdict: { state: total === 0 ? 'clean' : 'findings', checks }, findings, unlisted, policies, changed }
}

/** How many things the comparison found: what the tab's badge counts (the touched files are no finding). */
export function branchCount(input: BranchInput): number {
  return input.findings.length + input.unlisted.reduce((s, u) => s + u.count, 0)
}

/** What the tab walks: each finding, then each boundary of what changed and, under an opened one, its files. */
export function branchList(input: BranchInput): Openable[] {
  const open = (l: BranchLine): Openable => ({ name: l.name, canonical: l.canonical, loc: l.loc })
  return [
    ...input.findings.map(f => open(f.line)),
    ...(input.changed?.groups ?? []).flatMap((g): Openable[] => [
      { name: g.name ?? 'outside the graph', canonical: g.name ?? '', inert: true, fold: foldOf(g.name) },
      ...(g.open ? g.files.map((f): Openable => ({ name: f.path, canonical: f.path, loc: f.loc, file: true })) : []),
    ]),
  ]
}

/** The fewest findings the verdict lists, however short the pane. */
const FINDINGS_MIN = 3

/** The fewest rows the list of what changed shows, however short the pane. */
const CHANGED_MIN = 3

/** One walked row: the marker, then `content` fitted to what is left, tinted when marked. */
function itemRow(key: string, marked: boolean, content: Segment[], columns: number): Row {
  const kept = clip([{ text: marked ? '›' : ' ', color: ACCENT, bold: true }, { text: ' ' }, ...content], columns)
  if (!marked) return { key, segments: kept }
  const used = segmentsWidth(kept)
  return { key, segments: tinted([...kept, ...(used < columns ? [{ text: spaces(columns - used) }] : [])], SELECTED_BG), tint: SELECTED_BG }
}

/** A component's name as a pressable cut to `width`, then its boundary as a chip when it fits. */
function named(line: BranchLine, press: string, width: number, hues: Hues): Segment[] {
  const own = chip(line.boundary, hues)
  const room = Math.max(1, width - (own.length > 0 ? segmentsWidth(own) + 1 : 0))
  const name = fit(line.name, room)
  return [button(press, name), ...(own.length > 0 && cells(name) + 1 + segmentsWidth(own) <= width ? [{ text: ' ' }, ...own] : [])]
}

/** A finding's row: its mark in its colour, then what it is. */
function findingRow(f: BranchFinding, at: number, selected: number, columns: number, hues: Hues): Row {
  const press = `row:${at}`
  const room = Math.max(8, columns - 4)
  const half = Math.max(8, Math.floor((room - 3) / 2))
  const content: Segment[] =
    f.kind === 'policy'
      ? [mark(SEVERITY.error), { text: ' ' }, button(press, fit(f.line.name, half)), { text: ' → ', dim: true }, { text: fit(f.target, Math.max(1, room - half - 5)) }, { text: `  ${f.policy}`, dim: true }]
      : f.kind === 'cycle'
        ? [{ text: CARD_ICON.cycles, color: STATUS_COLOURS.warn }, { text: ` ${f.size} `, dim: true }, button(press, fit(f.line.name, half)), { text: ` → ${f.members.slice(1).map(m => m.name).join(' → ')}${f.size > f.members.length ? ' → …' : ''}`, dim: true }]
        : f.kind === 'crossing'
          ? [mark(SEVERITY.warn), { text: ' ' }, ...named(f.line, press, half, hues), { text: ' → ', dim: true }, ...chip(f.target.boundary, hues), { text: ` ${fit(f.target.name, Math.max(1, room - half - 8 - cells(f.target.boundary ?? '')))}` }]
          : f.kind === 'hub'
            ? [mark(SEVERITY.warn), { text: ' ' }, ...named(f.line, press, room - 16, hues), { text: `  ${grouped(f.before)} → ${grouped(f.after)} `, dim: true }, { text: `+${grouped(f.after - f.before)}`, color: STATUS_COLOURS.warn }]
            : [mark(SEVERITY.warn), { text: ' ' }, ...named(f.line, press, Math.max(8, room - 2 - Math.min(cells(f.line.place), Math.floor(room / 3))), hues), ...(f.line.place === '' ? [] : [{ text: '  ' }, { text: fit(f.line.place, Math.max(4, Math.floor(room / 3))), dim: true, ...(f.line.loc === null ? {} : { link: f.line.loc }) }])]
  return itemRow(`finding-${at}`, at === selected, content, columns)
}

/** The verdict as rows: all clear on one line, or what to look at, then a row per finding (`limit` of them round the marker) and a line per kind left unlisted. */
function verdictRows(input: BranchInput, selected: number, columns: number, limit: number, hues: Hues): Row[] {
  if (input.verdict.state === 'clean') {
    return [{ key: 'branch-verdict', segments: clip(input.verdict.checks.flatMap((c, i): Segment[] => [{ text: i === 0 ? '   ' : '   ' }, { text: `${SEVERITY.ok.glyph} ${c}`, color: STATUS_COLOURS.ok }]), columns) }]
  }
  const count = branchCount(input)
  const head: Row = { key: 'branch-verdict', segments: clip([{ text: '   ' }, { text: `${SEVERITY.warn.glyph} ${plural(count, 'thing', 'things')} to look at`, color: STATUS_COLOURS.warn, bold: true }], columns) }
  const window = windowOf(input.findings.length, limit, selected < input.findings.length ? selected : -1)
  const rows = input.findings.slice(window.start, window.end).map((f, n) => findingRow(f, window.start + n, selected, columns, hues))
  const rest = input.unlisted.map((u): Row => ({ key: `branch-unlisted-${u.kind}`, segments: [{ text: fit(`    +${grouped(u.count)} more ${WORST[u.kind].many} not listed`, columns), dim: true }] }))
  return [head, ...rows, ...moreRows('branch-findings-window', window, input.findings.length, columns, 0), ...rest]
}

/** A boundary's row: its swatch and name, a bar by lines changed, then its files, lines and the files it reaches. */
function groupRow(g: BranchGroup, at: number, selected: number, widest: number, most: number, columns: number, hues: Hues): Row {
  const label = g.name === null ? 'outside the graph' : g.name
  const name = fit(label, Math.min(24, Math.max(8, Math.floor(columns / 4))))
  const lines: Segment[] = [{ text: `+${grouped(g.added)}`, color: STATUS_COLOURS.ok }, { text: ' ' }, { text: `${CHANGE.removed.glyph}${grouped(g.deleted)}`, color: STATUS_COLOURS.alert }]
  const figures: Segment[] = [{ text: `  ${padStart(plural(g.count, 'file', 'files'), 9)}  `, dim: true }, ...lines, { text: `  ${padStart(g.name === null ? '' : grouped(g.reach), 6)}`, color: g.name === null ? FAINT : undefined }]
  const room = Math.max(4, columns - 4 - widest - 1 - segmentsWidth(figures) - 2)
  const glyphs = bar(g.added + g.deleted, most, room)
  const colour = g.name === null ? FAINT : (boundaryColour(g.name, hues) ?? FAINT)
  const swatch: Segment = { text: `${g.open ? '▾' : '▸'} `, dim: true }
  const content: Segment[] = [swatch, ...(g.name === null ? [{ text: '· ', dim: true }] : [{ text: `${SWATCH} `, color: colour }]), button(`row:${at}`, name), { text: spaces(Math.max(1, widest - cells(name) + 1)) }, { text: glyphs, color: colour }, { text: spaces(room - cells(glyphs)) }, ...figures]
  return itemRow(`group-${at}`, at === selected, content, columns)
}

/** A touched file's row under its opened boundary: its path, its lines and its dependents. */
function fileRow(f: BranchFile, at: number, selected: number, columns: number): Row {
  const lines: Segment[] = [{ text: `+${grouped(f.added)}`, color: STATUS_COLOURS.ok }, { text: ' ' }, { text: `${CHANGE.removed.glyph}${grouped(f.deleted)}`, color: STATUS_COLOURS.alert }]
  const deps = `${grouped(f.dependents)} deps`
  const room = Math.max(8, columns - 6 - segmentsWidth(lines) - cells(deps) - 4)
  const path = fit(f.path, room)
  return itemRow(`file-${at}`, at === selected, [{ text: '    ' }, button(`row:${at}`, path), { text: spaces(Math.max(1, room - cells(path) + 1)) }, ...lines, { text: '  ' }, { text: deps, dim: true }], columns)
}

/**
 * The Branch tab: the branch and its verdict, then what changed by boundary,
 * one column at every width, the list of what changed growing into the
 * rows the pane has.
 */
export function branchArrangement(input: BranchInput, selected: number, hues: Hues = NO_HUES): Arrangement {
  const head: Block = {
    key: 'branch',
    ...(input.findings.length === 0 ? {} : { grow: { length: input.findings.length, min: FINDINGS_MIN } }),
    make: (columns, limit) => {
      const said = input.said.flatMap((text, i) =>
        wrapWords(text, Math.max(1, columns - 3)).map((part, j): Row => ({ key: `branch-said-${i}-${j}`, segments: [{ text: `${input.warn && j === 0 ? SEVERITY.warn.glyph : ' '}  `, color: STATUS_COLOURS.warn }, { text: part, ...(input.warn ? { color: STATUS_COLOURS.warn } : { dim: true }) }] })),
      )
      const left: Row[] = input.heading?.left === null || input.heading === null ? [] : [{ key: 'branch-left', segments: [{ text: fit(`   ${input.heading.left}`, columns), dim: true }] }]
      const verdict = input.verdict.state === 'none' ? [] : verdictRows(input, selected, columns, limit, hues)
      const note = input.loading ? 'comparing…' : input.heading?.ahead === null || input.heading === null ? '' : `${plural(input.heading.ahead, 'commit', 'commits')} ahead`
      return { key: 'branch', title: input.heading?.title ?? 'Branch', glyph: CARD_ICON.branch, ...(note === '' ? {} : { note: noteOf(note) }), body: [...left, ...verdict, ...said] }
    },
  }
  const changed = input.changed
  if (changed === null) return { left: [head] }
  const at0 = input.findings.length
  // The rows of what changed in walk order: each boundary, then its files when opened.
  const walk: ({ kind: 'group'; g: BranchGroup } | { kind: 'file'; f: BranchFile })[] = changed.groups.flatMap(g => [{ kind: 'group' as const, g }, ...(g.open ? g.files.map(f => ({ kind: 'file' as const, f })) : [])])
  const list: Block = {
    key: 'branch-changed',
    grow: { length: walk.length, min: CHANGED_MIN },
    make: (columns, limit) => {
      const total = `${plural(changed.count, 'file', 'files')}  +${grouped(changed.added)} ${CHANGE.removed.glyph}${grouped(changed.deleted)} · reach ${grouped(changed.reach)}${changed.truncated ? ' · first 300 listed' : ''}`
      const section = (body: Row[]): Section => ({ key: 'branch-changed', title: 'What changed', glyph: CARD_ICON['branch-files'], note: noteOf(changed.count === 0 ? 'none' : total), body })
      if (walk.length === 0) return section([])
      const widest = Math.min(24, Math.max(8, Math.floor(columns / 4)), Math.max(...changed.groups.map(g => cells(g.name ?? 'outside the graph'))))
      const most = Math.max(1, ...changed.groups.map(g => g.added + g.deleted))
      const local = selected - at0
      const window = windowOf(walk.length, limit, local >= 0 && local < walk.length ? local : -1)
      const head: Row = { key: 'branch-changed-head', segments: clip([{ text: `${padEnd('', columns - 7 - 9)}` }, { text: padStart('reach', 7), dim: true }], columns) }
      const rows = walk.slice(window.start, window.end).map((r, n) => (r.kind === 'group' ? groupRow(r.g, at0 + window.start + n, selected, widest, most, columns, hues) : fileRow(r.f, at0 + window.start + n, selected, columns)))
      return section([head, ...rows, ...moreRows('branch-changed-window', window, walk.length, columns, at0)])
    },
  }
  return { left: [head, list] }
}
