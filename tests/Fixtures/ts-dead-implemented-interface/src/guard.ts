import type { QueryGuard, Validation } from './types'

export class Guard implements QueryGuard {
  validate(query: string, dialect = 'sql'): Validation {
    return { ok: query.length > 0 && dialect !== '' }
  }

  complexity(query: string): number {
    return query.split(' ').length
  }

  /** Declared by no interface and called by nothing: dead. */
  unused(): number {
    return 0
  }
}
