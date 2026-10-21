<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Worker\WorkerErrorCode;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The worker diagnostic codes live in one enum, and the recovery matrix lists
 * exactly those codes.
 */
#[Group('worker')]
final class WorkerErrorCodeTest extends KnossosTestCase
{
    public function testTheRecoveryMatrixListsExactlyTheEnumsCodes(): void
    {
        $doc = (string) file_get_contents(self::repositoryRoot() . '/docs/operate/recovery-matrix.md');
        $start = strpos($doc, '## Worker diagnostic codes');
        self::assertNotFalse($start);
        $end = strpos($doc, "\n## ", $start + 1);
        $section = substr($doc, $start, $end === false ? null : $end - $start);
        preg_match_all('/^\| `(WORKER_[A-Z_]+)`/m', $section, $matches);
        $documented = $matches[1];
        sort($documented);
        $codes = array_map(static fn(WorkerErrorCode $code): string => $code->value, WorkerErrorCode::cases());
        sort($codes);

        assertSame($codes, $documented);
    }

    public function testNoSourceFileSpellsAWorkerCodeOutsideTheEnum(): void
    {
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::repositoryRoot() . '/src', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, '.php') || str_ends_with($path, '/WorkerErrorCode.php')) {
                continue;
            }
            if (preg_match("/'WORKER_[A-Z_]+'/", (string) file_get_contents($path)) === 1) {
                $offenders[] = $path;
            }
        }

        assertSame([], $offenders);
    }

    public function testACodeIsReadBackFromItsException(): void
    {
        assertSame(WorkerErrorCode::Timeout, WorkerErrorCode::of(new WorkerException('WORKER_TIMEOUT', 'late')));
        assertSame(null, WorkerErrorCode::of(new WorkerException('SOMETHING_ELSE', 'other')));
    }
}
