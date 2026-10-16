<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Every worker recognises the same interpreters, so a launches edge means the same in every language. */
final class LaunchInterpretersAgreementTest extends KnossosTestCase
{
    #[Group('scan')]
    public function testEveryWorkerListsTheSameInterpreters(): void
    {
        $sources = [
            'workers/php/src/FactCollector.php' => '/INTERPRETERS\s*=\s*\[(.*?)\];/s',
            'workers/typescript/src/launch-facts.js' => '/INTERPRETERS\s*=\s*new Set\(\[(.*?)\]\)/s',
            'workers/python/bin/knossos_python/collector.py' => '/^INTERPRETERS\s*=\s*\{(.*?)\}/ms',
            'workers/rust/src/visit/launches.rs' => '/INTERPRETERS:\s*\[&str;\s*\d+\]\s*=\s*\[(.*?)\];/s',
        ];
        $lists = [];
        foreach ($sources as $path => $pattern) {
            self::assertSame(1, preg_match($pattern, (string) file_get_contents(self::repositoryRoot() . '/' . $path), $match), $path . ' declares no INTERPRETERS list.');
            preg_match_all('/["\']([^"\'\s]+)["\']/', $match[1], $names);
            $list = $names[1];
            sort($list);
            $lists[$path] = $list;
        }

        self::assertCount(1, array_unique(array_map(static fn(array $list): string => implode(',', $list), $lists)), 'Interpreter lists differ: ' . json_encode($lists));
        self::assertNotSame([], reset($lists));
    }
}
