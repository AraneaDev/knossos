<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\OnRequest;

use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\OnRequestCategory;
use Knossos\TestFidelity\Rules\DriftText;

/**
 * `STALE_SINCE`: a double whose target changed since a git ref while the
 * test file holding the double did not. The port of Momus DRIFT-006
 * (`packages/core/src/rules/drift.ts:646-685`), at Momus's granularity, the
 * file.
 *
 * A target changed when any file it lives in is in the changed set: its own,
 * a `contains` child's (a Rust `impl` in another file), or one declaring a
 * member the double stubs on a type the target extends or uses (a PHP trait).
 *
 * Only in scope `git-diff`: without a changed set it says nothing. One
 * finding per test file and target, at the first double of it, reviewing
 * every member the file's doubles of it stub; Momus gives one per double,
 * but two doubles of one target in one file would give one key twice.
 *
 * Always a warning: a changed file does not prove the double wrong.
 */
final class StaleSince
{
    /** The rule id every finding carries. */
    public const ID = 'test.fidelity.stale_since.v1';

    /** Momus's name for the rule, `stale-mock`, as the sub-rule (preflight F20). */
    public const SUB_RULE = 'stale_mock';

    /** The members a message names at most, as Momus's `memberNames.slice(0, 4)`. */
    private const MEMBERS = 4;

    /** The message cap, {@see DriftText::clean()}'s. */
    private const MAX_LENGTH = 120;

    private function __construct() {}

    /**
     * Every stale double in the input's changed set, in file and line order;
     * none outside scope `git-diff`.
     *
     * @param string $baseRef the ref the changed set is read against, as the message names it
     * @return list<OnRequestFinding>
     */
    public static function findings(OnRequestInput $input, string $baseRef): array
    {
        if ($input->changed === null) {
            return [];
        }
        $changed = array_fill_keys($input->changed, true);
        $files = array_keys($input->targets);
        sort($files, SORT_STRING);
        $findings = [];
        foreach ($files as $file) {
            if (isset($changed[$file])) {
                continue;
            }
            foreach (self::staleTargets($input->targets[$file], $changed) as $stale) {
                $findings[] = new OnRequestFinding(
                    OnRequestCategory::StaleSince,
                    FindingConfidence::Warning,
                    self::ID,
                    self::SUB_RULE,
                    self::message($baseRef, $stale['target'], $file, $stale['members']),
                    $file,
                    $stale['line'],
                    $stale['double'],
                    $stale['target'],
                    $stale['canonical'],
                );
            }
        }
        return $findings;
    }

    /**
     * One test file's doubles whose target changed, one per target: the
     * first double's name and line, and every member the file stubs on it.
     *
     * @param list<array{double: string, line: int, target: string, canonical: string, files: list<string>, inherited: array<string, list<string>>, members: list<string>}> $doubles
     * @param array<string, true> $changed
     * @return list<array{double: string, line: int, target: string, canonical: string, members: list<string>}>
     */
    private static function staleTargets(array $doubles, array $changed): array
    {
        usort($doubles, static fn(array $a, array $b): int => [$a['line'], strcmp($a['canonical'], $b['canonical'])] <=> [$b['line'], 0]);
        $stale = [];
        foreach ($doubles as $double) {
            if (!self::changed($double, $changed)) {
                continue;
            }
            $canonical = $double['canonical'];
            $stale[$canonical] ??= ['double' => $double['double'], 'line' => $double['line'], 'target' => $double['target'], 'canonical' => $canonical, 'members' => []];
            $stale[$canonical]['members'] = array_values(array_unique([...$stale[$canonical]['members'], ...$double['members']]));
        }

        return array_values($stale);
    }

    /**
     * Whether a file the double's target lives in changed.
     *
     * @param array{files: list<string>, inherited: array<string, list<string>>, members: list<string>} $double
     * @param array<string, true> $changed
     */
    private static function changed(array $double, array $changed): bool
    {
        $files = $double['files'];
        foreach ($double['members'] as $member) {
            array_push($files, ...$double['inherited'][$member] ?? []);
        }
        foreach ($files as $file) {
            if (isset($changed[$file])) {
                return true;
            }
        }

        return false;
    }

    /**
     * `stale since {ref}: {target} changed, {test file} untouched; review: {members}`,
     * naming as many of the first four members, whole, as the cap leaves room
     * for, and no review list when none fits. A head over the cap is cut as
     * any message is.
     *
     * @param list<string> $members
     */
    private static function message(string $baseRef, string $target, string $file, array $members): string
    {
        $head = DriftText::clean(sprintf('stale since %s: %s changed, %s untouched', $baseRef, $target, $file));
        $listed = [];
        foreach (array_slice($members, 0, self::MEMBERS) as $member) {
            $tail = '; review: ' . implode(', ', [...$listed, DriftText::clean($member)]);
            if (mb_strlen($head . $tail) > self::MAX_LENGTH) {
                break;
            }
            $listed[] = DriftText::clean($member);
        }

        return $listed === [] ? $head : $head . '; review: ' . implode(', ', $listed);
    }
}
