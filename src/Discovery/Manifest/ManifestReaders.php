<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

use Knossos\Discovery\UnitKind;

/**
 * The reader for each unit kind: a text reader for the kinds that are not
 * JSON, a JSON reader for the ones discovery decodes first.
 *
 * The match names every kind, so a kind added to {@see UnitKind} without a
 * reader fails static analysis rather than reading as an empty unit.
 */
final class ManifestReaders
{
    /** The reader for a kind that is not JSON, or null when the kind is JSON. */
    public static function text(UnitKind $kind): ?ManifestReader
    {
        $reader = self::reader($kind);

        return $reader instanceof ManifestReader ? $reader : null;
    }

    /** The reader for a JSON kind, or null when the kind is not JSON. */
    public static function json(UnitKind $kind): ?JsonManifestReader
    {
        $reader = self::reader($kind);

        return $reader instanceof JsonManifestReader ? $reader : null;
    }

    /** The one reader for $kind. */
    private static function reader(UnitKind $kind): ManifestReader|JsonManifestReader
    {
        return match ($kind) {
            UnitKind::Python => new PyprojectManifest(),
            UnitKind::Cargo => new CargoManifest(),
            UnitKind::Gitignore => new GitIgnoreManifest(),
            UnitKind::Requirements => new RequirementsManifest(),
            UnitKind::Html => new HtmlManifest(),
            UnitKind::Yaml => new YamlManifest(),
            UnitKind::Dockerfile => new DockerfileManifest(),
            UnitKind::Readme => new ReadmeManifest(),
            UnitKind::Markdown => new MarkdownManifest(),
            UnitKind::Shell => new ShellManifest(),
            UnitKind::AgentConfig => new AgentConfigManifest(),
            UnitKind::ToolConfig => new ToolConfigManifest(),
            UnitKind::Composer => new ComposerManifest(),
            UnitKind::Node => new NodeManifest(),
            UnitKind::AzureFunction => new AzureFunctionManifest(),
            UnitKind::Typescript => new TsconfigManifest(),
            UnitKind::Knip => new KnipManifest(),
            UnitKind::Knossos => new KnossosManifest(),
        };
    }
}
