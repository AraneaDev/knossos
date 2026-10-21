import type { Loc } from './types'

/** The path relative to the project root, or null when it lies outside it. */
export function relativise(root: string, path: string): string | null {
  const base = normalise(root).replace(/\/+$/, '')
  const full = normalise(path)
  return full.startsWith(base + '/') ? full.slice(base.length + 1) : null
}

function normalise(path: string): string {
  const out: string[] = []
  for (const part of path.split('/')) {
    if (part === '..') out.pop()
    else if (part !== '.' && part !== '') out.push(part)
  }
  return '/' + out.join('/')
}

/** A path's last part: the file's own name. */
export const baseName = (path: string): string => path.slice(path.lastIndexOf('/') + 1)

/** A file and line as the pane prints a place: the file's own name, then the line. */
export function placeOf(path: string | null, line: number | null): string {
  if (path === null) return ''
  return line === null ? baseName(path) : `${baseName(path)}:${line}`
}

/** A project-relative path made absolute under `root`; an absolute path is kept. */
export function absolute(root: string, path: string): string {
  return path.startsWith('/') ? path : `${root.replace(/\/+$/, '')}/${path}`
}

/** `path:line` (or the path alone), as an editor's go-to and a copy take it. */
export const locText = (loc: Loc): string => (loc.line === null ? loc.path : `${loc.path}:${loc.line}`)
