<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

/**
 * What a test-fidelity finding says is wrong: the closed set of scan-tier categories.
 *
 * A rule reports under exactly one category, and its id names it:
 * `test.fidelity.<category in lowercase>.v<N>`.
 */
enum FindingCategory: string
{
    /** A double stubs a member, export or module its target does not have. */
    case GhostMember = 'GHOST_MEMBER';

    /** A double's member takes a different number of arguments than the member it replaces. */
    case ArityMismatch = 'ARITY_MISMATCH';

    /** A double pins a value the replaced member's declared return type does not admit. */
    case ReturnDrift = 'RETURN_DRIFT';

    /** A double replaces a member that is not visible to it. */
    case VisibilityBreach = 'VISIBILITY_BREACH';

    /** An assertion that cannot fail. */
    case Tautology = 'TAUTOLOGY';

    /** A test replaces the module it is testing. */
    case MocksSubject = 'MOCKS_SUBJECT';

    /** The rule-id segment that names this category. */
    public function ruleSegment(): string
    {
        return strtolower($this->value);
    }
}
