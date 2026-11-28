<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A method the code hands on as a callable value, rather than calling it, is
 * reached through a `calls` edge marked `callable: true` (G3-31): PHP may call
 * it with more arguments than it takes, as `array_walk()` does.
 */
#[Group('php-scanner')]
final class PhpCallableReachTest extends KnossosTestCase
{
    public function testEveryCallableFormMarksTheMethodItReaches(): void
    {
        $edges = $this->callableEdges(<<<'PHP'
            <?php
            namespace App;

            final class Walker
            {
                public function __construct(private Repo $repo) {}

                public function each(array $xs, Repo $other): void
                {
                    array_walk($xs, [$this->repo, 'save'], 7);
                    $local = new Repo();
                    usort($xs, [$local, 'compare']);
                    array_map([Repo::class, 'make'], $xs);
                    array_map(['App\Repo', 'build'], $xs);
                    array_map('App\Repo::parse', $xs);
                    array_map(\Closure::fromCallable([$other, 'load']), $xs);
                    array_map($other->find(...), $xs);
                    array_map(Repo::create(...), $xs);
                    array_map([$this, 'own'], $xs);
                }

                private function own(): void {}
            }
            PHP);

        assertSame([
            ['php:method:App\Walker::each', 'php:method:App\Repo::save'],
            ['php:method:App\Walker::each', 'php:method:App\Repo::compare'],
            ['php:method:App\Walker::each', 'php:method:App\Repo::make'],
            ['php:method:App\Walker::each', 'php:method:App\Repo::build'],
            ['php:method:App\Walker::each', 'php:method:App\Repo::parse'],
            ['php:method:App\Walker::each', 'php:method:App\Repo::load'],
            ['php:method:App\Walker::each', 'php:method:App\Repo::find'],
            ['php:method:App\Walker::each', 'php:method:App\Repo::create'],
            ['php:method:App\Walker::each', 'php:method:App\Walker::own'],
        ], $edges);
    }

    public function testWhatNamesNoMethodIsNoCallable(): void
    {
        $edges = $this->callableEdges(<<<'PHP'
            <?php
            namespace App;

            final class Plain
            {
                public function run(array $xs, $untyped, string $name): void
                {
                    take(['first', 'second']);
                    take([1, 2, 3]);
                    take(['a' => $this, 'b' => 'run']);
                    take([$this, 'run', 'extra']);
                    take([$this, $name]);
                    take([$untyped, 'run']);
                    take(...[[$this, 'run']]);
                    array_map('not a callable', $xs);
                    array_map('App\Plain::', $xs);
                    array_map('::run', $xs);
                    $this->run($xs, $untyped, $name);
                }
            }
            PHP);

        assertSame([], $edges, 'A plain call is no callable, nor is what names no method.');
    }

    /**
     * Only an argument handed straight to a call is reached: not one to `new`
     * (an exception's message), nor a value returned, yielded or assigned. A
     * string counts only for `Closure::fromCallable()` or a PHP function
     * known to take a callback (the reviewer's adversarial cases, G3-31).
     */
    public function testOnlyACallArgumentIsACallable(): void
    {
        $edges = $this->callableEdges(<<<'PHP'
            <?php
            namespace App;

            final class Shelf
            {
                public function put(Store $store): iterable
                {
                    $held = [$store, 'held'];
                    yield [Store::class, 'yielded'];
                    throw new \LogicException('App\Store::message');
                    $this->log('App\Store::logged');
                    log_it([Store::class, 'handed']);
                    \Closure::fromCallable('App\Store::fromString');
                    call_user_func('App\Store::called');
                    return [$store, 'returned'];
                }
            }
            PHP);

        assertSame([
            ['php:method:App\Shelf::put', 'php:method:App\Store::handed'],
            ['php:method:App\Shelf::put', 'php:method:App\Store::fromString'],
            ['php:method:App\Shelf::put', 'php:method:App\Store::called'],
        ], $edges);
    }

    /**
     * The `calls` edges marked `callable` the scanner emits for one file, as source and target.
     *
     * @return list<array{string, string}>
     */
    private function callableEdges(string $source): array
    {
        require_once self::repositoryRoot() . '/workers/php/vendor/autoload.php';
        $root = self::scratchPath('knossos-stale-php-callables');
        mkdir($root);
        file_put_contents($root . '/Subject.php', $source);
        try {
            $contribution = (new \KnossosPhpScanner\PhpScanner())->scan($root, $root . '/Subject.php', 'Subject.php');
        } finally {
            $this->removeTempTree($root);
        }
        $edges = [];
        foreach ($contribution['edges'] as $edge) {
            if ((((array) $edge['attributes'])['callable'] ?? false) === true) {
                assertSame('calls', $edge['kind']);
                $edges[] = [$edge['source'], $edge['target']];
            }
        }

        return $edges;
    }
}
