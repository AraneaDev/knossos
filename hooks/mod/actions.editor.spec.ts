import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { RenderSurface } from 'claude-code'
import { FEEDBACK_MS } from '../lib/layout'
import { fileHref } from '../lib/rows'
import { copyCommand, handlersOf, PRESSES, pressOf } from './actions'
import type { Port } from './port'
import { currentInput } from './render'
import { mod } from './state'

vi.mock('./render', async importOriginal => ({ ...(await importOriginal<typeof import('./render')>()), currentInput: vi.fn() }))

const NOW = 5_000
const PLACE = { path: '/work/app/src/A.php', line: 12 }
const SURFACE = 'pane' as RenderSurface

type Run = (argv: readonly string[], options: { timeoutMs: number }) => Promise<{ exitCode: number }>
type Copy = (args: { text: string; surface?: RenderSurface }) => Promise<{ isCopied: boolean; reason?: string }>

/** A port whose editor command and clipboard are the given fakes, and whose footer feedback is kept in memory. */
function port(run: Run, copy: Copy = vi.fn(async () => ({ isCopied: true }))) {
    let feedback: unknown = null
    const toasts: string[] = []
    const io = {
        process: { run: vi.fn(run) },
        ui: { copy: vi.fn(copy), toast: (text: string) => void toasts.push(text) },
        clock: { now: async () => NOW },
        state: { feedback: { read: async () => feedback, update: async (change: (v: unknown) => unknown) => void (feedback = change(feedback)) } },
    } as unknown as Port
    return { io, run: io.process.run as unknown as ReturnType<typeof vi.fn>, copy: io.ui.copy as unknown as ReturnType<typeof vi.fn>, feedback: () => feedback, toasts }
}

const exited =
    (exitCode: number): Run =>
    async () => ({ exitCode })

/** Lets the work a handler started without awaiting run to its end. */
const settle = () => new Promise(resolve => setTimeout(resolve, 0))

/** A press on `id` from `surface`, as the pane dispatches it. */
const press = (io: Port, id: string, surface?: RenderSurface) => {
    const found = pressOf(id)!
    return PRESSES.get(found.key)!(io, found.rest, surface, id)
}

beforeEach(() => {
    vi.mocked(currentInput).mockReset()
    mod.editor = undefined
})

