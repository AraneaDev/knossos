<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\OnRequest;

use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\OnRequest\OnRequestInput;
use Knossos\TestFidelity\OnRequest\StaleSince;
use Knossos\TestFidelity\OnRequestCategory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `STALE_SINCE`, the port of Momus DRIFT-006 (`packages/core/src/rules/drift.ts:646-685`),
 * with its cases from `packages/core/test/diff.test.ts:112-182`: a double
 * whose target's file changed since the base ref while the double's own file
 * did not.
 */
#[Group('test-fidelity')]
final class StaleSinceTest extends TestCase
{
    private const TEST = 'tests/ledger.test.ts';

    private const PROD = 'src/ledger.ts';

    public function testAChangedTargetFileWithAnUntouchedTestFires(): void
    {
        $findings = StaleSince::findings(self::input([self::PROD]), 'main');

        self::assertCount(1, $findings);
        $finding = $findings[0];
        self::assertSame(
            [OnRequestCategory::StaleSince, 'test.fidelity.stale_since.v1', 'stale_mock', self::TEST, 5, 'vi.spyOn LedgerService.totalFor', 'LedgerService', 'src/ledger.ts#LedgerService'],
            [$finding->category, $finding->ruleId, $finding->subRule, $finding->file, $finding->line, $finding->double, $finding->target, $finding->targetCanonical],
        );
        self::assertSame('stale since main: LedgerService changed, tests/ledger.test.ts untouched; review: totalFor', $finding->message);
        // The target did not change: nothing to review.
        self::assertSame([], StaleSince::findings(self::input(['src/other.ts']), 'main'));
        self::assertSame([], StaleSince::findings(self::input([]), 'main'));
    }

    public function testAChangedTestFileIsSilent(): void
    {
        self::assertSame([], StaleSince::findings(self::input([self::PROD, self::TEST]), 'main'));
    }

    public function testWithoutAChangedSetNothingFires(): void
    {
        // Outside scope git-diff the input carries no changed set (Momus: "stays quiet in workspace mode").
        $input = self::input([self::PROD]);
        $outside = new OnRequestInput($input->testFiles, [], [], [], targets: $input->targets);

        self::assertSame([], StaleSince::findings($outside, 'main'));
    }

    public function testAModuleDoubleFiresOnItsModulePath(): void
    {
        $double = ['double' => 'vi.mock ../src/ledger', 'line' => 3, 'target' => 'ledger.ts', 'canonical' => self::PROD, 'files' => [self::PROD], 'inherited' => [], 'members' => ['LedgerService']];
        $input = new OnRequestInput([self::TEST => 'typescript'], [], [], [], targets: [self::TEST => [$double]], changed: [self::PROD]);

        $findings = StaleSince::findings($input, 'HEAD');
        self::assertCount(1, $findings);
        self::assertSame('stale since HEAD: ledger.ts changed, tests/ledger.test.ts untouched; review: LedgerService', $findings[0]->message);
        self::assertSame(3, $findings[0]->line);
        $elsewhere = new OnRequestInput([self::TEST => 'typescript'], [], [], [], targets: [self::TEST => [$double]], changed: ['src/other.ts']);
        self::assertSame([], StaleSince::findings($elsewhere, 'HEAD'));
    }

    public function testAMemberInAnotherFileCounts(): void
    {
        // A PHP class whose stubbed `greet` comes from a trait in another file.
        $php = ['double' => 'createMock App\\Greeter', 'line' => 7, 'target' => 'Greeter', 'canonical' => 'App\\Greeter', 'files' => ['src/Greeter.php'], 'inherited' => ['greet' => ['src/Greets.php'], 'wave' => ['src/Waves.php']], 'members' => ['greet']];
        $file = 'tests/GreeterTest.php';
        $trait = new OnRequestInput([$file => 'php'], [], [], [], targets: [$file => [$php]], changed: ['src/Greets.php']);
        self::assertSame(['stale since HEAD: Greeter changed, tests/GreeterTest.php untouched; review: greet'], self::messages(StaleSince::findings($trait, 'HEAD')));
        // A trait member the double does not stub is not the double's concern.
        $unstubbed = new OnRequestInput([$file => 'php'], [], [], [], targets: [$file => [$php]], changed: ['src/Waves.php']);
        self::assertSame([], StaleSince::findings($unstubbed, 'HEAD'));

        // A Rust type whose `impl` block lives in another file: its method is a `contains` child there.
        $rust = ['double' => 'mockall::mock MockWall', 'line' => 3, 'target' => 'Wall', 'canonical' => 'crate::clock::Wall', 'files' => ['src/clock.rs', 'src/clock_impl.rs'], 'inherited' => [], 'members' => ['tick']];
        $impl = new OnRequestInput(['tests/clock.rs' => 'rust'], [], [], [], targets: ['tests/clock.rs' => [$rust]], changed: ['src/clock_impl.rs']);
        self::assertSame(['stale since HEAD: Wall changed, tests/clock.rs untouched; review: tick'], self::messages(StaleSince::findings($impl, 'HEAD')));
    }

