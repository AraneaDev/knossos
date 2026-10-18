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
    // Only the exit and the count: what a runtime prints on stderr (a warning of its own) is not the modules'.
    expect(run.status, run.stderr).toBe(0)
    expect(run.stdout).toMatch(/^ok \d+$/m)
    expect(Number(/^ok (\d+)$/m.exec(run.stdout)?.[1])).toBe(expected)
  })
})
