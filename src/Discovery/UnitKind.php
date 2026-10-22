<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * Every kind of project unit discovery records, with what each one is.
 *
 * The value is the kind string a {@see ProjectUnit} carries and the
 * contribution cache keys hash, so a value never changes. Whether a kind is
 * read as JSON with comments, anchors a manifest root or infers a boundary is
 * answered here, once, for every consumer; the reader for each kind is in
 * {@see Manifest\ManifestReaders}.
 */
enum UnitKind: string
{
    case Gitignore = 'gitignore';
    case Readme = 'readme';
    case Markdown = 'markdown';
    case Shell = 'shell';
    case Dockerfile = 'dockerfile';
    case Composer = 'composer';
    case Knossos = 'knossos';
    case Node = 'node';
    case AzureFunction = 'azure_function';
    case Html = 'html';
    case Yaml = 'yaml';
    case AgentConfig = 'agent_config';
    case Knip = 'knip';
    case Python = 'python';
    case Requirements = 'requirements';
    case Typescript = 'typescript';
    case Cargo = 'cargo';
    case ToolConfig = 'tool_config';

    /** Whether the JSON is read with comments allowed, whatever the file's extension. */
    public function isJsonc(): bool
    {
        return $this === self::Typescript || $this === self::Knossos;
    }

    /**
     * Whether the kind makes its directory a manifest root.
     *
     * Each of these declares a package or a build, which is what puts build
     * output (`dist`, `coverage`) beside it. Entry-point configs (YAML, HTML,
     * agent and tool configs) name files to run, not a build root.
     */
    public function isManifestRoot(): bool
    {
        return match ($this) {
            self::Cargo, self::Composer, self::Node, self::Python, self::Requirements, self::Typescript => true,
            default => false,
        };
    }

    /**
     * Whether the kind infers a boundary around its directory.
     *
     * Every manifest root but a pip requirements file: that lists one
     * environment's dependencies rather than declaring a package, carries no
     * name, and often sits in a directory such as `docs/` that is no package
     * at all. Beside a pyproject.toml it would also rename that package's
     * boundary.
     */
    public function infersBoundary(): bool
    {
        return $this->isManifestRoot() && $this !== self::Requirements;
    }
}
