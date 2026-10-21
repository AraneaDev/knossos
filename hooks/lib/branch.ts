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
import { noteOf, windowedTable } from './cards'
import type { Arrangement, Block, Section } from './cards'
import { boundaryColour, FAINT, NO_HUES, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { button, clip, inList, notListedRow, segmentsWidth, wrapGroups, walkedRow } from './rows'
import { bar } from './table'
import { chip, SWATCH } from './chips'
import { cells, displayName, fit, grouped, padStart, plural, shortName, spaces, wrapWords } from './text'
import { opensFile } from './editor'
import { placeOf } from './paths'
import type { Loc, Row, Segment } from './types'
import { locIn } from './views'
import type { Openable } from './views'
import { CARD_ICON, CHANGE, SEVERITY, mark } from './marks'
import { busy } from './motion'
import { countLabel } from './envelopes'
import { unreadablePolicies, unreadableText } from './policies'

/** One component the Branch tab lists: what it shows, what it opens, where it is. */
export type BranchLine = { name: string; canonical: string; boundary: string | null; place: string; loc: Loc | null }

/** A file the branch touched: the lines it added and deleted, and how many files depend on it. */
export type BranchFile = { path: string; added: number; deleted: number; dependents: number; loc: Loc | null }

/** A boundary's touched files (`name` null for files no boundary claims, `outside` for those the graph does not hold), their lines, and the files their changes reach. */
export type BranchGroup = { name: string | null; outside: boolean; files: BranchFile[]; count: number; added: number; deleted: number; reach: number; open: boolean }

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
  /** The policy check stopped at its edge or time limit: its count is a floor, and the verdict is never all clear. */
  partial: boolean
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

/** The fold key of a group: its boundary, `branch:` for files no boundary claims, and one no boundary name can be for the files outside the graph. */
const foldOf = (name: string | null, outside: boolean): string => (outside ? 'branch:\u0000outside' : `branch:${name ?? ''}`)

/** How a group is named on its row. */
const labelOf = (g: Pick<BranchGroup, 'name' | 'outside'>): string => (g.outside ? 'outside the graph' : (g.name ?? 'no boundary'))

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
  // A file belongs to a group by its boundary and whether the graph holds it: no boundary and outside are two groups.
  const keyOf = (boundary: string | null, outside: boolean) => (outside ? '\u0000outside' : (boundary ?? ''))
  const itemKey = (i: (typeof f.items)[number]) => keyOf(i.boundary, i.in_graph === false)
  const sent =
    f.boundaries ??
    [...new Set(f.items.map(itemKey))].map(key => {
      const of = f.items.filter(i => itemKey(i) === key)
      return { boundary: of[0]!.boundary, outside: of[0]!.in_graph === false, files: of.length, added: of.reduce((s, i) => s + i.added, 0), deleted: of.reduce((s, i) => s + i.deleted, 0), reach: 0 }
    })
  const groups = sent.map((g): BranchGroup => {
    const outside = g.outside === true
    return { name: g.boundary, outside, files: f.items.filter(i => itemKey(i) === keyOf(g.boundary, outside)).map(file), count: g.files, added: g.added, deleted: g.deleted, reach: g.reach, open: folds.includes(foldOf(g.boundary, outside)) }
  })
  return { count: f.count, added: f.added ?? f.items.reduce((s, i) => s + i.added, 0), deleted: f.deleted ?? f.items.reduce((s, i) => s + i.deleted, 0), reach: f.reach ?? 0, truncated: f.truncated === true, groups }
}

