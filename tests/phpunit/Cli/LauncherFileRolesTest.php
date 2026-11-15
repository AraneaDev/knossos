<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The container launcher knows every option and argument that names a file.
 *
 * In container mode the project is read-only, so the launcher on the host has
 * to place every file a command writes or reads (FILE_ROLES in
 * tools/launcher/knossos). That table is checked here against the usage block
 * of docs/reference/cli.md, which `generate-reference --check` keeps equal to
 * `knossos help` and CliDocumentedOptionsTest keeps equal to each command's
 * option allowlist. A new option or argument that takes a file, documented as
 * the reference documents every one (`=FILE`, `=PATH`, `=DIR`, `<file>`,
 * `[FILE...]`), fails here until the launcher has a line for it.
 */
final class LauncherFileRolesTest extends KnossosTestCase
{
    /** What a FILE_ROLES line may say an option or argument is. */
    private const ROLES = ['output', 'input', 'project', 'database', 'none', 'refused'];

    #[Group('cli')]
    public function testEveryFileTheCliTakesHasALauncherRole(): void
    {
        $roles = self::launcherRoles();
        $missing = [];
        foreach (self::documentedFiles() as $command => $keys) {
            foreach ($keys as $key) {
                if (self::roleFor($roles, $command, $key) === null) {
                    $missing[] = $command . ' ' . $key;
                }
            }
        }

        assertSame([], $missing, 'Options or arguments that name a file without a line in FILE_ROLES: ' . implode(', ', $missing));
    }

    #[Group('cli')]
    public function testEveryLauncherRoleNamesSomethingTheCliTakes(): void
    {
        $documented = self::documentedFiles();
        $stale = [];
        foreach (self::launcherRoles() as [$command, $key, $role]) {
            assertSame(true, in_array($role, self::ROLES, true), sprintf('%s %s has the unknown role %s.', $command, $key, $role));
            if ($command === '*') {
                continue;
            }
            if (!in_array($key, $documented[$command] ?? [], true)) {
                $stale[] = $command . ' ' . $key;
            }
        }

        assertSame([], $stale, 'FILE_ROLES lines the CLI reference does not document: ' . implode(', ', $stale));
        // The parser saw the block at all: these are known to name files.
        assertSame(true, in_array('--output', $documented['export-bundle'] ?? [], true));
        assertSame(true, in_array('@1', $documented['import-bundle'] ?? [], true));
        assertSame(true, in_array('@2...', $documented['review-diff'] ?? [], true));
    }

    /**
     * The (command, key) pairs of the reference's usage block that name a file:
     * `--name` for an option whose value is FILE, PATH, DIR, HOSTPATH or a
     * `.sqlite` name, `@N` for the Nth positional `<file>`, `@N...` for a
     * `[FILE...]` from the Nth on.
     *
     * @return array<string, list<string>>
     */
    private static function documentedFiles(): array
    {
        $reference = (string) file_get_contents(self::repositoryRoot() . '/docs/reference/cli.md');
        assertSame(1, preg_match('/```text\n(.*?)```/s', $reference, $block), 'The reference has a usage block.');
        $usages = [];
        $current = null;
        $note = false;
        foreach (explode("\n", $block[1]) as $line) {
            if (preg_match('/^  knossos ([a-z][a-z-]*)(.*)$/', $line, $start) === 1) {
                $current = $start[1];
                $usages[$current][] = $start[2];
                $note = false;
                continue;
            }
            if ($current === null || !str_starts_with($line, '   ')) {
                $current = null;
                continue;
            }
            // A parenthesised note under a usage line is prose, not syntax.
            $trimmed = trim($line);
            if ($note || str_starts_with($trimmed, '(')) {
                $note = !str_ends_with($trimmed, ')');
                continue;
            }
            $usages[$current][count($usages[$current]) - 1] .= ' ' . $trimmed;
        }

        $files = [];
        foreach ($usages as $command => $lines) {
            foreach ($lines as $usage) {
                foreach (self::filesOf($usage) as $key) {
                    $files[$command][] = $key;
                }
            }
            if (isset($files[$command])) {
                $files[$command] = array_values(array_unique($files[$command]));
            }
        }

        return $files;
    }

    /**
     * The keys of one usage line that name a file.
     *
     * @return list<string>
     */
    private static function filesOf(string $usage): array
    {
        $keys = [];
        preg_match_all('/--([a-z][a-z0-9-]*)=([^\]\s]+)/', $usage, $options, PREG_SET_ORDER);
        foreach ($options as [, $name, $placeholder]) {
            if (preg_match('/FILE|PATH|DIR|\.sqlite/', $placeholder) === 1) {
                $keys[] = '--' . $name;
            }
        }
        // Positionals: what is left once every option and the `--` separator are gone.
        $positionals = preg_split('/\s+/', trim((string) preg_replace('/\[--[^\]]*\](\.\.\.)?|\(?--[a-z][^\s\]]*\)?|\s--\s.*$/', ' ', $usage))) ?: [];
        $position = 0;
        foreach ($positionals as $token) {
            if ($token === '' || $token === '|') {
                continue;
            }
            ++$position;
            if (preg_match('/^\[FILE\.\.\.\]$/', $token) === 1) {
                $keys[] = '@' . $position . '...';
            } elseif (preg_match('/^[<\[]file[>\]]$/', $token) === 1) {
                $keys[] = '@' . $position;
            }
        }

        return $keys;
    }

    /**
     * FILE_ROLES as the launcher holds it: one [command, key, role] per line.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function launcherRoles(): array
    {
        $launcher = (string) file_get_contents(self::repositoryRoot() . '/tools/launcher/knossos');
        assertSame(1, preg_match("/^FILE_ROLES='\n(.*?)^'$/ms", $launcher, $table), 'The launcher has a FILE_ROLES table.');
        $roles = [];
        foreach (explode("\n", trim($table[1])) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            assertSame(3, count($fields), 'A FILE_ROLES line is `command key role`: ' . $line);
            $roles[] = [$fields[0], $fields[1], $fields[2]];
        }

        return $roles;
    }

    /**
     * The role the launcher gives a documented key, as its file_role decides.
     *
     * @param list<array{0: string, 1: string, 2: string}> $roles
     */
    private static function roleFor(array $roles, string $command, string $key): ?string
    {
        foreach ($roles as [$rowCommand, $rowKey, $role]) {
            if (($rowCommand === '*' || $rowCommand === $command) && $rowKey === $key) {
                return $role;
            }
        }

        return null;
    }
}
