/**
 * What the web host's specs share: a plugin root whose wrapper is the
 * fixture one, the fixture dashboard for a project, and a frame read as text.
 */
import { chmodSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import type { Dashboard } from '../../types'
import type { Frame } from '../hub'

const HERE = dirname(fileURLToPath(import.meta.url))

/** A fresh directory under the system's temp dir. */
export const scratch = (prefix = 'knossos-web-'): string => mkdtempSync(join(tmpdir(), prefix))

/** A plugin root whose hooks/scripts/knossos-run.sh runs the fixture wrapper, which answers from web/fixtures. */
export function fixturePluginRoot(): string {
  const root = scratch('knossos-web-plugin-')
  mkdirSync(join(root, 'hooks/scripts'), { recursive: true })
  const shim = join(root, 'hooks/scripts/knossos-run.sh')
  writeFileSync(shim, `#!/bin/sh\nexec sh '${join(HERE, 'knossos-run.sh')}' "$@"\n`)
  chmodSync(shim, 0o755)
  return root
}

/** The fixture dashboard, as the wrapper prints it for `root`. */
export const fixtureDashboard = (root: string): Dashboard => JSON.parse(readFileSync(join(HERE, 'dashboard.json'), 'utf8').replaceAll('@ROOT@', root)) as Dashboard

/** A frame's rows as the text they show, one line each. */
export const frameText = (frame: Frame): string => [...frame.body, ...frame.footer].map(row => row.segments.map(s => s.text).join('')).join('\n')

/** The tab the frame shows as open: the tab button laid on a ground. */
export const activeTab = (frame: Frame): string | undefined =>
  frame.body
    .flatMap(row => row.segments)
    .find(s => s.press?.id.startsWith('tab:') === true && s.look.background !== undefined)
    ?.press?.id.slice('tab:'.length)
