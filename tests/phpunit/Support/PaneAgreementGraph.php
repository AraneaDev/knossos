<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use Knossos\Store\StableId;
use PDO;

/**
 * The one graph the pane's answers and the core queries are compared on.
 * Used with {@see Fixtures}, which builds the store it adds to.
 */
trait PaneAgreementGraph
{
    /**
     * `App\Target` with a static method `make`; `App\StaticCaller` calling
     * only `App\Target::make`; `App\User` calling the class, with a chain
     * three deep above it (`App\Chain1` to `App\Chain3`); `App\Maybe`
     * calling the class on a probable edge; and `Tests\TargetTest`, a test,
     * calling `App\User`.
     *
     * @return array{0: PDO, 1: string, 2: string} the database, the project root and its id
     */
    public function paneAgreementGraph(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $owner = 'php:file:src/Target.php';
        $node = function (string $name, string $kind = 'class', ?string $parent = null, bool $test = false) use ($repository, $ids, $owner): string {
            $path = ($test ? 'tests/' : 'src/') . str_replace(['\\', '::'], ['/', '.'], $name) . '.php';
            $file = StableId::file($ids['project'], $path);
            $repository->saveFile($file, $ids['project'], $path, hash('sha256', $path), 20, 1, 'php', '0.1.0', $ids['scan']);
            $id = StableId::symbol($ids['project'], 'php', $kind, $name);
            $short = substr((string) strrchr('\\' . str_replace('::', '\\', $name), '\\'), 1);
            $repository->saveNode($id, $ids['project'], 'php', $kind, $name, $short, $parent, $file, 1, 10, 'ast', 'certain', [], $owner, $ids['scan']);
            if ($test) {
                $repository->saveClassification(StableId::classification($ids['project'], $id, 'quality.test_module', 'core.test.modules.v1'), $ids['project'], $id, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $file, 1, 10, [], $ids['scan']);
            }

            return $id;
        };
        $edge = function (string $kind, string $from, string $to, string $confidence = 'certain') use ($repository, $ids, $owner): void {
            $repository->saveEdge(StableId::edge($ids['project'], $kind, $from, $to, $from . '>' . $to), $ids['project'], $kind, $from, $to, null, 1, 1, 'ast', $confidence, [], $owner, $ids['scan']);
        };
        $target = $node('App\\Target');
        $make = $node('App\\Target::make', 'method', $target);
        $edge('contains', $target, $make);
        $edge('calls', $node('App\\StaticCaller'), $make);
        $user = $node('App\\User');
        $edge('calls', $user, $target);
        $edge('calls', $node('App\\Maybe'), $target, 'probable');
        $below = $user;
        foreach ([1, 2, 3] as $level) {
            $above = $node("App\\Chain{$level}");
            $edge('calls', $above, $below);
            $below = $above;
        }
        $edge('calls', $node('Tests\\TargetTest', test: true), $user);
        $repository->completeScan($ids['project'], $ids['scan']);

        return [$pdo, '/workspace/fixture-shop', $ids['project']];
    }

    /**
     * The test components' ids, as keys.
     *
     * @return array<string, true>
     */
    public static function idsOfTests(PDO $pdo): array
    {
        $ids = $pdo->query("SELECT node_id FROM classifications WHERE role = 'quality.test_module'")->fetchAll(PDO::FETCH_COLUMN);

        return array_fill_keys(array_map('strval', $ids), true);
    }
}