describe('a press on a file: link', () => {
    it('opens path:line in the editor with a ten-second limit, says so, and copies nothing', async () => {
        const { io, run, copy, feedback } = port(exited(0))
        handlersOf(io).link(fileHref(PLACE), SURFACE)
        await settle()
        expect(run).toHaveBeenCalledTimes(1)
        expect(run).toHaveBeenCalledWith(['code', '-g', '/work/app/src/A.php:12'], { timeoutMs: 10_000 })
        expect(copy).not.toHaveBeenCalled()
        expect(feedback()).toEqual({ text: '✓ opened in editor', tone: 'ok', until: NOW + FEEDBACK_MS })
    })

    it('opens a path with no line as the path alone, its encoded characters decoded', async () => {
        const { io, run } = port(exited(0))
        handlersOf(io).link('file:///work/my%20app/src/B%28x%29.php', SURFACE)
        await settle()
        expect(run).toHaveBeenCalledWith(['code', '-g', '/work/my app/src/B(x).php'], { timeoutMs: 10_000 })
    })

    it('copies path:line to the surface the press came from when the editor exits non-zero, and says the path was copied', async () => {
        const { io, copy, feedback } = port(exited(1))
        handlersOf(io).link(fileHref(PLACE), SURFACE)
        await settle()
        expect(copy).toHaveBeenCalledTimes(1)
        expect(copy).toHaveBeenCalledWith({ text: '/work/app/src/A.php:12', surface: SURFACE })
        expect(feedback()).toEqual({ text: '✗ code exited 1 · path copied', tone: 'alert', until: NOW + FEEDBACK_MS })
    })

    it('treats an editor command that cannot start (ENOENT) as not opened, and copies instead', async () => {
        const { io, copy, feedback } = port(async () => {
            throw Object.assign(new Error('spawn code ENOENT'), { code: 'ENOENT' })
        })
        handlersOf(io).link(fileHref(PLACE), SURFACE)
        await settle()
        expect(copy).toHaveBeenCalledWith({ text: '/work/app/src/A.php:12', surface: SURFACE })
        expect(feedback()).toEqual({ text: '✗ code did not start · path copied', tone: 'alert', until: NOW + FEEDBACK_MS })
    })

    it('says only how the editor failed when the clipboard refuses too', async () => {
        const { io, feedback } = port(exited(127), async () => ({ isCopied: false, reason: 'no clipboard' }))
        handlersOf(io).link(fileHref(PLACE), SURFACE)
        await settle()
        expect(feedback()).toEqual({ text: '✗ code exited 127', tone: 'alert', until: NOW + FEEDBACK_MS })
    })

    it('runs the editor command the plugin is set to, and says where to set it when it fails', async () => {
        mod.editor = 'idea --line {line} {file}'
        const opened = port(exited(0))
        handlersOf(opened.io).link(fileHref(PLACE), SURFACE)
        await settle()
        expect(opened.run).toHaveBeenCalledWith(['idea', '--line', '12', '/work/app/src/A.php'], { timeoutMs: 10_000 })
        expect(opened.toasts).toEqual([])
        const failed = port(exited(2))
        handlersOf(failed.io).link(fileHref(PLACE), SURFACE)
        await settle()
        expect(failed.feedback()).toMatchObject({ text: '✗ idea exited 2 · path copied', tone: 'alert' })
        expect(failed.toasts).toEqual(['knossos ran "idea --line 12 /work/app/src/A.php" to open the file. Set another editor command with /plugin configure knossos@knossos.'])
    })

    it('names no surface at all to the clipboard when the press came from none', async () => {
        const { io, copy } = port(exited(1))
        handlersOf(io).link(fileHref(PLACE), undefined)
        await settle()
        const args = copy.mock.calls[0]![0] as Record<string, unknown>
        expect(args).toEqual({ text: '/work/app/src/A.php:12' })
        expect(Object.hasOwn(args, 'surface')).toBe(false)
    })

    it.each(['http://example.com/src/A.php#L12', 'https://work/app/src/A.php', 'garbage', '', 'file://relative/path.php', 'file:///work/app/%E0%A4%A.php'])(
        'does nothing for %j, which names no place',
        async href => {
            const { io, run, copy, feedback } = port(exited(0))
            handlersOf(io).link(href, SURFACE)
            await settle()
            expect(run).not.toHaveBeenCalled()
            expect(copy).not.toHaveBeenCalled()
            expect(feedback()).toBeNull()
        },
    )

    it('swallows an editor command that throws outright, leaving the footer as it was', async () => {
        const { io, run, feedback } = port(() => {
            throw new Error('process API gone')
        })
        expect(handlersOf(io).link(fileHref(PLACE), SURFACE)).toBeUndefined()
        await settle()
        expect(run).toHaveBeenCalledTimes(1)
        expect(feedback()).toBeNull()
    })

    it('swallows a clipboard that rejects, leaving the footer as it was', async () => {
        const { io, copy, feedback } = port(exited(1), async () => {
            throw new Error('surface torn down')
        })
        expect(handlersOf(io).link(fileHref(PLACE), SURFACE)).toBeUndefined()
        await settle()
        expect(copy).toHaveBeenCalledTimes(1)
        expect(feedback()).toBeNull()
    })
})

