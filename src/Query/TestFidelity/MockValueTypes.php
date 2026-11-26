<?php

declare(strict_types=1);

namespace Knossos\Query\TestFidelity;

use Closure;
use Knossos\Query\ReportableComponent;
use Knossos\Scanner\PackagedLanguages;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\Facts\EnumCases;
use Knossos\TestFidelity\Facts\FieldLists;
use Knossos\TestFidelity\Synthesis\ExampleValue;
use Knossos\TestFidelity\Synthesis\MockMembers;
use Knossos\TestFidelity\TestFidelityGraph;
use PDO;

/**
 * The project types a template's example value may be built from: the
 * lookup {@see ExampleValue} asks, answered from the active graph.
 *
 * A name resolves to one type of the language outside test code, the one
 * the referencing file declares first, else the only one of that name. It
 * gives a literal only when the field facts vouch for every key (Tasks 3
 * and 4: a whole, closed list):
 *
 * - TypeScript: an interface, type alias or class without type parameters,
 *   every field public, and every other name it lists an interface's
 *   method (a stub); a class with a method gives none;
 * - PHP: a concrete class whose public constructor takes only its fields
 *   and every required one, written `new \X(name: …)`;
 * - Python: a class with a field list, called with keywords;
 * - Rust: a struct with a field list.
 *
 * An enum gives its first case by name (PHP `\X::Case`, Python `X.CASE`,
 * Rust `X::Case`); a TypeScript enum, which a template cannot import, gives
 * nothing. Anything else gives null, and the template a placeholder.
 *
 * @phpstan-import-type Record from ExampleValue
 * @phpstan-import-type EnumCase from ExampleValue
 */
final class MockValueTypes
{
    private const KINDS = "('class', 'interface', 'type_alias', 'enum')";

    /** @var array<string, Record|EnumCase|null> */
    private array $cache = [];

    public function __construct(private readonly PDO $pdo, private readonly MockGraphLoader $graphs, private readonly string $projectId, private readonly string $language) {}

    /**
     * The lookup a template's examples ask.
     *
     * @return Closure(string, string): (Record|EnumCase|null)
     */
    public function lookup(): Closure
    {
        return fn(string $type, string $file): ?array => $this->cache[$file . "\0" . $type] ??= $this->find($type, $file);
    }

    /**
     * The module that declares a Python class, when it is one: what `from … import` names.
     */
    public function pythonModule(string $nodeId): ?string
    {
        $statement = $this->pdo->prepare("SELECT s.kind, s.canonical_name FROM edges e JOIN nodes s ON s.id = e.source_id WHERE e.project_id = ? AND e.kind = 'contains' AND e.target_id = ? ORDER BY e.id LIMIT 1");
        $statement->execute([$this->projectId, $nodeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && $row['kind'] === 'module' ? (string) $row['canonical_name'] : null;
    }

    /** @return Record|EnumCase|null */
    private function find(string $type, string $file): ?array
    {
        $type = ltrim($type, '\\');
        $short = $this->language === 'php' ? $type : (string) preg_replace('/^.*(?:::|\.)/', '', $type);
        if (preg_match('/^[A-Za-z_][\w\\\\]*$/', $short) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.language, n.canonical_name, n.display_name, n.attributes_json, f.relative_path FROM nodes n JOIN files f ON f.id = n.file_id '
            . 'WHERE n.project_id = ? AND n.kind IN ' . self::KINDS . ' AND ' . ($this->language === 'php' ? 'lower(n.canonical_name) = lower(?)' : 'n.display_name = ?')
            . ' AND NOT EXISTS (SELECT 1 FROM classifications c WHERE c.project_id = n.project_id AND c.node_id = n.id AND c.role = ?) ORDER BY n.canonical_name, n.id LIMIT 20',
        );
        $statement->execute([$this->projectId, $short, ReportableComponent::TEST_ROLE]);
        $rows = array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC), fn(array $row): bool => PackagedLanguages::languageOf((string) $row['language']) === $this->language));
        $local = array_values(array_filter($rows, static fn(array $row): bool => $row['relative_path'] === $file));
        $chosen = count($local) === 1 ? $local[0] : (count($rows) === 1 ? $rows[0] : null);
        if ($chosen === null) {
            return null;
        }
        $loaded = $this->graphs->load($this->projectId, (string) $chosen['id']);
        if ($loaded === null) {
            return null;
        }
        $node = $loaded['node'];
        if (array_key_exists('cases', $node->attributes)) {
            return $this->enum($node, (string) $chosen['id']);
        }

