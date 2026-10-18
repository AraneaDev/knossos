// Imports every shipped module of the pane's shared core (hooks/lib and hooks/mod) under plain Node, so a
// module that needs the engine's bundler (JSX, a `.tsx` import) fails here rather than in a host without it.
import { readdirSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

import './ts-resolve.mjs'

const REPO = resolve(dirname(fileURLToPath(import.meta.url)), '..')

const modules = ['hooks/lib', 'hooks/mod']
  .flatMap(dir => readdirSync(join(REPO, dir)).filter(f => f.endsWith('.ts') && !f.endsWith('.spec.ts')).map(f => join(REPO, dir, f)))
  .sort()

for (const file of modules) await import(pathToFileURL(file).href)

console.log(`ok ${modules.length}`)
