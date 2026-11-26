<?php

declare(strict_types=1);

namespace Knossos\Query\TestFidelity;

use Closure;
use InvalidArgumentException;
use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Query\SignatureLine;
use Knossos\Result\ResultEnvelope;
use Knossos\Scanner\PackagedLanguages;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\Synthesis\FrameworkChoice;
use Knossos\TestFidelity\Synthesis\MockMember;
use Knossos\TestFidelity\Synthesis\MockMembers;
use Knossos\TestFidelity\Synthesis\MockTemplate;
use Knossos\TestFidelity\Synthesis\PhpTemplate;
use Knossos\TestFidelity\Synthesis\PythonTemplate;
use Knossos\TestFidelity\Synthesis\RustTemplate;
use Knossos\TestFidelity\Synthesis\TypeScriptTemplate;
use PDO;

/**
 * `synthesize_mock`: a mock template of one class, interface or trait for
 * a test framework, the port of Momus `synthesize_mock_contract` (Decision
 * 5). Only text is rendered, from the stored facts; nothing is compiled or
 * run.
 *
 * What it cannot answer fully it says: an ambiguous, unknown or external
 * name, an enum, a function, a PHP trait (PHPUnit 12 cannot double one,
 * preflight F18) or a final PHP class is an error; a member it cannot render
 * faithfully is `omitted` with why; a member list that may hold more is
 * `complete: false`; and each untyped part and placeholder is listed in its
 * member's `unknown`.
 */
