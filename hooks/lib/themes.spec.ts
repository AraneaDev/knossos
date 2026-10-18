import { describe, expect, it } from 'vitest'
import * as palette from './palette'
import { RASTER_THEMES } from './raster'
import { THEMES, themeColour } from './themes'

/** Every theme key the pane can colour a segment with: palette.ts's constants and tables, each language chip, and the diff and page grounds. */
function paneKeys(): string[] {
  const keys = new Set<string>()
  for (const value of Object.values(palette)) {
    if (typeof value === 'string') keys.add(value)
    else if (Array.isArray(value)) value.forEach(v => keys.add(v as string))
    else if (value !== null && typeof value === 'object' && !(value instanceof Map)) Object.values(value).forEach(v => keys.add(v as string))
  }
  for (const language of ['php', 'ts', 'js', 'py', 'rs', 'typescript', 'javascript', 'python', 'rust', 'go']) keys.add(palette.languageColour(language))
  for (const key of ['diffAdded', 'diffRemoved', 'diffAddedWord', 'diffRemovedWord', 'selectionBg', 'background']) keys.add(key)
  return [...keys]
}

describe('THEMES', () => {
  it('gives every key the pane emits a colour in both themes', () => {
    const keys = paneKeys()
    expect(keys).toContain('claude')
    expect(keys).toContain('blue_FOR_SUBAGENTS_ONLY')
    for (const theme of ['dark', 'light'] as const) {
      for (const key of keys) expect(themeColour(key, theme), `${theme}.${key}`).toMatch(/^#[0-9a-f]{6}$/)
    }
  })
  it('holds Claude Code values as #rrggbb', () => {
    expect(THEMES.dark.text).toBe('#ffffff')
    expect(THEMES.light.error).toBe('#ab2b3f')
    for (const theme of Object.values(THEMES)) for (const value of Object.values(theme)) expect(value).toMatch(/^#[0-9a-f]{6}$/)
  })
  it('passes a raw colour through and knows no other', () => {
    expect(themeColour('#7aa2f7', 'light')).toBe('#7aa2f7')
    expect(themeColour('nonsense', 'dark')).toBeUndefined()
    expect(themeColour(undefined, 'dark')).toBeUndefined()
  })
  it('is what the raster draws its dark and light grids with', () => {
    for (const theme of ['dark', 'light'] as const) {
      for (const key of ['text', 'inactive', 'subtle', 'suggestion', 'success', 'warning', 'error', 'userMessageBackground', 'blue_FOR_SUBAGENTS_ONLY']) {
        expect(RASTER_THEMES[theme][key], key).toBe(parseInt(THEMES[theme][key]!.slice(1), 16))
      }
    }
    expect(RASTER_THEMES.dark.text).toBe(0xffffff)
    expect(RASTER_THEMES.light.userMessageBackground).toBe(0xf0f0f0)
  })
})