    public function testDoublesOfOneTargetInOneFileGiveOneFinding(): void
    {
        $first = ['double' => 'vi.spyOn LedgerService.totalFor', 'line' => 9, 'target' => 'LedgerService', 'canonical' => 'src/ledger.ts#LedgerService', 'files' => [self::PROD], 'inherited' => [], 'members' => ['totalFor']];
        $second = ['line' => 4, 'double' => 'vi.spyOn LedgerService.post', 'members' => ['post', 'totalFor']] + $first;
        $input = new OnRequestInput([self::TEST => 'typescript'], [], [], [], targets: [self::TEST => [$first, $second]], changed: [self::PROD]);

        $findings = StaleSince::findings($input, 'main');
        self::assertCount(1, $findings);
        // At the first double, reviewing every member the file stubs, once each.
        self::assertSame([4, 'vi.spyOn LedgerService.post'], [$findings[0]->line, $findings[0]->double]);
        self::assertSame('stale since main: LedgerService changed, tests/ledger.test.ts untouched; review: post, totalFor', $findings[0]->message);
    }

    public function testFindingsAreInFileThenLineOrder(): void
    {
        $late = ['double' => 'vi.spyOn LedgerService.totalFor', 'line' => 30, 'target' => 'LedgerService', 'canonical' => 'src/ledger.ts#LedgerService', 'files' => [self::PROD], 'inherited' => [], 'members' => []];
        $early = ['line' => 2, 'target' => 'Audit', 'canonical' => 'src/ledger.ts#Audit'] + $late;
        $input = new OnRequestInput(['tests/b.test.ts' => 'typescript', 'tests/a.test.ts' => 'typescript'], [], [], [], targets: ['tests/b.test.ts' => [$early], 'tests/a.test.ts' => [$late, $early]], changed: [self::PROD]);

        self::assertSame(
            [['tests/a.test.ts', 2], ['tests/a.test.ts', 30], ['tests/b.test.ts', 2]],
            array_map(static fn($finding): array => [$finding->file, $finding->line], StaleSince::findings($input, 'main')),
        );
    }

    public function testMessageCutsMembersFirst(): void
    {
        $members = ['alpha', 'bravo', 'charlie', 'delta', 'echo'];
        $five = self::input([self::PROD], $members);
        // Up to four members.
        self::assertSame('stale since main: LedgerService changed, tests/ledger.test.ts untouched; review: alpha, bravo, charlie, delta', self::messages(StaleSince::findings($five, 'main'))[0]);

        // A long ref leaves room for fewer: members go whole, before the head is cut.
        $ref = str_repeat('r', 30);
        $message = self::messages(StaleSince::findings($five, $ref))[0];
        self::assertSame('stale since ' . $ref . ': LedgerService changed, tests/ledger.test.ts untouched; review: alpha, bravo', $message);
        self::assertLessThanOrEqual(120, mb_strlen($message));
        // Exactly at the cap, the member fits; one character more, it goes.
        $fits = self::input([self::PROD], [str_repeat('m', 120 - mb_strlen('stale since main: LedgerService changed, tests/ledger.test.ts untouched; review: '))]);
        self::assertSame(120, mb_strlen(self::messages(StaleSince::findings($fits, 'main'))[0]));
        $over = self::input([self::PROD], [str_repeat('m', 121 - mb_strlen('stale since main: LedgerService changed, tests/ledger.test.ts untouched; review: '))]);
        self::assertSame('stale since main: LedgerService changed, tests/ledger.test.ts untouched', self::messages(StaleSince::findings($over, 'main'))[0]);

        // No member fits a head over the cap: the head is cut, as any message is.
        $long = StaleSince::findings(self::input([self::PROD]), str_repeat('x', 150));
        self::assertSame(120, mb_strlen($long[0]->message));
        self::assertStringEndsWith('…', $long[0]->message);
        self::assertStringNotContainsString('review', $long[0]->message);
    }

    public function testAlwaysWarning(): void
    {
        $findings = StaleSince::findings(self::input([self::PROD], ['totalFor', 'post']), 'main');

        self::assertNotSame([], $findings);
        foreach ($findings as $finding) {
            self::assertSame(FindingConfidence::Warning, $finding->confidence);
            self::assertSame('warning', $finding->toItem()['confidence']);
            self::assertSame('on_request', $finding->toItem()['tier']);
        }
    }

    /**
     * One test file with one double of `LedgerService`, and the files changed since the ref.
     *
     * @param list<string> $changed
     * @param list<string> $members
     */
    private static function input(array $changed, array $members = ['totalFor']): OnRequestInput
    {
        $double = ['double' => 'vi.spyOn LedgerService.totalFor', 'line' => 5, 'target' => 'LedgerService', 'canonical' => 'src/ledger.ts#LedgerService', 'files' => [self::PROD], 'inherited' => [], 'members' => $members];

        return new OnRequestInput([self::TEST => 'typescript'], [], [], [], targets: [self::TEST => [$double]], changed: $changed);
    }

    /**
     * @param list<\Knossos\TestFidelity\OnRequest\OnRequestFinding> $findings
     * @return list<string>
     */
    private static function messages(array $findings): array
    {
        return array_map(static fn($finding): string => $finding->message, $findings);
    }
}
