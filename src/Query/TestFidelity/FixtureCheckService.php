<?php

declare(strict_types=1);

namespace Knossos\Query\TestFidelity;

use InvalidArgumentException;
use Knossos\Filesystem\RelativePathRule;
use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Query\Drift\ScannedPaths;
use Knossos\Query\ProjectFile;
use Knossos\Query\ProjectRelativePath;
use Knossos\Result\ResultEnvelope;
use Knossos\Scanner\PackagedLanguages;
use Knossos\TestFidelity\ActiveTestFindings;
use Knossos\TestFidelity\Fixtures\DtoMatch;
use Knossos\TestFidelity\Fixtures\FixtureDrift;
use Knossos\TestFidelity\Fixtures\FixtureFiles;
use Knossos\TestFidelity\Fixtures\FixturePaths;
use Knossos\TestFidelity\Fixtures\JsonDocument;
use Knossos\TestFidelity\Fixtures\YamlDocument;
use Knossos\TestFidelity\OnRequest\OnRequestFinding;
use Knossos\TestFidelity\TestFindingSuppressions;
use Knossos\TestFidelity\TestRuleCatalog;

/**
 * `check_fixtures`: the project's JSON and YAML fixture files against the
 * types whose records they hold, the port of Nemesis's stale-fixture check
 * (`src/fixtures/staleFixtures.ts`, Decision 6). Its findings are
 * `FIXTURE_DRIFT` warnings, computed when asked and never stored; `audit_tests`
 * lists the same ones in its on-request tier.
 *
 * Each fixture is read by core's own readers, never evaluated. A file that
 * is no document of the subset is `unparsable` with why; one over the size
 * cap, or a link out of the root, is `skipped`; one matched to no type is
 * `unmatched`, with the best near miss. None of those is a finding.
 *
 * @phpstan-type Run array{findings: list<OnRequestFinding>, languages: array<string, string>, scanned: int, unmatched: list<array{file: string, best: ?array{type: string, score: int}}>, unparsable: list<array{file: string, reason: string}>, skipped: int, truncated: bool, partial: bool, factless: list<string>}
 */
