/**
 * The plugin's settings are written down in three places: the userConfig in
 * .claude-plugin/plugin.json (what Claude Code shows and fills in), the
 * constants the mod falls back to, and the settings table in the pane's
 * documentation. Each default and each range must be the same in all three.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { DEFAULT_EDITOR } from '../lib/editor'
import { DEFAULT_WATCH_POLL_MS, MAX_WATCH_POLL_MS, MIN_WATCH_POLL_MS } from '../lib/live'
import { DEFAULT_THRESHOLD, MAX_THRESHOLD, initialAgent, initialAlerts, initialSession, initialWatch } from './state'

const at = (path: string): string => fileURLToPath(new URL(`../../${path}`, import.meta.url))
const manifest = JSON.parse(readFileSync(at('.claude-plugin/plugin.json'), 'utf8')) as {
  userConfig: Record<string, { type: string; description: string; default: unknown }>
}
const settings = manifest.userConfig

/** The documentation's settings table, by setting: its default and what it says, code marks dropped. */
function docTable(): Map<string, { fallback: string; text: string }> {
  const text = readFileSync(at('docs/claude-code/pane/index.md'), 'utf8')
  const start = text.indexOf('| setting ')
  expect(start).toBeGreaterThan(-1)
  const rows = new Map<string, { fallback: string; text: string }>()
  for (const line of text.slice(start).split('\n').slice(2)) {
    if (!line.startsWith('|')) break
    const [name, fallback, said] = line.split('|').slice(1, -1).map(cell => cell.replace(/`/g, '').trim())
    rows.set(name!, { fallback: fallback!, text: said! })
  }
  return rows
}

/** A number as the documentation writes it, with thousands separated by commas. */
const written = (n: number): string => n.toLocaleString('en-US')

/** What the mod does with a setting nobody set, for each one it falls back on itself. */
const UNSET: Record<string, unknown> = {
  fanInThreshold: initialSession({}, 'claude').threshold,
  enforcePolicies: initialSession({}, 'claude').enforce,
  watch: initialWatch({}).on,
  watchPollMs: initialWatch({}).pollMs,
  agentNotes: initialAgent({}).notesOn,
  notifications: initialAlerts({}).on,
  editor: DEFAULT_EDITOR,
}

describe('the plugin settings', () => {
  it('are the same settings, with the same defaults, in plugin.json and in the documentation', () => {
    const docs = docTable()
    expect([...docs.keys()].sort()).toEqual(Object.keys(settings).sort())
    for (const [name, setting] of Object.entries(settings)) expect(docs.get(name)!.fallback, name).toBe(String(setting.default))
  })

  it('default in plugin.json to what the mod falls back on when nothing is set', () => {
    for (const [name, fallback] of Object.entries(UNSET)) expect(settings[name]!.default, name).toEqual(fallback)
    expect(settings.fanInThreshold!.default).toBe(DEFAULT_THRESHOLD)
    expect(settings.watchPollMs!.default).toBe(DEFAULT_WATCH_POLL_MS)
  })

  it('read the two the mod checks before its state exists the way their defaults say', () => {
    const register = readFileSync(at('hooks/register.tsx'), 'utf8')
    // On unless set to false; off unless set to true.
    expect(settings.enabled!.default).toBe(true)
    expect(register).toContain('options.enabled === false')
    expect(settings.openPaneOnStart!.default).toBe(false)
    expect(register).toContain('options.openPaneOnStart === true')
  })

  it('state the ranges the mod accepts, in plugin.json and in the documentation', () => {
    const docs = docTable()
    expect(settings.fanInThreshold!.description).toContain(`1 to ${MAX_THRESHOLD}`)
    expect(docs.get('fanInThreshold')!.text).toContain(`1 to ${written(MAX_THRESHOLD)}`)
    expect(settings.watchPollMs!.description).toContain(`${MIN_WATCH_POLL_MS} to ${MAX_WATCH_POLL_MS} milliseconds`)
    expect(docs.get('watchPollMs')!.text).toContain(`${MIN_WATCH_POLL_MS} to ${written(MAX_WATCH_POLL_MS)} milliseconds`)
  })

  it('call what the model is told a message, as the documentation does', () => {
    expect(settings.fanInThreshold!.description).toContain('gets a message')
    expect(docTable().get('fanInThreshold')!.text).toContain('gets a message')
  })
})
