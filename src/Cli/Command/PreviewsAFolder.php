<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\DirectoryArgument;

/**
 * What a command that takes a folder and previews unless told to write has in common: `--execute`, with the usual
 * `--db` and `--json`, and the folder argument itself.
 */
trait PreviewsAFolder
{
    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'json', 'execute'];
    }

    /**
     * The folder the command was given, checked to exist.
     *
     * @param list<string> $positionals
     * @param string|null $notAbsolute a sprintf template refusing a relative path, or null to accept one
     */
    private function folder(array $positionals, string $usage, ?string $notAbsolute = null): string
    {
        return DirectoryArgument::of($positionals, $usage, $notAbsolute);
    }
}
