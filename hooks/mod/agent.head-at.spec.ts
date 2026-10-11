import { describe, expect, it } from 'vitest'
import { headAt } from './agent'
import type { Port } from './port'

const REPO = '/work/app'
const SHA1 = 'a'.repeat(20) + '0123456789abcdef0123'
const SHA256 = '0123456789abcdef'.repeat(4)

type Answer = { exitCode: number; stdout: string } | Error

/** A port whose process.run answers once with `answer`, recording each argv and options it was handed. */
function fakePort(answer: Answer) {
    const runs: { argv: string[]; options: unknown }[] = []
    const io = {
        process: {
            run: async (argv: string[], options: unknown) => {
                runs.push({ argv, options })
                if (answer instanceof Error) throw answer
                return { exitCode: answer.exitCode, stdout: answer.stdout, stderr: '' }
            },
        },
    } as unknown as Port
    return { io, runs }
}

describe('headAt: naming the commit', () => {
    it('returns a 40-hex sha with its trailing newline trimmed', async () => {
        const { io } = fakePort({ exitCode: 0, stdout: `${SHA1}\n` })
        expect(SHA1).toHaveLength(40)
        expect(await headAt(io, REPO)).toBe(SHA1)
    })

    it('returns a 64-hex sha from a sha256 repository', async () => {
        const { io } = fakePort({ exitCode: 0, stdout: `${SHA256}\n` })
        expect(SHA256).toHaveLength(64)
        expect(await headAt(io, REPO)).toBe(SHA256)
    })

    it('asks git for HEAD in the repository with a 2000ms timeout', async () => {
        const { io, runs } = fakePort({ exitCode: 0, stdout: SHA1 })
        await headAt(io, REPO)
        expect(runs).toEqual([{ argv: ['git', '--no-optional-locks', '-C', REPO, 'rev-parse', '-q', '--verify', 'HEAD'], options: { timeoutMs: 2000 } }])
    })
})

describe('headAt: a branch with no commit yet', () => {
    it.each([
        ['empty stdout', ''],
        ['a sha on stdout', SHA1],
        ['noise on stdout', 'fatal: whatever\n'],
    ])('returns an empty string when git exits 1, with %s', async (_, stdout) => {
        const { io } = fakePort({ exitCode: 1, stdout })
        expect(await headAt(io, REPO)).toBe('')
    })
})

describe('headAt: when git gives no usable answer', () => {
    it.each([
        ['the literal HEAD', 'HEAD\n'],
        ['uppercase hex', SHA1.toUpperCase()],
        ['39 hex characters', SHA1.slice(0, 39)],
        ['65 hex characters', SHA256 + 'a'],
        ['a sha with trailing text', `${SHA1} extra`],
        ['nothing at all', ''],
    ])('returns null when git exits 0 with %s', async (_, stdout) => {
        const { io } = fakePort({ exitCode: 0, stdout })
        expect(await headAt(io, REPO)).toBeNull()
    })

    it.each([128, 2, -1])('returns null when git exits %i, even with a sha on stdout', async exitCode => {
        const { io } = fakePort({ exitCode, stdout: SHA1 })
        expect(await headAt(io, REPO)).toBeNull()
    })

    it('returns null rather than throwing when the run rejects on a timeout', async () => {
        const { io } = fakePort(new Error('timed out after 2000ms'))
        await expect(headAt(io, REPO)).resolves.toBeNull()
    })
})