final readonly class MockSynthesisService extends AbstractArchitectureQueryService
{
    /** The kinds a template stands in for. */
    private const KINDS = ['class', 'interface', 'trait'];

    public function __construct(PDO $pdo, ?Closure $clock, private SymbolResolution $resolution)
    {
        parent::__construct($pdo, $clock);
    }

    /**
     * The template of the type `$symbol` names, for `$framework` or the one the project points to.
     *
     * @throws InvalidArgumentException when the name, the language or the framework gives no faithful template
     */
    public function synthesize(string $projectId, string $symbol, ?string $framework = null, ?string $language = null, bool $includeValues = true): ResultEnvelope
    {
        $project = $this->project($projectId);
        if ($language !== null && !in_array($language, PackagedLanguages::languages(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown language "%s"; one of %s.', $language, implode(', ', PackagedLanguages::languages())));
        }
        $resolved = $this->resolution->resolve($projectId, $symbol, $language, self::KINDS)['node'];
        $loader = new MockGraphLoader($this->pdo);
        $loaded = $loader->load($projectId, $resolved['id']) ?? throw new InvalidArgumentException(sprintf('"%s" names a type the scan did not resolve.', $symbol));
        $target = $loaded['node'];
        $language = $resolved['language'];
        self::refuse($target, $language);
        $choice = FrameworkChoice::choose($language, $framework, $this->doubleFrameworks($projectId, $language), ManifestFrameworks::near((string) $project['root_realpath'], $target->evidence->relativePath, $language));
        $members = MockMembers::of($loaded['graph'], $target, $language, $loaded['cut']);
        $types = new MockValueTypes($this->pdo, $loader, $projectId, $language);
        $template = $this->template($language, $types, $resolved['id'], $target);
        $text = $template->render($members, $choice['framework'], $includeValues);
        $rendered = array_map(static function (MockMember $member) use ($template, $members, $choice, $includeValues): array {
            $part = $template->member($members, $member, $choice['framework'], $includeValues);

            return [
                'name' => $member->name,
                'signature' => match ($member->kind) {
                    'property' => $member->name . ($member->optional ? '?' : '') . ($member->type === null ? '' : ': ' . $member->type),
                    'type' => 'type ' . (string) $member->type,
                    'const' => sprintf('const %s: %s', $member->name, (string) $member->type),
                    default => SignatureLine::of($member->signature, $member->name) ?? $member->name . '()',
                },
                'rendered' => $part['rendered'],
                'unknown' => array_values(array_unique($part['unknown'])),
            ];
        }, $members->members);
        $omitted = count($members->omitted);

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('%s mock of %s (%s): %d member%s%s, %s.', $choice['framework'], $target->canonicalName, $choice['source'], count($rendered), count($rendered) === 1 ? '' : 's', $omitted === 0 ? '' : sprintf(', %d omitted', $omitted), $members->complete ? 'complete' : 'incomplete'),
            [
                'symbol' => $target->canonicalName,
                'file' => $target->evidence->relativePath,
                'language' => $language,
                'framework' => $choice['framework'],
                'framework_source' => $choice['source'],
                'complete' => $members->complete,
                'template' => $text,
                'members' => $rendered,
                'omitted' => $members->omitted,
                'notes' => [...$members->notes, ...self::scopeNotes($target, $language)],
            ],
        );
    }

    /**
     * Refuse a target no template can double faithfully: one outside the
     * project, a PHP trait (PHPUnit 12 has no `getMockForTrait`, and
     * `createMock` of a trait fails at run time), or a final PHP class.
     */
    private static function refuse(NodeFact $target, string $language): void
    {
        $path = $target->evidence->relativePath;
        if (($target->attributes['external'] ?? false) === true || str_starts_with($path, 'vendor/') || str_contains($path, '/node_modules/') || str_starts_with($path, 'node_modules/') || str_ends_with($path, '.d.ts')) {
            throw new InvalidArgumentException(sprintf('%s is declared outside the project (%s): its members cannot be read, so no template is given.', $target->canonicalName, $path));
        }
        if ($language === 'php' && $target->kind === 'trait') {
            throw new InvalidArgumentException(sprintf('%s is a PHP trait: PHPUnit 12 cannot double a trait, and a template would fail when run; mock a class that uses it.', $target->canonicalName));
        }
        if ($language === 'php' && ($target->attributes['final'] ?? false) === true) {
            throw new InvalidArgumentException(sprintf('%s is final: PHPUnit, Mockery and Prophecy cannot double a final class; mock an interface it implements.', $target->canonicalName));
        }
    }

    /**
     * What a template leaves the reader to bring into scope: TypeScript's
     * target and the types its members name, Rust's types its signatures
     * name. PHP writes every name fully qualified, and Python imports what it
     * calls.
     *
     * @return list<string>
     */
    private static function scopeNotes(NodeFact $target, string $language): array
    {
        $file = $target->evidence->relativePath;

        return match ($language) {
            'typescript' => [sprintf('Import %s, and the types its members name, from %s.', $target->displayName, $file)],
            'rust' => [
                sprintf('The signatures name types as %s does: bring them into scope as it does.', $file),
                ...(MockMembers::typeParamsOf($target) === [] ? [] : [sprintf('%s is generic: Mock%s::new() binds none of its parameters; name them where it is made.', $target->displayName, $target->displayName)]),
            ],
            default => [],
        };
    }

    /** The template class of a language, given what its values and imports need. */
    private function template(string $language, MockValueTypes $types, string $nodeId, NodeFact $target): MockTemplate
    {
        if ($language === 'python') {
            $module = $types->pythonModule($nodeId);

            return new PythonTemplate(
                $types->lookup(),
                $module === null ? sprintf('# import %s from the module that declares it', $target->displayName) : sprintf('from %s import %s', $module, $target->displayName),
                $target->displayName,
            );
        }

        return match ($language) {
            'typescript' => new TypeScriptTemplate($types->lookup()),
            'php' => new PhpTemplate($types->lookup()),
            'rust' => new RustTemplate($types->lookup()),
            default => throw new InvalidArgumentException(sprintf('No mock template is written for %s.', $language)),
        };
    }

    /**
     * How many of the project's doubles of `$language` each framework makes.
     *
     * @return array<string, int>
     */
    private function doubleFrameworks(string $projectId, string $language): array
    {
        $statement = $this->pdo->prepare("SELECT language, json_extract(attributes_json, '$.framework') AS framework, COUNT(*) AS doubles FROM nodes WHERE project_id = ? AND kind = 'test_double' GROUP BY language, framework ORDER BY language, framework");
        $statement->execute([$projectId]);
        $counts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (PackagedLanguages::languageOf((string) $row['language']) === $language && is_string($row['framework'])) {
                $counts[$row['framework']] = ($counts[$row['framework']] ?? 0) + (int) $row['doubles'];
            }
        }

        return $counts;
    }
}
