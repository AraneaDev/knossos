<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Result\ResultEnvelope;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * `export function hold({ tape }) { tape.hold(); }` in plain JavaScript, and
 * `hold({ tape: recorder() })` in another file of the program: only that call
 * says what `tape` holds, so `tape.hold()` is a probable call of the member
 * the value has, however the callee is reached (a named or default export, a
 * re-export, an imported table). The callee's facts then follow the caller's
 * file, which an incremental scan must see.
 */
#[Group('typescript-scanner')]
final class TypescriptCrossFileCallArgumentTest extends KnossosTestCase
{
    /** A cache row an incremental scan reused keeps this stamp; a rescanned one is rewritten. */
    private const UNTOUCHED = 'untouched';

    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            $this->removeTempTree($this->root);
        }
        parent::tearDown();
    }

    public function testAMethodCallOnADestructuredParameterReachesTheMemberAnotherFileHandsIt(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-cross-file-call-arguments',
                'files' => ['src/devices.js', 'src/flash.js', 'src/handlers.js', 'src/lib/frame.js', 'src/lib/index.js', 'src/shoot.js', 'src/steps.js', 'src/typed.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $calls = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'calls' && str_contains($edge->targetReference, '.{object}')) {
                    $calls[] = sprintf(
                        '%s %s -> %s %s',
                        $edge->confidence->value,
                        $edge->sourceReference,
                        substr($edge->targetReference, (int) strrpos($edge->targetReference, '#') + 1),
                        json_encode($edge->attributes, JSON_THROW_ON_ERROR),
                    );
                }
            }
        }
        sort($calls);

        // Two call sites hand `hold` two values, so it reaches both. The
        // typed function keeps the one edge its declared type gives, though
        // `flash` hands it a camera, and `items.clear()` on a `Map` is no
        // member of this project. `recorder`'s `idle` is called by nothing.
        self::assertSame([
            'certain ts:function:src/typed.ts#stopTyped -> recorder.{object}@3:12::stop []',
            'probable ts:function:src/lib/frame.js#frame -> recorder.{object}@3:12::stop {"inferred_from":"call_argument"}',
            'probable ts:function:src/steps.js#cut -> recorder.{object}@3:12::stop {"inferred_from":"call_argument"}',
            'probable ts:function:src/steps.js#hold -> camera.{object}@11:12::hold {"inferred_from":"call_argument"}',
            'probable ts:function:src/steps.js#hold -> recorder.{object}@3:12::hold {"inferred_from":"call_argument"}',
            'probable ts:method:src/handlers.js#HANDLERS::roll -> recorder.{object}@3:12::hold {"inferred_from":"call_argument"}',
        ], $calls);
    }

    /**
     * The callee's contribution reads the caller's file: editing the caller
     * rebuilds the callee and moves the edge, deleting it drops the edge, and
     * each step ends with the graph a full scan of the same bytes gives.
     */
    public function testEditingOrDeletingTheCallerRecomputesTheCalleesInferredCall(): void
    {
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
        $this->root = sys_get_temp_dir() . '/knossos-stale-ts-call-arguments-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        $this->write('jsconfig.json', '{"compilerOptions": {"checkJs": false}, "include": ["src"]}' . "\n");
        $this->write('src/devices.js', "export function recorder() {\n    return { hold() {} };\n}\n\nexport function camera() {\n    return { hold() {} };\n}\n");
        $this->write('src/steps.js', "export function hold({ tape }) {\n    tape.hold();\n}\n");
        $this->write('src/shoot.js', "import { hold } from './steps.js';\nimport { recorder } from './devices.js';\n\nhold({ tape: recorder() });\n");
        $this->write('src/other.js', "export const other = 1;\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        self::assertSame(['steps.js#hold -> recorder.{object}@2:12::hold'], $this->inferredCalls($pdo));
        $this->stampCacheRows($pdo);

        $this->write('src/shoot.js', "import { hold } from './steps.js';\nimport { camera } from './devices.js';\n\nhold({ tape: camera() });\n");
        $incremental = $this->scan($pdo);

        self::assertSame('incremental', $incremental->data['mode']);
        self::assertSame(['src/shoot.js', 'src/steps.js'], $this->rescannedFiles($pdo));
        self::assertSame(['steps.js#hold -> camera.{object}@6:12::hold'], $this->inferredCalls($pdo));
        $this->assertMatchesAFullScan($pdo);

        unlink($this->root . '/src/shoot.js');
        $this->scan($pdo);

        self::assertSame([], $this->inferredCalls($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * No read of the callee names a file that never handed it a literal, so
     * one that starts to changes the program's environment instead, and every
     * file of the program is rebuilt.
     */
    public function testAFileThatStartsHandingTheCalleeALiteralRebuildsTheProgram(): void
    {
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
        $this->root = sys_get_temp_dir() . '/knossos-stale-ts-call-arguments-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        $this->write('jsconfig.json', '{"compilerOptions": {"checkJs": false}, "include": ["src"]}' . "\n");
        $this->write('src/devices.js', "export function recorder() {\n    return { hold() {} };\n}\n");
        $this->write('src/steps.js', "export function hold({ tape }) {\n    tape.hold();\n}\n");
        $this->write('src/shoot.js', "import { hold } from './steps.js';\nimport { recorder } from './devices.js';\n\nhold(recorder);\n");
        $this->write('src/other.js', "export const other = 1;\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        self::assertSame([], $this->inferredCalls($pdo));
        $this->stampCacheRows($pdo);

        $this->write('src/shoot.js', "import { hold } from './steps.js';\nimport { recorder } from './devices.js';\n\nhold({ tape: recorder() });\n");
        $this->scan($pdo);

        self::assertSame(['src/devices.js', 'src/other.js', 'src/shoot.js', 'src/steps.js'], $this->rescannedFiles($pdo));
        self::assertSame(['steps.js#hold -> recorder.{object}@2:12::hold'], $this->inferredCalls($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * A function whose parameter has a declared type is typed by the checker,
     * whatever a call hands it, so a call that starts handing it a literal
     * changes nothing beyond its own file: the other files' rows stay. A
     * callback typed by its context elsewhere in the program does not count.
     */
    public function testALiteralCallIntoATypedCalleeRebuildsOnlyTheCaller(): void
    {
        $this->makeRoot();
        $this->write('tsconfig.json', '{"compilerOptions": {"strict": true}, "include": ["src"]}' . "\n");
        $this->write('src/steps.ts', "export type Options = { tape: { hold(): void } };\n\nexport function hold({ tape }: Options): void {\n    tape.hold();\n}\n");
        $this->write('src/ids.ts', "export const ids = (rows: { id: number }[]) => rows.map(({ id }) => id);\n");
        $this->write('src/shoot.ts', "import { hold } from './steps';\n\nexport const shoot = hold;\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $this->stampCacheRows($pdo);

        $this->write('src/shoot.ts', "import { hold } from './steps';\n\nexport const shoot = (): void => hold({ tape: { hold() {} } });\n");
        $this->scan($pdo);

        self::assertSame(['src/shoot.ts'], $this->rescannedFiles($pdo));
        self::assertSame([], $this->inferredCalls($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * `p/src/main.js` imports `q/src/helper.js`, which the q config owns, and
     * that file hands p's `hold` a literal. Only the q program describes the
     * helper, and it does not emit `hold`'s file, so `hold` gets nothing.
     */
    public function testACallFromAFileAnotherConfigOwnsGivesTheCalleeNothing(): void
    {
        $this->makeRoot();
        mkdir($this->root . '/p/src', 0o777, true);
        mkdir($this->root . '/q/src', 0o777, true);
        $config = '{"compilerOptions": {"allowJs": true, "checkJs": false}, "include": ["src"]}' . "\n";
        $this->write('p/tsconfig.json', $config);
        $this->write('q/tsconfig.json', $config);
        $this->write('p/src/devices.js', "export function recorder() {\n    return { hold() {} };\n}\n");
        $this->write('p/src/steps.js', "export function hold({ tape }) {\n    tape.hold();\n}\n");
        $this->write('p/src/main.js', "import { helper } from '../../q/src/helper.js';\n\nhelper();\n");
        $this->write('q/src/helper.js', "import { hold } from '../../p/src/steps.js';\nimport { recorder } from '../../p/src/devices.js';\n\nexport function helper() {\n    hold({ tape: recorder() });\n}\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        self::assertSame([], $this->inferredCalls($pdo));
        self::assertNotContains('q/src/helper.js', $pdo->query("SELECT read_path FROM contribution_reads WHERE owner_key = 'knossos.typescript:file:p/src/steps.js'")->fetchAll(PDO::FETCH_COLUMN));
    }

    private function makeRoot(): void
    {
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
        $this->root = sys_get_temp_dir() . '/knossos-stale-ts-call-arguments-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
    }

    private function scan(PDO $pdo): ResultEnvelope
    {
        return (new ProjectScanService($pdo, self::repositoryRoot(), [(string) $this->root]))->scan((string) $this->root);
    }

    /** @return list<string> every `call_argument` edge, as `file#source -> target member` */
    private function inferredCalls(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT s.canonical_name source_name, t.canonical_name target_name FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id WHERE e.kind = 'calls' AND e.confidence = 'probable' AND e.attributes_json LIKE '%call_argument%' ORDER BY 1, 2")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn(array $row): string => basename((string) $row['source_name']) . ' -> ' . substr((string) $row['target_name'], (int) strrpos((string) $row['target_name'], '#') + 1),
            $rows,
        );
    }

    private function stampCacheRows(PDO $pdo): void
    {
        $pdo->prepare('UPDATE contribution_cache SET updated_at = ?')->execute([self::UNTOUCHED]);
    }

    /** @return list<string> the files whose contribution the last scan rewrote */
    private function rescannedFiles(PDO $pdo): array
    {
        $statement = $pdo->prepare("SELECT file_path FROM contribution_cache WHERE scanner_id = 'knossos.typescript' AND updated_at <> ? ORDER BY file_path");
        $statement->execute([self::UNTOUCHED]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function assertMatchesAFullScan(PDO $incremental): void
    {
        $full = $this->freshTestDatabase();
        $this->scan($full);
        self::assertSame($this->graphSignature($full), $this->graphSignature($incremental));
    }

    private function write(string $relative, string $contents): void
    {
        file_put_contents($this->root . '/' . $relative, $contents);
    }
}
