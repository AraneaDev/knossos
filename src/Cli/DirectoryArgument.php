<?php

declare(strict_types=1);

namespace Knossos\Cli;

use InvalidArgumentException;

/**
 * The folder a command that works on one is given: present, and an existing directory.
 */
final class DirectoryArgument
{
    /**
     * The first positional argument, checked to be an existing directory.
     *
     * @param list<string> $positionals
     * @param string $usage what to say when there is no argument
     * @param string|null $notAbsolute a sprintf template (one %s, the path) refusing a relative path, or null to accept one
     * @throws InvalidArgumentException when the argument is missing, relative where that is refused, or not a directory
     */
    public static function of(array $positionals, string $usage, ?string $notAbsolute = null): string
    {
        $path = $positionals[0] ?? throw new InvalidArgumentException($usage);
        if ($notAbsolute !== null && !str_starts_with($path, '/')) {
            throw new InvalidArgumentException(sprintf($notAbsolute, $path));
        }
        if (!is_dir($path)) {
            throw new InvalidArgumentException(sprintf('%s is not an existing directory.', $path));
        }

        return $path;
    }
}
