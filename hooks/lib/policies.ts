/**
 * What a policy check's status says beyond its count: a check knossos could
 * not run because the project's `knossos.json` could not be read.
 */

/** What the pane and the notes say when the core gives no reason for policies it could not read. */
const NO_REASON = 'knossos.json could not be read'

/**
 * Why the project's policies could not be read, when a policy check (the
 * dashboard's, a turn brief's) says `config_invalid`; null for any other
 * status. The reason is the core's `message`, read as whatever it is.
 */
export function unreadablePolicies(policy: { status: string } | null | undefined): string | null {
  if (policy?.status !== 'config_invalid') return null
  const message: unknown = (policy as { message?: unknown }).message
  return typeof message === 'string' && message.trim() !== '' ? message.trim() : NO_REASON
}

/** The line that says so: `policies unreadable: <reason>`. */
export const unreadableText = (reason: string): string => `policies unreadable: ${reason}`
