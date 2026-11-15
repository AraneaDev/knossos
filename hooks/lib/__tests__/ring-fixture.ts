import type { RingMember, RingsInput } from '../rings'

/** A member of a ring as the detail holds it. */
export const member = (name: string, kind: string, boundary: string, tested: boolean, via?: string): RingMember => ({ name, canonical: `App\\${name}`, kind, boundary, tested, loc: { path: `/w/src/${name}.php`, line: 3 }, ...(via === undefined ? {} : { via }) })

/**
 * Three rings round `Importer::run` in core: the nearest with four short
 * names, one across a boundary and one untested; the next with one name
 * too long to share its ring with another column; the furthest with more
 * members than its cells hold, and more than knossos listed.
 */
export const threeRings = (): RingsInput => ({
  loading: false,
  said: null,
  truncated: false,
  centre: { name: 'Importer::run', boundary: 'core' },
  rings: [
    { label: '1 hop', count: 4, tested: 3, probable: 1, members: [member('Near0', 'class', 'core', false, 'make'), member('Near1', 'class', 'edge', true), member('Near2', 'method', 'core', true), member('Near3', 'function', 'core', true)], tests: { count: 2, paths: ['tests/NearTest.php', 'tests/Other/FarTest.php'] } },
    { label: '2 hops', count: 1, tested: 1, members: [member('AnExtraordinarilyLongComponentNameThatFillsItsRing::andItsMethod', 'method', 'core', true)], tests: { count: 1, paths: ['tests/LongTest.php'] } },
    { label: '3+ hops', count: 40, tested: 37, members: Array.from({ length: 12 }, (_, i) => member(`Further${i}`, i % 4 === 0 ? 'method' : 'class', i % 3 === 0 ? 'tooling' : 'core', i > 2)), tests: { count: 22, paths: Array.from({ length: 12 }, (_, i) => `tests/Further${i}Test.php`) } },
  ],
})
