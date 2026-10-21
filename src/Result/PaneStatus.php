<?php

declare(strict_types=1);

namespace Knossos\Result;

/**
 * The statuses each envelope the pane reads may carry, by envelope.
 *
 * The pane accepts an envelope only when its `status` is one it knows
 * (`hooks/lib/envelopes.ts`); any other makes the parser answer null, and the
 * pane drops the answer without a word. A status added here without the
 * pane, or there without the core, is that silent drop, so these lists are
 * the contract: each constant is named as the set in `envelopes.ts` that
 * reads it, and a test holds the two to the same members.
 *
 * `error` and `no-binary` belong to every envelope: the first is the CLI's
 * answer when a command fails, the second the shell wrapper's when there is
 * no binary to run.
 */
final class PaneStatus
{
    /** The turn brief (`knossos brief`). */
    public const BRIEF = ['ok', 'not-allowed', 'missing', 'unscanned', 'scan-failed', 'error', 'no-binary'];

    /** The dashboard, and the envelopes shaped like it (couplings, search). */
    public const DASH = ['ok', 'unscanned', 'error', 'no-binary'];

    /** A component's detail and its blast radius. */
    public const DETAIL = ['ok', 'unscanned', 'not-found', 'ambiguous', 'error', 'no-binary'];

    /** A file's detail. */
    public const FILE = ['ok', 'unscanned', 'not-found', 'error', 'no-binary'];

    /** The changes since the session began; a working-tree read may find no git. */
    public const LEDGER = [...self::DASH, 'no-git'];

    /** A rescan the pane asked for. */
    public const RESCAN = ['ok', 'not-allowed', 'missing', 'unscanned', 'scan-failed', 'error', 'no-binary'];

    /** A file's change since the session began. */
    public const DIFF = ['ok', 'no-git', 'unknown-rev', 'error', 'no-binary'];

    /** The commit and branch the session began at (`session-head`). */
    public const HEAD = ['ok', 'no-git'];

    /** The Branch tab's comparison (`knossos branch-diff`). */
    public const BRANCH = ['ok', 'no-snapshot', 'on-default', 'no-git', 'no-default', 'unscanned', 'error', 'no-binary'];

    /** The Notes tab's notes. */
    public const NOTES = ['ok', 'refused', 'unscanned', 'error', 'no-binary'];

    /** The Churn tab's hotspots. */
    public const CHURN = ['ok', 'no-git', 'unreadable', 'unscanned', 'error', 'no-binary'];

    /** The routes between two components. */
    public const PATH = ['ok', 'not-found', 'ambiguous', 'unscanned', 'error', 'no-binary'];

    /** A note's preview, record or write. */
    public const NOTE = ['ok', 'refused', 'unscanned', 'error', 'no-binary'];

    /**
     * The `status` of a policy verdict inside an envelope: the turn brief's
     * and the dashboard's `policy`. `disabled` only in a brief run without
     * policies; `config_invalid`, with a `message`, when the project's
     * `knossos.json` cannot be read. Kept in step with `PolicyStatus` in
     * `types/index.d.ts`.
     */
    public const POLICY = ['evaluated', 'not_evaluated', 'disabled', 'config_invalid'];
}
