<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Roots;

use Knossos\Roots\RootException;
use Knossos\Roots\RootGuard;
use PHPUnit\Framework\Attributes\Group;

/**
 * RootGuard against real directories: resolve() returns the canonical
 * realpath inside an allowed root and throws RootException for a missing
 * project, a missing allowed root, or a project outside every allowed root;
 * contains() matches only at a separator boundary.
 *
 * Conventions: bare global helpers from `tests/phpunit/Support/Assertions.php`.
 * NO `#[CoversClass]`. NO `assertTrue`.
 */
#[Group('discovery-guards')]
final class RootGuardContainmentTest extends \Knossos\Tests\Phpunit\KnossosTestCase
{
    /**
     * Create a temporary directory under sys_get_temp_dir and return
     * its absolute path. The directory is realpath-resolvable. Used
     * for RootGuard tests that need real filesystem + realpath.
     */
    private function tempDir(string $leaf = 'root'): string
    {
        $base = sys_get_temp_dir() . '/knossos-guard-' . bin2hex(random_bytes(6));
        mkdir($base, 0700, true);
        $absolute = $base . '/' . $leaf;
        mkdir($absolute, 0700, true);
        return $absolute;
    }

    public function testRootGuardResolveReturnsCanonicalRealpathInsideAllowedRoot(): void
    {
        // Success arm: requested root that realpath-resolves AND is
        // inside one of the allowedRoots returns the canonical
        // realpath. The containment check uses the static contains()
        // with normalised forward-slash paths.
        $allowed = $this->tempDir('allowed');
        $project = $allowed . '/subproject';
        mkdir($project, 0700, true);

        $guard = new RootGuard([$allowed]);
        $resolved = $guard->resolve($project);

        assertSame(realpath($project), $resolved);
    }

    public function testRootGuardResolveThrowsWhenRequestedRootDoesNotExist(): void
    {
        // Throw arm 1: realpath() on a missing path returns false ->
        // throw RootException ('Project root does not exist...').
        $allowed = $this->tempDir('allowed');
        $guard = new RootGuard([$allowed]);
        assertThrows(
            fn() => $guard->resolve('/nonexistent-' . bin2hex(random_bytes(4))),
            RootException::class,
        );
    }

    public function testRootGuardResolveThrowsWhenAllowedRootDoesNotExist(): void
    {
        // Throw arm 2: realpath() on a configured allowedRoot
        // returns false -> throw RootException ('Configured
        // allowed root does not exist...').
        $project = $this->tempDir('project');
        $guard = new RootGuard(['/nonexistent-allowed-' . bin2hex(random_bytes(4))]);
        assertThrows(
            fn() => $guard->resolve($project),
            RootException::class,
        );
    }

    public function testRootGuardResolveThrowsWhenProjectOutsideAllowed(): void
    {
        // Throw arm 3: requested realpath does exist but is NOT
        // inside any allowedRoot -> 'Project root is outside the
        // configured allowed roots.'
        $allowed = $this->tempDir('allowed');
        $disallowed = $this->tempDir('disallowed');

        $guard = new RootGuard([$allowed]);
        assertThrows(
            fn() => $guard->resolve($disallowed),
            RootException::class,
        );
    }

    public function testRootGuardResolveAllowsProjectExactlyEqualToAllowedRoot(): void
    {
        // Boundary case: requested = allowed root (after realpath).
        // The contains() check returns true for candidate === root.
        $allowed = $this->tempDir('exact-allowed');
        $guard = new RootGuard([$allowed]);
        $resolved = $guard->resolve($allowed);
        assertSame(realpath($allowed), $resolved);
    }

    public function testRootGuardResolveAllowsClosestMatchingAllowedWhenMultipleConfigured(): void
    {
        // Multi-allowed arm: multiple allowedRoots configured; the
        // requested is inside one of them; the first matching
        // allowed returns the canonical resolution (the loop
        // short-circuits on first matches()=true).
        $first = $this->tempDir('first');
        $second = $this->tempDir('second');
        $project = $second . '/sub';
        mkdir($project, 0700, true);

        $guard = new RootGuard([$first, $second]);
        $resolved = $guard->resolve($project);
        assertSame(realpath($project), $resolved);
    }

    public function testRootGuardStaticContainsExactMatch(): void
    {
        // Success: candidate === root (exact match) returns true
        // (after rtrim + normalise). The trailing-slash variants
        // exercise the rtrim arm.
        assertSame(true, RootGuard::contains('/repo', '/repo'));
        assertSame(true, RootGuard::contains('/repo/', '/repo'));
        assertSame(true, RootGuard::contains('/repo', '/repo/'));
    }

    public function testRootGuardStaticContainsChildPath(): void
    {
        // Success: candidate is a child (deeper) of root -> candidate
        // starts with root + '/' -> true.
        assertSame(true, RootGuard::contains('/repo', '/repo/sub'));
        assertSame(true, RootGuard::contains('/repo', '/repo/sub/inner'));
        assertSame(true, RootGuard::contains('/repo/', '/repo/sub'));
    }

    public function testRootGuardStaticContainsReturnsFalseForUnrelated(): void
    {
        // Negative: candidate is unrelated to root.
        assertSame(false, RootGuard::contains('/repo', '/elsewhere'));
        assertSame(false, RootGuard::contains('/repo', '/repo-other'));
    }

    public function testRootGuardStaticContainsNormalizesBackslashes(): void
    {
        // Normalisation arm: backslash separators are converted to
        // forward slashes before the containment check.
        assertSame(true, RootGuard::contains('C:\repo', 'C:\repo\sub'));
    }

    public function testRootGuardStaticContainsDoesNotMatchPrefixWithoutSeparator(): void
    {
        // Safety arm: a candidate that shares a string prefix but
        // differs at the separator boundary does NOT match. This is
        // the protection against str_starts_with($root . '/')
        // security bypass via shared names like '/repo' vs '/repos'.
        assertSame(false, RootGuard::contains('/repo', '/repos'));
        assertSame(false, RootGuard::contains('/repo', '/repository'));
    }
}
