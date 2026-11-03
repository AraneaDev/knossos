<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Roots;

use Knossos\Roots\StartClaim;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Which folder a start claims, and when it must ask first.
 *
 * Everything is decided from the filesystem alone, on temp trees, because the
 * answer has to be the same with no git binary on the path.
 */
final class StartClaimTest extends KnossosTestCase
{
    private string $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = (string) realpath(sys_get_temp_dir()) . '/knossos-claim-' . bin2hex(random_bytes(4));
        mkdir($this->tree . '/home', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tree));
        parent::tearDown();
    }

    #[Group('roots')]
    public function testASubfolderOfARepositoryTargetsItsToplevel(): void
    {
        mkdir($this->tree . '/repo/.git', 0o755, true);
        mkdir($this->tree . '/repo/src/deep', 0o755, true);

        assertSame($this->tree . '/repo', StartClaim::targetOf($this->tree . '/repo/src/deep', $this->tree . '/home')['root']);
    }

    #[Group('roots')]
    public function testALinkedWorktreeTargetsItself(): void
    {
        mkdir($this->tree . '/outer/.git', 0o755, true);
        mkdir($this->tree . '/outer/tree', 0o755, true);
        file_put_contents($this->tree . '/outer/tree/.git', "gitdir: /elsewhere\n");

        assertSame($this->tree . '/outer/tree', StartClaim::targetOf($this->tree . '/outer/tree', $this->tree . '/home')['root']);
    }

    #[Group('roots')]
    public function testANonGitFolderTargetsItself(): void
    {
        mkdir($this->tree . '/plain/sub', 0o755, true);

        assertSame($this->tree . '/plain/sub', StartClaim::targetOf($this->tree . '/plain/sub', $this->tree . '/home')['root']);
    }

    #[Group('roots')]
    public function testAnOrdinaryRepositoryUnderHomeDoesNotAsk(): void
    {
        mkdir($this->tree . '/home/work/repo/.git', 0o755, true);

        $target = StartClaim::targetOf($this->tree . '/home/work/repo', $this->tree . '/home');

        assertSame(false, $target['ask']);
        assertSame($this->tree . '/home/work/repo', $target['root']);
    }

    #[Group('roots')]
    public function testRootHomeAndAParentOfHomeAsk(): void
    {
        mkdir($this->tree . '/home/user', 0o755, true);
        $home = $this->tree . '/home/user';

        assertSame(true, StartClaim::targetOf('/', $home)['ask']);
        assertSame(true, StartClaim::targetOf($home, $home)['ask']);
        assertSame(true, StartClaim::targetOf($this->tree . '/home', $home)['ask']);
    }

    #[Group('roots')]
    public function testAHomeThatIsARepositoryAsks(): void
    {
        mkdir($this->tree . '/home/.git', 0o755, true);

        assertSame(true, StartClaim::targetOf($this->tree . '/home', $this->tree . '/home')['ask']);
    }

    #[Group('roots')]
    public function testASubfolderOfARepositoryAtHomeAsks(): void
    {
        mkdir($this->tree . '/home/.git', 0o755, true);
        mkdir($this->tree . '/home/Documents', 0o755, true);

        $target = StartClaim::targetOf($this->tree . '/home/Documents', $this->tree . '/home');

        assertSame(true, $target['ask']);
        assertSame($this->tree . '/home', $target['root']);
    }

    #[Group('roots')]
    public function testASymlinkToHomeAsks(): void
    {
        symlink($this->tree . '/home', $this->tree . '/alias');

        $target = StartClaim::targetOf($this->tree . '/alias', $this->tree . '/home');

        assertSame(true, $target['ask']);
        assertSame($this->tree . '/home', $target['root']);
    }

    #[Group('roots')]
    public function testAnUnknownHomeAsks(): void
    {
        mkdir($this->tree . '/plain', 0o755, true);

        assertSame(true, StartClaim::targetOf($this->tree . '/plain', null)['ask']);
    }
}
