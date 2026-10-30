import { Guard } from './guard'

/** Calls through the class type, never through the interface. */
export function check(query: string): boolean {
  const guard = new Guard()
  return guard.validate(query).ok && guard.complexity(query) < 10
}