final readonly class FixtureCheckService extends AbstractArchitectureQueryService
{
    /** How many `unmatched` and `unparsable` entries a result lists at most; past them it says how many more. */
    private const MAX_LISTED = 100;

    /**
     * A page of the project's fixture drift, in file and line order, and
     * what the walk found besides.
     *
     * @param list<string> $paths project-relative files or directories to check instead of every fixture
     */
    public function check(string $projectId, array $paths = [], int $limit = 50, int $offset = 0): ResultEnvelope
    {
        $project = $this->project($projectId);
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('limit must be between 1 and 200.');
        }
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('offset must be between 0 and 100000.');
        }
        $root = (string) $project['root_realpath'];
        $run = $this->run($projectId, $root, self::requested($root, $paths), null);
        $suppressions = ActiveTestFindings::suppressionsOf($root);
        $items = array_map(static fn(OnRequestFinding $finding): array => $finding->toItem(), self::unsuppressed($run['findings'], $suppressions));
        usort($items, static fn(array $a, array $b): int => [strcmp($a['file'], $b['file']), $a['line'], strcmp($a['category'], $b['category']), strcmp($a['rule_id'], $b['rule_id']), strcmp($a['id'], $b['id'])] <=> [0, $b['line'], 0, 0, 0]);
        $total = count($items);
        $page = array_slice($items, $offset, $limit);
        $next = $offset + count($page) < $total ? $offset + count($page) : null;

        return new ResultEnvelope(
            $projectId,
            (string) $project['active_scan_id'],
            sprintf('%d fixture drift finding%s in %d fixture file%s.', $total, $total === 1 ? '' : 's', $run['scanned'], $run['scanned'] === 1 ? '' : 's'),
            [
                'items' => $page,
                'total' => $total,
                'scanned' => $run['scanned'],
                'unmatched' => array_slice($run['unmatched'], 0, self::MAX_LISTED),
                'unparsable' => array_slice($run['unparsable'], 0, self::MAX_LISTED),
                'skipped' => $run['skipped'],
                'truncated' => $run['truncated'],
                'summary' => ['by_language' => self::byLanguage($items, $run['languages'], $run['factless'])],
                'suppressions_invalid' => $suppressions->invalid(),
                'pagination' => ['offset' => $offset, 'next_offset' => $next],
            ],
            [],
            self::warnings($run),
            $next !== null,
        );
    }

    /**
     * Every fixture drift finding of the project, for `audit_tests`'s
     * on-request tier: the findings, each fixture file's language (its
     * type's), and whether `$deadline` (in the clock's nanoseconds) or a
     * bound cut the walk short.
     *
     * @return array{findings: list<OnRequestFinding>, languages: array<string, string>, partial: bool}
     */
    public function findings(string $projectId, string $root, ?int $deadline): array
    {
        $run = $this->run($projectId, $root, [], $deadline);

        return ['findings' => $run['findings'], 'languages' => $run['languages'], 'partial' => $run['partial'] || $run['truncated']];
    }

    /**
     * The walk: every fixture file read, matched and checked.
     *
     * @param list<string> $paths
     * @return Run
     */
    private function run(string $projectId, string $root, array $paths, ?int $deadline): array
    {
        $run = ['findings' => [], 'languages' => [], 'scanned' => 0, 'unmatched' => [], 'unparsable' => [], 'skipped' => 0, 'truncated' => false, 'partial' => false, 'factless' => []];
        $loaded = (new FixtureTypeLoader($this->pdo))->load($projectId);
        if ($loaded === null) {
            return ['partial' => true] + $run;
        }
        $run['factless'] = $loaded['factless'];
        $exclusions = ScannedPaths::forProject($this->pdo, $projectId);
        $walk = FixtureFiles::walk($root, $paths, static fn(string $path, bool $directory): bool => $exclusions->excludes($path, $directory));
        [$run['skipped'], $run['truncated']] = [$walk['skipped'], $walk['truncated']];
        $match = new DtoMatch($loaded['types']);
        foreach ($walk['files'] as $file) {
            if ($deadline !== null && $this->now() > $deadline) {
                $run['partial'] = true;
                break;
            }
            $bytes = FixtureFiles::read($root, $file);
            if ($bytes === null) {
                ++$run['skipped'];
                continue;
            }
            $reason = null;
            $document = str_ends_with($file, '.json') ? JsonDocument::parse($bytes, $reason) : YamlDocument::parse($bytes, $reason);
            if ($document === null) {
                $run['unparsable'][] = ['file' => $file, 'reason' => (string) $reason];
                continue;
            }
            ++$run['scanned'];
            ['type' => $type, 'best' => $best] = $match->match(pathinfo($file, PATHINFO_FILENAME), $document->root);
            if ($type === null) {
                $run['unmatched'][] = ['file' => $file, 'best' => $best];
                continue;
            }
            $run['languages'][$file] = $type->language;
            array_push($run['findings'], ...FixtureDrift::check($file, DtoMatch::recordsOf($document->root, $type), $type, $loaded['types']));
        }

        return $run;
    }

    /**
     * The paths a caller named, as project-relative paths of files or
     * directories inside the root; refused when one leaves it, is not there,
     * or is a file that is no JSON or YAML.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function requested(string $root, array $paths): array
    {
        $requested = [];
        foreach ($paths as $path) {
            $relative = rtrim(RelativePathRule::withoutDotSlash(ProjectRelativePath::of($root, $path)), '/');
            if ($relative === '' || $relative === '.') {
                return [];
            }
            if (ProjectFile::isDirectory($root, $relative)) {
                $requested[] = $relative;
            } elseif (!ProjectFile::exists($root, $relative)) {
                throw new InvalidArgumentException(sprintf("Requested fixture path '%s' does not exist in the project.", $path));
            } elseif (!FixturePaths::hasFixtureExtension($relative)) {
                throw new InvalidArgumentException(sprintf("Requested fixture path '%s' is not a JSON or YAML file or a directory.", $path));
            } else {
                $requested[] = $relative;
            }
        }

        return $requested;
    }

    /**
     * The findings the project's `test_finding_suppressions` leave.
     *
     * @param list<OnRequestFinding> $findings
     * @return list<OnRequestFinding>
     */
    private static function unsuppressed(array $findings, TestFindingSuppressions $suppressions): array
    {
        return array_values(array_filter($findings, static fn(OnRequestFinding $finding): bool => !$suppressions->suppresses($finding->ruleId, $finding->category->value, $finding->subRule, $finding->file)));
    }

    /**
     * The findings per packaged language and confidence, and per language
     * whose worker sent no field facts, a note saying so.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, string> $languages fixture file => its type's language
     * @param list<string> $factless
     * @return array<string, array<string, int|list<string>>>
     */
    private static function byLanguage(array $items, array $languages, array $factless): array
    {
        $zeros = array_fill_keys(TestRuleCatalog::confidences(), 0);
        $byLanguage = array_fill_keys(PackagedLanguages::languages(), $zeros);
        foreach ($items as $item) {
            $language = $languages[(string) $item['file']];
            $byLanguage[$language] ??= $zeros;
            ++$byLanguage[$language][(string) $item['confidence']];
        }
        foreach ($factless as $language) {
            $byLanguage[$language] ??= $zeros;
            $byLanguage[$language]['notes'] = ['FIXTURE_DRIFT: no field facts from worker ' . PackagedLanguages::scannerOf($language)];
        }
        ksort($byLanguage);

        return $byLanguage;
    }

    /**
     * What the result could not hold, and why.
     *
     * @param Run $run
     * @return list<string>
     */
    private static function warnings(array $run): array
    {
        $warnings = [];
        if ($run['truncated']) {
            $warnings[] = sprintf('The walk stopped at %d fixture files; name paths to check the rest.', FixtureFiles::MAX_FILES);
        }
        if ($run['partial']) {
            $warnings[] = 'The graph holds more nodes than one read takes; no fixture was checked.';
        }
        foreach (['unmatched', 'unparsable'] as $list) {
            if (count($run[$list]) > self::MAX_LISTED) {
                $warnings[] = sprintf('%d more %s files are not listed.', count($run[$list]) - self::MAX_LISTED, $list);
            }
        }

        return $warnings;
    }
}
