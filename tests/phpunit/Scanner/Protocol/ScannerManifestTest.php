<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Protocol;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\ScannerManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-protocol')]
final class ScannerManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function minimalData(): array
    {
        return [
            'id' => 'test.knossos',
            'version' => '0.1.0',
            'protocol_version' => '1.0',
            'output_schema_version' => '1.0',
            'languages' => ['php'],
            'file_extensions' => ['php'],
            'capabilities' => ['scan'],
        ];
    }

    // ----- shape -----

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(ScannerManifest::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    // ----- constructor happy path -----

    public function testConstructorStoresProperties(): void
    {
        $m = new ScannerManifest(
            id: 'test.knossos',
            version: '0.1.0',
            protocolVersion: '1.0',
            outputSchemaVersion: '1.0',
            languages: ['php', 'python'],
            fileExtensions: ['php', 'py'],
            capabilities: ['scan', 'cancel'],
        );

        assertSame('test.knossos', $m->id);
        assertSame('0.1.0', $m->version);
        assertSame('1.0', $m->protocolVersion);
        assertSame('1.0', $m->outputSchemaVersion);
        assertSame(['php', 'python'], $m->languages);
        assertSame(['php', 'py'], $m->fileExtensions);
        assertSame(['scan', 'cancel'], $m->capabilities);
    }

    // ----- constructor validation -----

    public function testRejectsEmptyId(): void
    {
        $data = self::minimalData();
        $data['id'] = '';

        $error = captureThrows(
            static fn() => ScannerManifest::fromArray($data),
            InvalidArgumentException::class,
        );
        assertSame('Scanner identity and version fields must not be empty.', $error->getMessage());
    }

    public function testRejectsEmptyVersion(): void
    {
        $data = self::minimalData();
        $data['version'] = '';

        $error = captureThrows(
            static fn() => ScannerManifest::fromArray($data),
            InvalidArgumentException::class,
        );
        assertSame('Scanner identity and version fields must not be empty.', $error->getMessage());
    }

    public function testRejectsEmptyProtocolVersion(): void
    {
        assertThrows(
            static fn() => new ScannerManifest('id', 'v', '', 'o', ['php'], ['php'], []),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyOutputSchemaVersion(): void
    {
        assertThrows(
            static fn() => new ScannerManifest('id', 'v', 'p', '', ['php'], ['php'], []),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyLanguagesList(): void
    {
        assertThrows(
            static fn() => new ScannerManifest('id', 'v', 'p', 'o', [], ['php'], []),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsNonStringInLanguages(): void
    {
        $data = self::minimalData();
        $data['languages'] = ['php', 42];

        $error = captureThrows(
            static fn() => ScannerManifest::fromArray($data),
            InvalidArgumentException::class,
        );
        assertSame('Manifest field "languages" must contain non-empty strings.', $error->getMessage());
    }

    public function testRejectsEmptyStringInFileExtensions(): void
    {
        $data = self::minimalData();
        $data['file_extensions'] = [''];

        $error = captureThrows(
            static fn() => ScannerManifest::fromArray($data),
            InvalidArgumentException::class,
        );
        assertSame('Manifest field "fileExtensions" must contain non-empty strings.', $error->getMessage());
    }

    public function testRejectsEmptyStringInCapabilities(): void
    {
        $data = self::minimalData();
        $data['capabilities'] = [''];

        $error = captureThrows(
            static fn() => ScannerManifest::fromArray($data),
            InvalidArgumentException::class,
        );
        assertSame('Manifest field "capabilities" must contain non-empty strings.', $error->getMessage());
    }

    // ----- fromArray -----

    public function testFromArrayConstructsFromValidData(): void
    {
        $m = ScannerManifest::fromArray(self::minimalData());

        assertSame('test.knossos', $m->id);
        assertSame('0.1.0', $m->version);
        assertSame('1.0', $m->protocolVersion);
        assertSame('1.0', $m->outputSchemaVersion);
        assertSame(['php'], $m->languages);
        assertSame(['php'], $m->fileExtensions);
        assertSame(['scan'], $m->capabilities);
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function missingStringFieldProvider(): array
    {
        return [
            'missing id' => ['id', null, 'id'],
            'version not a string' => ['version', 42, 'version'],
            'missing protocol_version' => ['protocol_version', null, 'protocol_version'],
            'output_schema_version not a string' => ['output_schema_version', true, 'output_schema_version'],
        ];
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function missingListFieldProvider(): array
    {
        return [
            'languages not a list' => ['languages', 'not-array', 'languages'],
            'file_extensions associative' => ['file_extensions', ['a' => 'php'], 'file_extensions'],
            'capabilities null' => ['capabilities', null, 'capabilities'],
        ];
    }

    #[DataProvider('missingStringFieldProvider')]
    public function testFromArrayRejectsInvalidStringField(string $key, mixed $value, string $expectedSnippet): void
    {
        $data = self::minimalData();
        $data[$key] = $value;

        assertThrows(
            static fn() => ScannerManifest::fromArray($data),
            InvalidArgumentException::class,
        );
    }

    #[DataProvider('missingListFieldProvider')]
    public function testFromArrayRejectsInvalidListField(string $key, mixed $value, string $expectedSnippet): void
    {
        $data = self::minimalData();
        $data[$key] = $value;

        assertThrows(
            static fn() => ScannerManifest::fromArray($data),
            InvalidArgumentException::class,
        );
    }

    // ----- jsonSerialize -----

    public function testJsonSerialization(): void
    {
        $m = ScannerManifest::fromArray(self::minimalData());
        $json = $m->jsonSerialize();

        assertSame('test.knossos', $json['id']);
        assertSame('0.1.0', $json['version']);
        assertSame('1.0', $json['protocol_version']);
        assertSame('1.0', $json['output_schema_version']);
        assertSame(['php'], $json['languages']);
        assertSame(['php'], $json['file_extensions']);
        assertSame(['scan'], $json['capabilities']);
    }

    // ----- source_hash -----

    public function testSourceHashIsOptionalAndLeftOutOfTheWireShapeWhenAbsent(): void
    {
        $m = ScannerManifest::fromArray(self::minimalData());

        assertSame(null, $m->sourceHash);
        $this->assertArrayNotHasKey('source_hash', $m->jsonSerialize());
    }

    public function testSourceHashRoundTripsWhenTheWorkerSendsOne(): void
    {
        $hash = str_repeat('0123456789abcdef', 4);
        $m = ScannerManifest::fromArray(self::minimalData() + ['source_hash' => $hash]);

        assertSame($hash, $m->sourceHash);
        assertSame($hash, $m->jsonSerialize()['source_hash']);
    }

    /** @return array<string, array{mixed}> */
    public static function malformedSourceHashes(): array
    {
        return [
            'not a string' => [42],
            'too short' => ['abc123'],
            'uppercase hex' => [str_repeat('0123456789ABCDEF', 4)],
            'not hex' => [str_repeat('z', 64)],
        ];
    }

    #[DataProvider('malformedSourceHashes')]
    public function testAMalformedSourceHashIsRefused(mixed $hash): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source_hash');

        ScannerManifest::fromArray(self::minimalData() + ['source_hash' => $hash]);
    }

    // ----- describing the launched worker -----

    /** @return array<string, array{0: string, 1: list<string>, 2: list<string>, 3: bool, 4: ?string}> */
    public static function descriptions(): array
    {
        return [
            'matching, languages in any order' => ['knossos.ts', ['javascript', 'typescript'], ['ts', 'vue'], true, null],
            'another id' => ['knossos.other', ['typescript', 'javascript'], ['ts'], true, 'answers as knossos.ts'],
            'other languages' => ['knossos.ts', ['typescript'], ['ts'], true, 'declares languages javascript, typescript, not typescript'],
            'a routed extension left out' => ['knossos.ts', ['typescript', 'javascript'], ['ts', 'astro'], true, 'does not declare the extensions astro'],
            'capability the descriptor lacks' => ['knossos.ts', ['typescript', 'javascript'], ['ts'], false, 'declares the added_files_affect_all capability'],
        ];
    }

    /** @param list<string> $languages @param list<string> $extensions */
    #[DataProvider('descriptions')]
    public function testAssertDescribesRefusesAManifestOfAnotherWorker(string $id, array $languages, array $extensions, bool $addedFiles, ?string $problem): void
    {
        $manifest = new ScannerManifest('knossos.ts', '1', '1.0', '1.0', ['typescript', 'javascript'], ['ts', 'vue'], ['added_files_affect_all']);
        try {
            $manifest->assertDescribes($id, $languages, $extensions, $addedFiles);
            assertSame(null, $problem);
        } catch (\Knossos\Scanner\Worker\WorkerException $error) {
            assertSame('WORKER_MANIFEST_INVALID', $error->diagnosticCode);
            assertSame(sprintf('The worker launched as %s %s.', $id, $problem), $error->getMessage());
        }
    }
}
