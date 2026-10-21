<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * One queued state of explain_flow's breadth-first search: a path so far.
 *
 * Carries the nodes and hops walked, the node ids already on the path (a
 * path never revisits one), the profile candidates the path is valid in
 * (null while no profiled edge bound it), and the indexes of its hops whose
 * profile is unknown.
 */
final readonly class FlowState
{
    /**
     * @param non-empty-list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $hops
     * @param array<string, true> $seen
     * @param ?list<string> $candidates
     * @param list<int> $unprofiled
     */
    public function __construct(
        public array $nodes,
        public array $hops,
        public array $seen,
        public ?array $candidates,
        public array $unprofiled,
    ) {}

    /**
     * The state a search starts from: the start node alone, pinned to `$profile` when one is named.
     *
     * @param array<string, mixed> $start
     */
    public static function start(array $start, ?string $profile): self
    {
        return new self([$start], [], [(string) $start['id'] => true], $profile === null ? null : [$profile], []);
    }
}
