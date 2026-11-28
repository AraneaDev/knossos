<?php

declare(strict_types=1);

namespace Knossos\Query\TestFidelity;

use InvalidArgumentException;
use Knossos\Query\ComponentQueryService;
use Knossos\Query\ReportableComponent;
use Knossos\Scanner\PackagedLanguages;
use PDO;

/**
 * One name to the one type a test-fidelity tool asks about: a canonical or
 * display name matched exactly, within the kinds the tool takes and outside
 * test code (preflight F13).
 *
 * Exact, not as `find_component` matches: a prefix or a substring would make
 * `User` ambiguous with `UserService`. No match is an error that suggests
 * `find_component`'s own candidates of those kinds; several is an error that
 * lists them by canonical name, which names one exactly. An enum (a node with
 * `cases`, or a Rust `rust_item` enum) or a Rust union has nothing to
 * double, and is refused.
 *
 * @phpstan-type Node array{id: string, kind: string, canonical_name: string, display_name: string, language: string, file: ?string, line: ?int, attributes: array<string, mixed>}
 */
final readonly class SymbolResolution
{
    /** How many exact matches are read at most; past them the error lists these. */
    private const MAX_CANDIDATES = 20;

    /** How many of `find_component`'s candidates a no-match error weighs. */
    private const SUGGESTIONS = 5;

    public function __construct(private PDO $pdo, private ComponentQueryService $components) {}

    /**
     * The one node `$symbol` names among `$kinds`, and every exact match
     * before `$language` narrowed them.
     *
     * @param ?string $language a packaged language name (`php`, `typescript`, …) that settles a name several languages declare
     * @param non-empty-list<string> $kinds the node kinds the tool takes, `class`, `interface` or `trait`
     * @return array{node: Node, candidates: list<Node>}
     *
     * @throws InvalidArgumentException when no node, or more than one, is named so
     */
    public function resolve(string $projectId, string $symbol, ?string $language, array $kinds): array
    {
        $candidates = $this->exact($projectId, $symbol, $kinds);
        $matches = $language === null ? $candidates : array_values(array_filter($candidates, static fn(array $node): bool => $node['language'] === $language));
        $described = self::described($kinds);
        if ($matches === []) {
            $suggestions = $candidates === [] ? $this->suggestions($projectId, $symbol, $kinds) : array_column($candidates, 'canonical_name');
            throw new InvalidArgumentException(sprintf('No %s is named "%s"%s%s', $described, $symbol, $language === null ? '' : ' in ' . $language, $suggestions === [] ? '.' : '; did you mean: ' . implode(', ', $suggestions) . '?'));
        }
        if (count($matches) > 1) {
            throw new InvalidArgumentException(sprintf('"%s" names %d %s; name one by its canonical name: %s.', $symbol, count($matches), self::described($kinds, plural: true), implode(', ', array_map(
                static fn(array $node): string => sprintf('%s (%s:%s)', $node['canonical_name'], $node['file'] ?? '?', $node['line'] ?? '?'),
                $matches,
            ))));
        }
        // A Rust enum sends `cases` only when it derives Deserialize; its `rust_item` marks it either way.
        $item = $matches[0]['attributes']['rust_item'] ?? null;
        if (array_key_exists('cases', $matches[0]['attributes']) || $item === 'enum' || $item === 'union') {
            throw new InvalidArgumentException(sprintf('"%s" names %s, which is no %s.', $symbol, $item === 'union' ? 'a union' : 'an enum', $described));
        }

        return ['node' => $matches[0], 'candidates' => $candidates];
    }

    /**
     * The nodes of `$kinds` outside test code whose canonical or display name is `$symbol`, in canonical order.
     *
     * @param non-empty-list<string> $kinds
     * @return list<Node>
     */
    private function exact(string $projectId, string $symbol, array $kinds): array
    {
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.language, n.start_line, n.attributes_json, f.relative_path '
            . 'FROM nodes n LEFT JOIN files f ON f.id = n.file_id '
            . 'WHERE n.project_id = ? AND n.kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ') AND (n.canonical_name = ? OR n.display_name = ?) '
            . 'AND NOT EXISTS (SELECT 1 FROM classifications c WHERE c.project_id = n.project_id AND c.node_id = n.id AND c.role = ?) '
            . 'ORDER BY n.canonical_name, n.id LIMIT ' . self::MAX_CANDIDATES,
        );
        $statement->execute([$projectId, ...$kinds, $symbol, $symbol, ReportableComponent::TEST_ROLE]);

        return array_map(static function (array $row): array {
            $attributes = json_decode((string) $row['attributes_json'], true);

            return [
                'id' => (string) $row['id'],
                'kind' => (string) $row['kind'],
                'canonical_name' => (string) $row['canonical_name'],
                'display_name' => (string) $row['display_name'],
                'language' => PackagedLanguages::languageOf((string) $row['language']),
                'file' => $row['relative_path'] === null ? null : (string) $row['relative_path'],
                'line' => $row['start_line'] === null ? null : (int) $row['start_line'],
                'attributes' => is_array($attributes) ? $attributes : [],
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * `find_component`'s candidates for `$symbol` that are of `$kinds` and outside test code, by canonical name.
     *
     * @param non-empty-list<string> $kinds
     * @return list<string>
     */
    private function suggestions(string $projectId, string $symbol, array $kinds): array
    {
        $names = [];
        foreach ($this->components->findComponent($projectId, $symbol, self::SUGGESTIONS)->data['components'] as $component) {
            if (in_array($component['kind'], $kinds, true) && !in_array(ReportableComponent::TEST_ROLE, array_column($component['roles'], 'role'), true)) {
                $names[] = (string) $component['canonical_name'];
            }
        }

        return $names;
    }

    /**
     * The kinds in words: `class, interface or trait`, or `classes, interfaces or traits`.
     *
     * @param non-empty-list<string> $kinds
     */
    private static function described(array $kinds, bool $plural = false): string
    {
        $words = array_map(static fn(string $kind): string => $plural ? (str_ends_with($kind, 's') ? $kind . 'es' : $kind . 's') : $kind, $kinds);
        $last = (string) array_pop($words);

        return $words === [] ? $last : implode(', ', $words) . ' or ' . $last;
    }
}
