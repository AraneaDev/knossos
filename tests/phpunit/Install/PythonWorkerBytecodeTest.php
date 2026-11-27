<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\PythonBytecode;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\Group;

/**
 * The installed Python worker ships checked-hash bytecode for every module
 * (G4-16, U2): each `.pyc` holds the hash of its source, the worker's imports
 * load it under the flags the core starts it with, and a source edited after
 * install is run from the source, never from the stale bytecode. Every install
 * path runs the one command `PythonBytecode` names.
 */
#[Group('python-scanner')]
final class PythonWorkerBytecodeTest extends KnossosTestCase
{
    /** Each module's bytecode file, its flags and whether its hash is its source's, as the interpreter reads them. */
    private const INSPECT = <<<'PY'
        import importlib.util, json, pathlib, sys
        root = pathlib.Path(sys.argv[1])
        found = {}
        for source in sorted(root.rglob("*.py")):
            if "__pycache__" in source.parts:
                continue
            cached = pathlib.Path(importlib.util.cache_from_source(str(source)))
            data = cached.read_bytes() if cached.exists() else b""
            flags = int.from_bytes(data[4:8], "little") if len(data) >= 16 else None
            matches = data[8:16] == importlib.util.source_hash(source.read_bytes()) if flags else False
            found[str(source.relative_to(root))] = [flags, matches]
        print(json.dumps(found))
        PY;

    /** Import every module of the worker's package, verbosely, as the core starts the worker. */
    private const IMPORT_ALL = <<<'PY'
        import importlib, pkgutil, sys
        sys.path.insert(0, sys.argv[1])
        import knossos_python
        for module in pkgutil.walk_packages(knossos_python.__path__, "knossos_python."):
            importlib.import_module(module.name)
        PY;

    public function testEveryModuleShipsCheckedHashBytecodeOfItsSource(): void
    {
        $bin = $this->compiledCopy();

        $found = json_decode($this->python(['-I', '-c', self::INSPECT, $bin])[0], true);

        assertTrue(is_array($found) && count($found) > 30);
        assertContains('knossos_python/foreign_writes.py', array_keys($found));
        foreach ($found as $module => [$flags, $matches]) {
            // Flag bits 0b01 (hash-based) and 0b10 (check the source): checked-hash.
            assertSame([$module, 3, true], [$module, $flags, $matches]);
        }
    }

    public function testTheWorkersImportsLoadTheBytecodeAndAStaleOneIsNeverRun(): void
    {
        $bin = $this->compiledCopy();
        $loaded = $this->loadedFromBytecode($bin);

        assertTrue(count($loaded) > 30);
        assertContains('knossos_python/collector.py', $loaded);
        assertContains('knossos_python/test_facts/configuration.py', $loaded);

        file_put_contents($bin . '/knossos_python/collector.py', "\n# edited after install\n", FILE_APPEND);
        $after = $this->loadedFromBytecode($bin);

        assertNotContains('knossos_python/collector.py', $after);
        assertSame(count($loaded) - 1, count($after));
    }

    public function testEveryInstallPathRunsTheOneCommand(): void
    {
        $command = implode(' ', PythonBytecode::COMMAND);
        foreach (['install.sh' => '"$PARTIAL/', 'tools/install' => '"$ROOT/', 'Dockerfile' => '/opt/knossos/'] as $file => $root) {
            assertStringContainsString($command . ' ' . $root . PythonBytecode::DIRECTORY, (string) file_get_contents(self::repositoryRoot() . '/' . $file), $file);
        }
        assertStringContainsString('PythonBytecode::compile($partial)', (string) file_get_contents(self::repositoryRoot() . '/src/Install/SelfUpdater.php'));
    }

    public function testARootWithoutTheWorkerCompilesNothing(): void
    {
        assertSame(false, PythonBytecode::compile(self::scratchDirectory('python-bytecode-empty')));
    }

    /** A copy of the worker's sources, without any bytecode, compiled as an install compiles it; the path of its `bin`. */
    private function compiledCopy(): string
    {
        $root = self::scratchPath('python-bytecode');
        $this->copyTree(self::repositoryRoot() . '/' . PythonBytecode::DIRECTORY, $root . '/' . PythonBytecode::DIRECTORY);
        $this->python(['-I', '-c', 'import pathlib, shutil, sys; [shutil.rmtree(p) for p in pathlib.Path(sys.argv[1]).rglob("__pycache__")]', $root]);
        assertTrue(PythonBytecode::compile($root));

        return $root . '/' . PythonBytecode::DIRECTORY;
    }

    /**
     * The modules, relative to `$bin`, whose code an import under the core's flags took from bytecode.
     *
     * @return list<string>
     */
    private function loadedFromBytecode(string $bin): array
    {
        [, $errors] = $this->python(['-I', '-B', '-v', '-c', self::IMPORT_ALL, $bin]);
        preg_match_all("~^# code object from '?" . preg_quote($bin, '~') . "/(.+?)/__pycache__/([^/.]+)\\.[^/]+\\.pyc'?$~m", $errors, $matches, PREG_SET_ORDER);
        $loaded = array_map(static fn(array $match): string => $match[1] . '/' . $match[2] . '.py', $matches);
        sort($loaded);

        return $loaded;
    }

    /**
     * Run `python3` with these arguments; its output and its error output.
     *
     * @param list<string> $arguments
     * @return array{string, string}
     */
    private function python(array $arguments): array
    {
        // Through files, not pipes: `-v` writes more to stderr than a pipe holds while stdout is read.
        $log = self::scratchDirectory('python-bytecode-log');
        $process = proc_open(['python3', ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log . '/out', 'w'], 2 => ['file', $log . '/err', 'w']], $pipes);
        assertTrue(is_resource($process));
        $status = proc_close($process);
        $errors = (string) file_get_contents($log . '/err');
        assertSame(0, $status, $errors);

        return [(string) file_get_contents($log . '/out'), $errors];
    }
}
