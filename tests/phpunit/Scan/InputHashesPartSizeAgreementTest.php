<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Every worker splits input_hashes at the same size; the core reassembles them as one stream. */
final class InputHashesPartSizeAgreementTest extends KnossosTestCase
{
    #[Group('scan')]
    public function testEveryWorkerUsesTheSamePartSize(): void
    {
        $sources = [
            'workers/php/src/WorkerServer.php' => '/INPUT_HASHES_PART_BYTES\s*=\s*([\d_]+)/',
            'workers/typescript/src/input-hashes-parts.js' => '/INPUT_HASHES_PART_BYTES\s*=\s*([\d_]+)/',
            'workers/python/bin/worker.py' => '/INPUT_HASHES_PART_BYTES\s*=\s*([\d_]+)/',
            'workers/rust/src/server.rs' => '/INPUT_HASHES_PART_BYTES:\s*usize\s*=\s*([\d_]+)/',
        ];
        $sizes = [];
        foreach ($sources as $path => $pattern) {
            self::assertSame(1, preg_match($pattern, (string) file_get_contents(self::repositoryRoot() . '/' . $path), $match), $path . ' declares no part size.');
            $sizes[$path] = (int) str_replace('_', '', $match[1]);
        }

        self::assertCount(1, array_unique($sizes), 'Part sizes differ: ' . json_encode($sizes));
    }
}