/** The tab's view of the stored comparison; `root` places each component on disk, `folds` names the boundaries opened. */
export function branchInput(state: BranchState | null, root: string | null, folds: string[] = []): BranchInput {
  const nothing = { heading: null, findings: [], unlisted: [], policies: false, partial: false, changed: null }
  // A comparison for a newer graph on its way keeps the last one on show, saying so.
  const loading = state === null || state.phase === 'loading'
  const answer = state?.answer ?? null
  if (answer === null && loading) return { said: [busy('Comparing the branch with its merge base…')], warn: false, loading: true, verdict: { state: 'none', checks: [] }, ...nothing }
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
  if (c === null) return { heading, said, warn, loading, verdict: { state: 'none', checks: [] }, findings: [], unlisted: [], policies: false, partial: false, changed }
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
  // Policies knossos could not read were not checked: no "no violations" to claim, and the reason said.
  const reason = unreadablePolicies(c.violations)
  const unreadable = reason !== null
  const policies = c.violations !== null && !unreadable
  const partial = policies && c.violations?.truncated === true
  const checks = ['no new cycles', 'no new crossings', 'no hubs grew', 'no dead code', ...(policies ? ['no violations'] : [])]
  const total = counted.reduce((s, [, n]) => s + n, 0)
  const notes = unreadable ? { said: [...said, unreadableText(reason)], warn: true } : { said, warn }
  return { heading, ...notes, loading, verdict: { state: total === 0 && !partial ? 'clean' : 'findings', checks }, findings, unlisted, policies, partial, changed }
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
      { name: labelOf(g), canonical: g.name ?? '', inert: true, fold: foldOf(g.name, g.outside) },
      ...(g.open ? g.files.map((f): Openable => ({ name: f.path, canonical: f.path, loc: f.loc, file: true })) : []),
    ]),
  ]
}

/** The cells a group's reach takes at its row's end, and the column title over it. */
const REACH_CELLS = 6

/** The fewest cells a bar is drawn in: narrower, it gives way. */
const BAR_MIN = 4

/** The fewest findings the verdict lists, however short the pane. */
const FINDINGS_MIN = 3

/** The fewest rows the list of what changed shows, however short the pane. */
const CHANGED_MIN = 3

/** One walked row: the marker and a space, then `content` fitted to what is left, tinted when marked. */
function itemRow(key: string, marked: boolean, content: Segment[], columns: number): Row {
  return walkedRow(key, marked, [{ text: ' ' }, ...content], columns)
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
            : [mark(SEVERITY.warn), { text: ' ' }, ...named(f.line, press, Math.max(8, room - 2 - Math.min(cells(f.line.place), Math.floor(room / 3))), hues), ...(f.line.place === '' ? [] : [{ text: '  ' }, opensFile(fit(f.line.place, Math.max(4, Math.floor(room / 3))), f.line.loc, { dim: true })])]
  return itemRow(`finding-${at}`, at === selected, content, columns)
}

/** The verdict as rows: all clear on one line, or what to look at, then a row per finding (`limit` of them round the marker) and a line per kind left unlisted. */
function verdictRows(input: BranchInput, selected: number, columns: number, limit: number, hues: Hues): Row[] {
  if (input.verdict.state === 'clean') {
    // Each check whole, wrapped onto as many lines as the width needs.
    return wrapGroups('branch-verdict', input.verdict.checks.map((c): Segment[] => [{ text: `${SEVERITY.ok.glyph} ${c}`, color: STATUS_COLOURS.ok }]), columns, 3, 3)
  }
  const count = branchCount(input)
  // A check that stopped early found at least this many: "3+ things", or with none found, that it is not done.
  const looked = !input.partial ? `${plural(count, 'thing', 'things')} to look at` : count === 0 ? 'policies not fully checked' : `${countLabel(count, true)} things to look at`
  const head: Row = { key: 'branch-verdict', segments: clip([{ text: '   ' }, { text: `${SEVERITY.warn.glyph} ${looked}`, color: STATUS_COLOURS.warn, bold: true }], columns) }
  const { rows } = windowedTable('branch-findings-window', input.findings, { columns, limit, local: inList(selected, input.findings.length), walk: 0 }, (shown, start) =>
    shown.map((f, n) => findingRow(f, start + n, selected, columns, hues)),
  )
  const rest = input.unlisted.flatMap(u => notListedRow(`branch-unlisted-${u.kind}`, u.count, 0, columns, { kind: WORST[u.kind].many, indent: 4 }))
  const stopped: Row[] = input.partial ? [{ key: 'branch-partial', segments: [{ text: fit('    the policy check stopped at its edge or time limit: more violations may be new', columns), dim: true }] }] : []
  return [head, ...rows, ...rest, ...stopped]
}

