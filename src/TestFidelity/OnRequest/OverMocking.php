<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\OnRequest;

use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\OnRequestCategory;

/**
 * `OVER_MOCKING`: a test file whose dependencies are mostly doubles, and
 * fewer than two of whose assertions read production code. The port of Momus
 * MOCK-001 (`packages/core/src/rules/hygiene.ts:56-96`), per test file as
 * Momus is.
 *
 * Both sides are counted per module (preflight F2), as Momus dedupes both:
 * - **mocked**: the distinct modules declaring a double's target. A double
 *   with no target, or whose target the test file declares itself, is not
 *   counted (`hygiene.ts:66-73`);
 * - **dependencies**: the distinct modules the file imports at module level,
 *   less its language's test frameworks ({@see FrameworkModules}), plus each
 *   mocked module the file does not import (a `patch("pkg.mod.x")` string).
 *
 * Always a warning at line 1 of the test file: a ratio is a heuristic, and
 * Momus's own severity is `warning`. A language whose worker sends no
 * `imports` edge from any test module (the PHP worker) has no known
 * dependency, so it is silent, and {@see self::languagesWithoutImports()}
 * says which.
 */
final class OverMocking
{
    /** The rule id every finding carries. */
    public const ID = 'test.fidelity.over_mocking.v1';

    /** Momus's name for the rule, `mock-saturation`, as the sub-rule (preflight F20). */
    public const SUB_RULE = 'saturation';

    /**
     * The share of dependencies mocked at which a file is over-mocked: Momus's
     * `mockSaturationThreshold` default, 0.7 (`hygiene.ts:63`), held as 7 in 10
     * so the comparison is exact.
     */
    private const THRESHOLD_MOCKED = 7;

    private const THRESHOLD_OF = 10;

    /** As many assertions reading production code as clear a file: Momus's `productionAssertions < 2` (`hygiene.ts:84`). */
    private const PRODUCTION_ASSERTIONS = 2;

    private function __construct() {}

    /**
     * Every over-mocked test file, in file order.
     *
     * @return list<OnRequestFinding>
     */
    public static function findings(OnRequestInput $input): array
    {
        $silent = array_flip(self::languagesWithoutImports($input));
        $findings = [];
        $files = array_keys($input->testFiles);
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $language = $input->testFiles[$file];
            if (isset($silent[$language])) {
                continue;
            }
            $finding = self::judge($file, $language, $input);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * The languages of the test files whose worker sent no import from any
     * test module, so this rule cannot judge them; in name order.
     *
     * @return list<string>
     */
    public static function languagesWithoutImports(OnRequestInput $input): array
    {
        $languages = [];
        foreach ($input->testFiles as $file => $language) {
            $languages[$language] ??= false;
            if (($input->imports[$file] ?? []) !== []) {
                $languages[$language] = true;
            }
        }
        $silent = array_keys(array_filter($languages, static fn(bool $imports): bool => !$imports));
        sort($silent, SORT_STRING);

        return $silent;
    }

    /**
     * Whether an outside Python package is the package of a project module
     * the same file imports: `from postal import mailer`, with `postal/` a
     * namespace package, sends the outside `postal` beside `postal.mailer`,
     * and that is one import, as Momus counts it.
     *
     * @param list<array{module: string, name: string, local: bool}> $imports
     */
    private static function holdsAProjectImport(string $language, string $package, array $imports): bool
    {
        if ($language !== 'python') {
            return false;
        }
        foreach ($imports as $import) {
            if (!$import['local'] && !str_starts_with($import['module'], 'external:') && str_starts_with($import['name'], $package . '.')) {
                return true;
            }
        }

        return false;
    }

    /** The finding on one test file, or null when it is not over-mocked. */
    private static function judge(string $file, string $language, OnRequestInput $input): ?OnRequestFinding
    {
        $dependencies = [];
        $imports = $input->imports[$file] ?? [];
        foreach ($imports as $import) {
            $external = str_starts_with($import['module'], 'external:');
            if ($import['local'] || $import['module'] === $file || ($external && (FrameworkModules::contains($language, $import['name']) || self::holdsAProjectImport($language, $import['name'], $imports)))) {
                continue;
            }
            $dependencies[$import['module']] = true;
        }
        $mocked = [];
        foreach ($input->doubles[$file] ?? [] as $double) {
            $module = $double['module'];
            if ($module === null || $module === $file) {
                continue;
            }
            $mocked[$module] = true;
            $dependencies[$module] = true;
        }
        if ($dependencies === []) {
            return null;
        }
        $production = 0;
        foreach ($input->assertions[$file] ?? [] as $assertion) {
            foreach ($assertion->operands as $operand) {
                if ($operand->source === 'production') {
                    ++$production;
                    break;
                }
            }
        }
        if (count($mocked) * self::THRESHOLD_OF < count($dependencies) * self::THRESHOLD_MOCKED || $production >= self::PRODUCTION_ASSERTIONS) {
            return null;
        }

        return new OnRequestFinding(
            OnRequestCategory::OverMocking,
            FindingConfidence::Warning,
            self::ID,
            self::SUB_RULE,
            sprintf('%d/%d dependencies mocked, %d production assertions: over-mocked', count($mocked), count($dependencies), $production),
            $file,
            1,
        );
    }
}
