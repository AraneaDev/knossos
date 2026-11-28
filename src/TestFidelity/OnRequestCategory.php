<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

/**
 * What an on-request test-fidelity finding says is wrong: the categories
 * computed only when `audit_tests` is asked for them (`tier` `on_request` or
 * `all`), and never stored.
 *
 * Kept apart from the closed scan-tier set in {@see FindingCategory}, so the
 * readers of stored findings (the gate, the dashboard, the turn brief, the
 * pane) cannot meet one. Each is a warning: none is proved by the source.
 */
enum OnRequestCategory: string
{
    /** Most of a test file's dependencies are doubles, and almost none of its assertions read production code. */
    case OverMocking = 'OVER_MOCKING';

    /** A double's target changed since a git ref while the double's own file did not. */
    case StaleSince = 'STALE_SINCE';

    /** A JSON or YAML fixture no longer matches the type whose shape it holds. */
    case FixtureDrift = 'FIXTURE_DRIFT';
}
