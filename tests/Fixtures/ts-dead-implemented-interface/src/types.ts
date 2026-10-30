export interface Validation {
  ok: boolean
}

/** Named only by the class that implements it: no receiver is typed as it. */
export interface QueryGuard {
  validate(query: string, dialect?: string): Validation
  complexity(query: string): number
}