describe('e: opening what is marked in the editor', () => {
    const ROW = { path: '/work/app/src/Changed.php', line: null }
    const OTHER = { path: '/work/app/src/First.php', line: 3 }
    const touched = (loc: { path: string; line: number | null }) => ({ path: loc.path.replace('/work/app/', ''), status: 'modified', boundary: null, dependents: 0, loc, tests: 1 })
    /** The Changes tab listing two files, the second marked; no detail open. */
    const onChanges = {
        tab: 'changes',
        detail: null,
        finder: null,
        route: null,
        drift: null,
        driftOpen: false,
        selected: 1,
        changes: { turns: 1, files: [touched(OTHER), touched(ROW)], tests: [], command: null, boundaries: [], violations: 0, untested: 0, untestedOnly: false },
    }

    it('does nothing without a dashboard to read the marked row from', async () => {
        vi.mocked(currentInput).mockResolvedValue(null)
        const { io, run, copy, feedback } = port(exited(0))
        await press(io, 'edit', SURFACE)
        expect(run).not.toHaveBeenCalled()
        expect(copy).not.toHaveBeenCalled()
        expect(feedback()).toBeNull()
    })

    it('does nothing when the marked row has no file to open', async () => {
        vi.mocked(currentInput).mockResolvedValue({ ...onChanges, changes: { ...onChanges.changes, files: [touched(OTHER), { ...touched(ROW), loc: null }] } } as never)
        const { io, run, feedback } = port(exited(0))
        await press(io, 'edit', SURFACE)
        expect(run).not.toHaveBeenCalled()
        expect(feedback()).toBeNull()
    })

    it("opens the marked row's file", async () => {
        vi.mocked(currentInput).mockResolvedValue(onChanges as never)
        const { io, run, feedback } = port(exited(0))
        await press(io, 'edit', SURFACE)
        expect(run).toHaveBeenCalledWith(['code', '-g', '/work/app/src/Changed.php'], { timeoutMs: 10_000 })
        expect((feedback() as { text: string }).text).toBe('✓ opened in editor')
    })

    it("opens the file the detail shows rather than the row marked under it, and copies it from the press's surface when no editor answers", async () => {
        const shown = { path: '/work/app/src/Shown.php', line: 40 }
        vi.mocked(currentInput).mockResolvedValue({ ...onChanges, detail: { component: { name: 'Shown', canonical: 'App\\Shown', loc: shown } } } as never)
        const { io, run, copy } = port(exited(1))
        await press(io, 'edit', SURFACE)
        expect(run).toHaveBeenCalledWith(['code', '-g', '/work/app/src/Shown.php:40'], { timeoutMs: 10_000 })
        expect(copy).toHaveBeenCalledWith({ text: '/work/app/src/Shown.php:40', surface: SURFACE })
    })

    it('opens the file of an open file detail', async () => {
        const file = { path: '/work/app/src/File.php', line: null }
        vi.mocked(currentInput).mockResolvedValue({ ...onChanges, detail: { file: { path: 'src/File.php', loc: file } } } as never)
        const { io, run } = port(exited(0))
        await press(io, 'edit', SURFACE)
        expect(run).toHaveBeenCalledWith(['code', '-g', '/work/app/src/File.php'], { timeoutMs: 10_000 })
    })
})

describe('copying a command the band offers', () => {
    const copier = (answer: { isCopied: boolean; reason?: string }) => {
        const toasts: string[] = []
        const copy = vi.fn(async () => answer)
        const io = { ui: { copy, toast: (text: string) => void toasts.push(text) } } as unknown as Port
        return { io, copy, toasts }
    }

    it('says what it copied, on the surface it was asked from', async () => {
        const { io, copy, toasts } = copier({ isCopied: true })
        await copyCommand(io, 'knossos allow-root /work/app', 'desktop')
        expect(copy).toHaveBeenCalledExactlyOnceWith({ text: 'knossos allow-root /work/app', surface: 'desktop' })
        expect(toasts).toEqual(['Copied knossos allow-root /work/app'])
    })

    it('says it could not, and why, when the surface refuses', async () => {
        const { io, toasts } = copier({ isCopied: false, reason: 'no clipboard' })
        await copyCommand(io, 'knossos allow-root /work/app')
        expect(toasts).toEqual(['Could not copy the command: no clipboard'])
    })

    it('names the surface as the cause when the refusal gives no reason', async () => {
        const { io, toasts } = copier({ isCopied: false })
        await copyCommand(io, 'knossos allow-root /work/app')
        expect(toasts).toEqual(['Could not copy the command: the surface refused'])
    })
})