/**
 * A boundary's row: its swatch and name, a bar by lines changed, then its
 * files, lines and the files it reaches. Narrow, the bar gives way first,
 * then the file count; the reach the list is sorted by always shows.
 */
function groupRow(g: BranchGroup, at: number, selected: number, widest: number, most: number, columns: number, hues: Hues): Row {
  const name = fit(labelOf(g), widest)
  const lines: Segment[] = [{ text: `+${grouped(g.added)}`, color: STATUS_COLOURS.ok }, { text: ' ' }, { text: `${CHANGE.removed.glyph}${grouped(g.deleted)}`, color: STATUS_COLOURS.alert }]
  const reach: Segment = { text: `  ${padStart(g.outside ? '' : grouped(g.reach), REACH_CELLS)}` }
  const files: Segment = { text: `  ${padStart(plural(g.count, 'file', 'files'), 9)}  `, dim: true }
  const colour = g.outside ? FAINT : g.name === null ? FAINT : (boundaryColour(g.name, hues) ?? FAINT)
  const lead: Segment[] = [{ text: `${g.open ? '▾' : '▸'} `, dim: true }, g.outside || g.name === null ? { text: '· ', dim: true } : { text: `${SWATCH} `, color: colour }, button(`row:${at}`, name), { text: spaces(Math.max(1, widest - cells(name) + 1)) }]
  // What is left for the bar after the marker (4), the lead, the files, the lines and the reach.
  const room = columns - 4 - segmentsWidth(lead) - segmentsWidth([files, ...lines, reach])
  const withFiles = room >= BAR_MIN
  const tail: Segment[] = withFiles ? [files, ...lines, reach] : [{ text: '  ' }, ...lines, reach]
  const barRoom = columns - 4 - segmentsWidth(lead) - segmentsWidth(tail)
  const glyphs = barRoom >= BAR_MIN ? bar(g.added + g.deleted, most, barRoom) : ''
  const content: Segment[] = [...lead, ...(glyphs === '' ? [{ text: spaces(Math.max(0, barRoom)) }] : [{ text: glyphs, color: colour }, { text: spaces(barRoom - cells(glyphs)) }]), ...tail]
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
      const note = input.loading ? busy('comparing…') : input.heading?.ahead === null || input.heading === null ? '' : `${plural(input.heading.ahead, 'commit', 'commits')} ahead`
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
    make: (columns, limit, scrolled) => {
      const total = `${plural(changed.count, 'file', 'files')}  +${grouped(changed.added)} ${CHANGE.removed.glyph}${grouped(changed.deleted)} · reach ${grouped(changed.reach)}${changed.truncated ? ' · first 300 listed' : ''}`
      const section = (body: Row[]): Section => ({ key: 'branch-changed', title: 'What changed', glyph: CARD_ICON['branch-files'], note: noteOf(changed.count === 0 ? 'none' : total), body })
      if (walk.length === 0) return section([])
      const widest = Math.min(24, Math.max(8, Math.floor(columns / 4)), Math.max(...changed.groups.map(g => cells(labelOf(g)))))
      const most = Math.max(1, ...changed.groups.map(g => g.added + g.deleted))
      // The reach column's title over the figures it heads, both ending at the card's edge.
      const head: Row = { key: 'branch-changed-head', segments: [{ text: spaces(Math.max(0, columns - REACH_CELLS)) }, { text: padStart('reach', REACH_CELLS), dim: true }] }
      const { rows, window } = windowedTable('branch-changed-window', walk, { columns, limit, local: inList(selected - at0, walk.length), scrolled, walk: at0 }, (shown, start) => [
        head,
        ...shown.map((r, n) => (r.kind === 'group' ? groupRow(r.g, at0 + start + n, selected, widest, most, columns, hues) : fileRow(r.f, at0 + start + n, selected, columns))),
      ])
      return { ...section(rows), window }
    },
  }
  return { left: [head, list] }
}
