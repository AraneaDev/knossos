<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\TestFidelity\Rules\{MocksSubjectRule, TautologyRule};
use Knossos\TestFidelity\TestFidelityAnalysis;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The tautology and mocks-subject rules over what the real TypeScript worker
 * emits: a healthy test that mocks a dependency, builds it and asserts on
 * production stays quiet; each planted case is found once.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TautologyEndToEndTest extends KnossosTestCase
{
    use ScansTypeScript;

    private const FILES = [
        'tsconfig.json' => '{"compilerOptions":{"strict":true,"module":"esnext","target":"es2022","moduleResolution":"bundler"}}',
        'src/db.ts' => "export class Db {\n    query(): number { return 1; }\n}\n",
        'src/ledger.ts' => "import { Db } from './db';\nexport class Ledger {\n    constructor(private db: Db) {}\n    total(): number { return this.db.query() * 2; }\n}\n",
        'tests/ledger.test.ts' => <<<'TS'
            import { expect, it, vi } from 'vitest';
            import { Db } from '../src/db';
            import { Ledger } from '../src/ledger';
            vi.mock('../src/db');
            it('totals through the ledger', () => {
                const db = new Db();
                vi.spyOn(db, 'query').mockReturnValue(5);
                const ledger = new Ledger(db);
                expect(ledger.total()).toBe(10);
                expect(db.query).toHaveBeenCalled();
            });
            it('computes again', () => {
                const ledger = new Ledger(new Db());
                const total = ledger.total();
                expect(ledger.total()).toBe(ledger.total());
                expect(total).not.toBe(total);
            });
            TS,
        'tests/planted.test.ts' => <<<'TS'
            import { expect, it, vi } from 'vitest';
            import { Db } from '../src/db';
            vi.mock('../src/db');
            it('queries the mocked db', () => {
                const db = new Db();
                expect(db.query()).toBe(db.query());
            });
            it('is always true', () => {
                expect(true).toBe(true);
            });
            it('echoes its stub', () => {
                const fake = { query: vi.fn() };
                fake.query.mockReturnValue(42);
                expect(fake.query()).toBe(42);
            });
            it('asserts a spy nothing calls', () => {
                const target = { run: (): number => 1 };
                const spy = vi.spyOn(target, 'run');
                expect(spy).toHaveBeenCalled();
            });
            it('pins what nothing calls', () => {
                const unused = { load: vi.fn() };
                unused.load.mockReturnValue(1);
                expect(1 + 1).toBe(2);
            });
            TS,
    ];

    /** @return list<string> */
    private function findings(): array
    {
        $findings = (new TestFidelityAnalysis(new TautologyRule(), new MocksSubjectRule()))->analyze($this->scanTypeScript(self::FILES));

        return array_map(static fn(TestFinding $finding): string => sprintf('%s:%d %s %s', $finding->evidence->relativePath, $finding->evidence->startLine, $finding->subRule, $finding->confidence->value), $findings);
    }

    public function testHealthyTestsGiveNothingAndEachPlantedCaseIsFoundOnce(): void
    {
        assertSame([
            'tests/planted.test.ts:3 mock_of_self warning',
            'tests/planted.test.ts:9 self_comparison definite',
            'tests/planted.test.ts:14 mock_echo definite',
            'tests/planted.test.ts:19 unconfigured_spy warning',
            'tests/planted.test.ts:22 zero_reach warning',
        ], $this->findings());
    }
}
