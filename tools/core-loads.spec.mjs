import { spawnSync } from 'node:child_process'
import { readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

describe('core-loads', () => {
  it('imports every shipped hooks/lib and hooks/mod module under plain Node', () => {
    const expected = ['hooks/lib', 'hooks/mod'].flatMap(dir => readdirSync(join(ROOT, dir)).filter(f => f.endsWith('.ts') && !f.endsWith('.spec.ts'))).length
    const run = spawnSync(process.execPath, [join(ROOT, 'tools', 'core-loads.mjs')], { encoding: 'utf8' })
    expect(run.stderr).toBe('')
    expect(run.status).toBe(0)
    expect(run.stdout).toMatch(/^ok \d+$/m)
    expect(Number(/^ok (\d+)$/m.exec(run.stdout)?.[1])).toBe(expected)
  })
})
