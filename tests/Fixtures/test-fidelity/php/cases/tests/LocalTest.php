<?php
namespace Tests;
use PHPUnit\Framework\TestCase;

function bump() { global $g; $g = 2; }

final class LocalTest extends TestCase
{
    public function testExtract(): void { $x = 1; extract(['x' => 2]); $this->assertSame(2, $x); }
    public function testForeachRef(): void { $x = 1; $a = [0]; foreach ($a as &$x) { $x = 2; } unset($x); $this->assertSame([2], $a); }
    public function testForeachRefScalar(): void { $v = 1; $a = [5]; foreach ($a as &$v) {} $this->assertSame(5, $v); }
    public function testVarVar(): void { $x = 1; $n = 'x'; $$n = 2; $this->assertSame(2, $x); }
    public function testGlobal(): void { global $g; $g = 1; bump(); $this->assertSame(2, $g); }
    public function testClosureRef(): void { $x = 1; $f = function () use (&$x) { $x = 2; }; $f(); $this->assertSame(2, $x); }
    public function testArrayPush(): void { $x = [1]; array_push($x, 2); $this->assertSame([1, 2], $x); }
    public function testSort(): void { $x = [2, 1]; sort($x); $this->assertSame([1, 2], $x); }
    public function testList(): void { $x = 1; list($x) = [2]; $this->assertSame(2, $x); }
    public function testShortList(): void { $x = 1; [$x] = [2]; $this->assertSame(2, $x); }
    public function testNestedList(): void { $x = 1; [[$x]] = [[2]]; $this->assertSame(2, $x); }
    public function testKeyedList(): void { $x = 1; ['k' => $x] = ['k' => 2]; $this->assertSame(2, $x); }
    public function testForeachList(): void { $x = 1; foreach ([[2]] as [$x]) {} $this->assertSame(2, $x); }
    public function testForeachNestedList(): void { $x = 1; foreach ([[[2]]] as [[$x]]) {} $this->assertSame(2, $x); }
    public function testListRef(): void { $x = 1; $a = [5]; [&$x] = $a; $a[0] = 2; $this->assertSame(2, $x); }
    public function testInclude(): void { $x = 1; include __DIR__ . '/inc.php'; $this->assertSame(2, $x); }
    public function testEval(): void { $x = 1; eval('$x = 2;'); $this->assertSame(2, $x); }
    public function testRef(): void { $x = 1; $r = &$x; $r = 2; $this->assertSame(2, $x); }
    public function testArrRef(): void { $x = 1; $a = [&$x]; $a[0] = 2; $this->assertSame(2, $x); }
    public function testPregMatch(): void { $m = 1; preg_match('/a/', 'a', $m); $this->assertSame(['a'], $m); }
    public function testWalk(): void { $x = [1]; array_walk($x, function (&$v) { $v = 2; }); $this->assertSame([2], $x); }
    public function testParseStr(): void { $x = 1; parse_str('x=2'); $this->assertSame('2', $x); }
    public function testStaticVar(): void { static $s = 1; $this->assertSame(1, $s); $s = 2; }
    public function testCatch(): void { $x = 1; try { throw new \RuntimeException('e'); } catch (\RuntimeException) { $x = 2; } $this->assertSame(2, $x); }
    public function testLocalConst(): void { $x = 1; $this->assertSame(1, $x); }
}
