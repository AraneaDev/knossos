<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery\Manifest;

use Knossos\Discovery\Manifest\AgentConfigManifest;
use Knossos\Discovery\Manifest\AzureFunctionManifest;
use Knossos\Discovery\Manifest\CargoManifest;
use Knossos\Discovery\Manifest\ComposerManifest;
use Knossos\Discovery\Manifest\DockerfileManifest;
use Knossos\Discovery\Manifest\GitIgnoreManifest;
use Knossos\Discovery\Manifest\HtmlManifest;
use Knossos\Discovery\Manifest\KnipManifest;
use Knossos\Discovery\Manifest\KnossosManifest;
use Knossos\Discovery\Manifest\ManifestReaders;
use Knossos\Discovery\Manifest\NodeManifest;
use Knossos\Discovery\Manifest\PyprojectManifest;
use Knossos\Discovery\Manifest\ReadmeManifest;
use Knossos\Discovery\Manifest\RequirementsManifest;
use Knossos\Discovery\Manifest\ShellManifest;
use Knossos\Discovery\Manifest\ToolConfigManifest;
use Knossos\Discovery\Manifest\TsconfigManifest;
use Knossos\Discovery\Manifest\YamlManifest;
use Knossos\Discovery\UnitKind;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Every unit kind discovery names reaches the reader for it, and a kind is
 * either read as text or decoded as JSON first, never both.
 */
#[Group('discovery')]
final class ManifestReadersTest extends TestCase
{
    public function testEachTextKindHasItsReader(): void
    {
        $readers = [
            'python' => PyprojectManifest::class,
            'cargo' => CargoManifest::class,
            'gitignore' => GitIgnoreManifest::class,
            'requirements' => RequirementsManifest::class,
            'html' => HtmlManifest::class,
            'yaml' => YamlManifest::class,
            'dockerfile' => DockerfileManifest::class,
            'readme' => ReadmeManifest::class,
            'shell' => ShellManifest::class,
            'agent_config' => AgentConfigManifest::class,
            'tool_config' => ToolConfigManifest::class,
        ];
        foreach ($readers as $kind => $class) {
            self::assertInstanceOf($class, ManifestReaders::text(UnitKind::from($kind)), $kind);
            self::assertNull(ManifestReaders::json(UnitKind::from($kind)), $kind);
        }
    }

    public function testEachJsonKindHasItsReader(): void
    {
        $readers = [
            'composer' => ComposerManifest::class,
            'node' => NodeManifest::class,
            'azure_function' => AzureFunctionManifest::class,
            'typescript' => TsconfigManifest::class,
            'knip' => KnipManifest::class,
            'knossos' => KnossosManifest::class,
        ];
        foreach ($readers as $kind => $class) {
            self::assertInstanceOf($class, ManifestReaders::json(UnitKind::from($kind)), $kind);
            self::assertNull(ManifestReaders::text(UnitKind::from($kind)), $kind);
        }
    }

    public function testAnUnknownKindIsNoUnitKind(): void
    {
        self::assertNull(UnitKind::tryFrom('unknown'));
    }

    /** Every kind has exactly one reader, so none is read both ways or dropped. */
    public function testEveryKindHasExactlyOneReader(): void
    {
        foreach (UnitKind::cases() as $kind) {
            self::assertTrue((ManifestReaders::text($kind) === null) !== (ManifestReaders::json($kind) === null), $kind->value);
        }
    }

    /** Only JSON kinds are read with comments allowed regardless of extension. */
    public function testOnlyTsconfigAndKnossosConfigAreJsonc(): void
    {
        $jsonc = array_values(array_filter(UnitKind::cases(), static fn(UnitKind $kind): bool => $kind->isJsonc()));

        self::assertSame([UnitKind::Knossos, UnitKind::Typescript], $jsonc);
    }

    /**
     * Every manifest root infers a boundary except a pip requirements file,
     * which declares an environment, not a package.
     */
    public function testManifestRootsAndBoundaryKinds(): void
    {
        $roots = array_values(array_map(static fn(UnitKind $kind): string => $kind->value, array_filter(UnitKind::cases(), static fn(UnitKind $kind): bool => $kind->isManifestRoot())));
        sort($roots);
        self::assertSame(['cargo', 'composer', 'node', 'python', 'requirements', 'typescript'], $roots);
        $boundaries = array_values(array_map(
            static fn(UnitKind $kind): string => $kind->value,
            array_filter(UnitKind::cases(), static fn(UnitKind $kind): bool => $kind->infersBoundary()),
        ));
        sort($boundaries);
        self::assertSame(['cargo', 'composer', 'node', 'python', 'typescript'], $boundaries);
    }

    /** The two readers with no parsing of their own answer exactly what the unit carries. */
    public function testTheTrivialReadersReturnTheirFixedShape(): void
    {
        assertSame([], (new GitIgnoreManifest())->read('.gitignore', '/p/.gitignore', "dist/\n"));
        assertSame(['version' => 1], (new KnossosManifest())->read('knossos.json', '/p/knossos.json', ['version' => 1]));
        assertSame(['version' => null], (new KnossosManifest())->read('knossos.json', '/p/knossos.json', []));
    }
}
