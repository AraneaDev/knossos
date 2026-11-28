<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\TestFidelityGraph;

/**
 * In-memory facts for the rule tests on Python, as the Python worker emits
 * them: a class `app.repo.Repo` in `src/app/repo.py` (or the module
 * `app.repo`) and one `unittest` double replacing it from
 * `tests/test_repo.py`.
 */
final class PythonFixtures
{
    public const DOUBLE = 'py:test_double:tests/test_repo.py#double:6:5';

    /**
     * A Python node, `py:<kind>:<canonical>`, displayed by its last name (a test fact by its whole canonical name).
     *
     * @param array<string, mixed> $attributes
     */
    public static function node(string $kind, string $canonical, array $attributes = [], string $file = 'src/app/repo.py'): NodeFact
    {
        $display = str_contains($canonical, '#') ? $canonical : (string) preg_replace('/^.*(?:::|\.)/', '', $canonical);

        return new NodeFact('py:' . $kind . ':' . $canonical, $kind, $canonical, $display, Origin::Ast, Confidence::Certain, new Evidence($file, 1, 1), $attributes);
    }

    /**
     * A method of `$owner`, or a function of a module owner.
     *
     * @param array<string, mixed> $attributes
     */
    public static function method(NodeFact $owner, string $name, array $attributes = []): NodeFact
    {
        return $owner->kind === 'module'
            ? self::node('function', $owner->canonicalName . '.' . $name, $attributes, $owner->evidence->relativePath)
            : self::node('method', $owner->canonicalName . '::' . $name, $attributes, $owner->evidence->relativePath);
    }

    /**
     * The class `app.repo.Repo` (or module `app.repo`) with the given members, and a double stubbing `$members`.
     *
     * @param list<array{string, array<string, mixed>}> $methods name and attributes of each member node
     * @param list<array<string, mixed>> $members
     * @param array<string, mixed> $doubleAttributes overrides of the double's attributes
     * @param array<string, mixed>|null $ownerAttributes the owner's attributes; by default the complete name list of its members
     * @param list<NodeFact> $extra further production nodes
     * @param list<EdgeFact> $extraEdges further production edges
     */
    public static function graph(array $methods, array $members, array $doubleAttributes = [], ?array $ownerAttributes = null, array $extra = [], array $extraEdges = [], string $kind = 'class'): TestFidelityGraph
    {
        $module = $kind === 'module';
        $names = array_map(static fn(array $method): string => $method[0], $methods);
        $owner = self::node($kind, $module ? 'app.repo' : 'app.repo.Repo', $ownerAttributes ?? [$module ? 'exports' : 'member_names' => $names]);
        $nodes = array_map(static fn(array $method): NodeFact => self::method($owner, $method[0], $method[1]), $methods);
        $double = new NodeFact(self::DOUBLE, 'test_double', 'tests/test_repo.py#double:6:5', 'patch.object', Origin::Ast, Confidence::Certain, new Evidence('tests/test_repo.py', 6, 6), [
            'framework' => 'unittest', 'pattern' => 'unittest.mock.patch.object', 'target' => $module ? ['kind' => 'module', 'specifier' => 'app.repo'] : ['kind' => 'class', 'export' => 'app.repo.Repo'],
            'automock' => false, 'invocations' => 0, 'members' => $members, ...$doubleAttributes,
        ]);

        return TestFidelityGraph::fromContributions([
            new ScanContribution('knossos.python:file:src/app/repo.py', [$owner, ...$nodes, ...$extra], [...array_map(static fn(NodeFact $node): EdgeFact => self::edge('contains', $owner, $node->localId), $nodes), ...$extraEdges]),
            new ScanContribution('knossos.python:file:tests/test_repo.py', [$double], [new EdgeFact('doubles', self::DOUBLE, $owner->localId, Origin::Ast, Confidence::Certain, $double->evidence)]),
        ]);
    }

    public static function edge(string $kind, NodeFact $source, string $target): EdgeFact
    {
        return new EdgeFact($kind, $source->localId, $target, Origin::Ast, Confidence::Certain, $source->evidence);
    }

    /**
     * A member a double stubs, as `patch.object` records it.
     *
     * @param list<array<string, mixed>> $returns
     * @param list<array<string, mixed>> $calledWith
     * @param list<array<string, mixed>>|null $params
     * @return array<string, mixed>
     */
    public static function stub(string $name, array $returns = [], array $calledWith = [], ?array $params = null): array
    {
        return ['name' => $name, 'line' => 7, 'api' => 'patch.object', 'returns' => $returns, 'called_with' => $calledWith] + ($params === null ? [] : ['params' => $params]);
    }

    /**
     * A pin of a value of `$type`, whose fit the worker leaves to core.
     *
     * @return array<string, mixed>
     */
    public static function pin(string $type, string $api = 'return_value'): array
    {
        return ['api' => $api, 'line' => 8, 'once' => false, 'assignable' => 'unknown', 'typed' => true, 'type' => $type];
    }

    /**
     * A call matcher of `$count` arguments, `$keywords` among them.
     *
     * @param list<string> $keywords
     * @return array<string, mixed>
     */
    public static function matcher(int $count, array $keywords = [], bool $spread = false): array
    {
        return ['api' => 'assert_called_with', 'line' => 9, 'count' => $count, 'spread' => $spread, 'keywords' => $keywords];
    }

    /**
     * A signature attribute block of the given parameters.
     *
     * @param list<array<string, mixed>> $params
     * @return array<string, mixed>
     */
    public static function signature(array $params, ?string $returns = null): array
    {
        return ['signature' => ['params' => $params] + ($returns === null ? [] : ['returns' => $returns])];
    }
}
