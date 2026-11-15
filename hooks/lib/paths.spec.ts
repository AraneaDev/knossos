import { describe, expect, it } from 'vitest'
import { placeText, relativise } from './paths'
import { placeLeast, placeSegments } from './text'

describe('relativise', () => {
  it('strips the project root', () => expect(relativise('/repo', '/repo/src/a.php')).toBe('src/a.php'))
  it('tolerates a trailing slash on the root', () => expect(relativise('/repo/', '/repo/a.php')).toBe('a.php'))
  it('rejects a sibling with a shared prefix', () => expect(relativise('/repo', '/repo2/a.php')).toBeNull())
  it('rejects a path outside the root', () => expect(relativise('/repo', '/etc/passwd')).toBeNull())
  it('keeps spaces and unicode', () => expect(relativise('/repo', '/repo/a b/ü.php')).toBe('a b/ü.php'))
  it('normalises dot segments', () => expect(relativise('/repo', '/repo/src/../a.php')).toBe('a.php'))
})

describe('the one form of a place', () => {
  it('says a place as its path from the project root and its line', () => {
    expect(placeText('src/Query/DashboardService.php', 28)).toBe('src/Query/DashboardService.php:28')
    expect(placeText('bin/knossos', null)).toBe('bin/knossos')
    expect(placeText(null, 3)).toBe('')
  })
  it('draws it cut from the front, its folder dim and its file name and line bright, the file opening it', () => {
    const loc = { path: '/w/src/Query/DashboardService.php', line: 28 }
    expect(placeSegments('src/Query/DashboardService.php:28', 40, loc)).toEqual([
      { text: 'src/Query/', dim: true },
      { text: 'DashboardService.php:28', press: { id: 'file:/w/src/Query/DashboardService.php', label: 'DashboardService.php:28' } },
    ])
    expect(placeSegments('src/Query/DashboardService.php:28', 26).map(s => [s.text, s.dim === true])).toEqual([
      ['…y/', true],
      ['DashboardService.php:28', false],
    ])
    // Cut into the file name, all of it is the file: there is no folder left to dim.
    expect(placeSegments('src/Query/DashboardService.php:28', 12).map(s => s.text)).toEqual(['…vice.php:28'])
    // What recorded a diagnostic, after ` · `, is dim.
    expect(placeSegments('src/A.php:3 · phpstan', 40).map(s => [s.text, s.dim === true])).toEqual([
      ['src/', true],
      ['A.php:3', false],
      [' · phpstan', true],
    ])
  })
  it('keeps the file name and line whole in its least, alone when no cell is left for the `…`', () => {
    expect(placeLeast('src/Query/DashboardService.php:28')).toBe('DashboardService.php:28'.length)
    expect(placeLeast('helper.php:4')).toBe('helper.php:4'.length)
    expect(placeLeast('src/A.php:3 · phpstan')).toBe('A.php:3'.length)
    expect(placeSegments('src/Query/DashboardService.php:28', 'DashboardService.php:28'.length).map(s => s.text)).toEqual(['DashboardService.php:28'])
  })
})