        return $this->record($loaded['graph'], $node, (string) $chosen['id']);
    }

    /** @return EnumCase|null */
    private function enum(NodeFact $node, string $id): ?array
    {
        $cases = array_keys(EnumCases::ofNode($node)?->cases() ?? []);
        $first = $cases[0] ?? null;
        if ($first === null) {
            return null;
        }

        return match ($this->language) {
            'php' => ['kind' => 'enum', 'value' => sprintf('\\%s::%s', ltrim($node->canonicalName, '\\'), $first), 'import' => null],
            'python' => ($module = $this->pythonModule($id)) === null ? null : ['kind' => 'enum', 'value' => $node->displayName . '.' . $first, 'import' => sprintf('from %s import %s', $module, $node->displayName)],
            'rust' => ['kind' => 'enum', 'value' => $node->displayName . '::' . $first, 'import' => sprintf('use %s;', $node->canonicalName)],
            default => null,
        };
    }

    /** @return Record|null */
    private function record(TestFidelityGraph $graph, NodeFact $node, string $id): ?array
    {
        $fields = FieldLists::of($graph, $node)?->fields();
        if ($fields === null || MockMembers::typeParamsOf($node) !== []) {
            return null;
        }
        $types = array_map(static fn(array $field): ?string => $field['type'], $fields);
        $file = $node->evidence->relativePath;
        $record = static fn(string $name, array $fields, array $methods = [], ?string $import = null): array => ['kind' => 'record', 'name' => $name, 'fields' => $fields, 'methods' => $methods, 'import' => $import, 'file' => $file];
        switch ($this->language) {
            case 'typescript':
                if (array_filter($fields, static fn(array $field): bool => isset($field['visibility'])) !== []) {
                    return null;
                }
                $listed = $node->attributes['member_names'] ?? null;
                if (!is_array($listed) || ($node->attributes['member_names_open'] ?? false) !== false) {
                    return null;
                }
                $methods = [];
                foreach (array_diff(array_filter($listed, is_string(...)), array_keys($fields)) as $name) {
                    // A class's methods are its prototype's, and a static one is no instance member: only an interface's are stubbed.
                    $method = $graph->member($node, $name);
                    if ($method?->kind !== 'method' || $node->kind === 'class' || ($method->attributes['static'] ?? false) === true) {
                        return null;
                    }
                    $methods[] = $name;
                }

                return $record($node->displayName, $types, $methods);
            case 'php':
                return $node->kind === 'class' && ($node->attributes['abstract'] ?? false) !== true ? $this->phpConstruction($graph, $node, $fields, $record) : null;
            case 'python':
                $module = $node->kind === 'class' ? $this->pythonModule($id) : null;

                return $module === null ? null : $record($node->displayName, $types, [], sprintf('from %s import %s', $module, $node->displayName));
            default:
                return $node->kind === 'class' ? $record($node->displayName, $types, [], sprintf('use %s;', $node->canonicalName)) : null;
        }
    }

    /**
     * A PHP class built through its constructor's named parameters, when
     * they are its fields and hold every required one.
     *
     * @param array<string, array{type: ?string, required: bool, line: int, visibility?: string}> $fields
     * @param Closure(string, array<string, ?string>): Record $record
     * @return Record|null
     */
    private function phpConstruction(TestFidelityGraph $graph, NodeFact $node, array $fields, Closure $record): ?array
    {
        $constructor = $graph->member($node, '__construct');
        if ($constructor === null) {
            return $fields === [] ? $record('\\' . ltrim($node->canonicalName, '\\'), []) : null;
        }
        $params = $constructor->attributes['signature']['params'] ?? null;
        if (($constructor->attributes['visibility'] ?? 'public') !== 'public' || !is_array($params)) {
            return null;
        }
        $named = [];
        foreach ($params as $param) {
            $name = is_array($param) ? ($param['name'] ?? null) : null;
            if (!is_string($name) || !isset($fields[$name]) || ($param['variadic'] ?? false) === true) {
                return null;
            }
            $named[$name] = is_string($param['type'] ?? null) ? $param['type'] : null;
        }
        foreach ($fields as $name => $field) {
            if ($field['required'] && !array_key_exists($name, $named)) {
                return null;
            }
        }

        return $record('\\' . ltrim($node->canonicalName, '\\'), $named);
    }
}
