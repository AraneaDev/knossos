<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Synthesis;

/**
 * A language's mock template: the whole text, and each member's part of
 * it. Only text is rendered; nothing is compiled or run.
 *
 * @phpstan-type Rendered array{rendered: string, unknown: list<string>, imports: list<string>}
 */
interface MockTemplate
{
    /** The whole template for `$members` in `$framework`, example values stubbed when `$values`. */
    public function render(MockMembers $members, string $framework, bool $values): string;

    /**
     * One member's lines as the template holds them, what in them is a
     * placeholder or a top type, and the imports its values need.
     *
     * @return Rendered
     */
    public function member(MockMembers $members, MockMember $member, string $framework, bool $values): array;
}
