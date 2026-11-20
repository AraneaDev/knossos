<?php
namespace Tests;
use PHPUnit\Framework\TestCase as Base;
use Tests\Support\PreHooks;
use Tests\Support\LateBefore;
use Tests\Support\PreTemplate;
use Tests\Support\DocBefore;
use Tests\Support\AssertHelper;

final class AliasPreConditionTest extends Base
{
    use PreHooks;
    private int $x;
    protected function setUp(): void { parent::setUp(); $this->x = 1; }
    public function testPre(): void { $this->assertSame(2, $this->x); }
}

final class AliasLateBeforeTest extends Base
{
    use LateBefore;
    private int $x;
    protected function setUp(): void { parent::setUp(); $this->x = 1; }
    public function testLate(): void { $this->assertSame(2, $this->x); }
}

final class FqPreTemplateTest extends \PHPUnit\Framework\TestCase
{
    use PreTemplate;
    private int $x;
    protected function setUp(): void { $this->x = 1; }
    public function testTemplate(): void { $this->assertSame(2, $this->x); }
}

final class DocBeforeTest extends Base
{
    use DocBefore;
    private int $x;
    protected function setUp(): void { $this->x = 1; }
    public function testDoc(): void { $this->assertSame(1, $this->x); }
}

final class AssertHelperTest extends Base
{
    use AssertHelper;
    private int $x;
    protected function setUp(): void { $this->x = 1; }
    public function testHelperThis(): void { $this->assertReady(); $this->assertSame(2, $this->x); }
    public function testHelperSelf(): void { self::assertReady(); $this->assertSame(2, $this->x); }
}

trait InlineHooks { #[\PHPUnit\Framework\Attributes\PreCondition] protected function prep(): void { $this->x = 2; } }

final class InlineTraitTest extends Base
{
    use InlineHooks;
    private int $x;
    protected function setUp(): void { $this->x = 1; }
    public function testInline(): void { $this->assertSame(2, $this->x); }
}

final class MagicTest extends Base
{
    private array $d = [];
    public function __set(string $n, mixed $v): void { $this->d[$n] = $v * 10; }
    public function __get(string $n): mixed { return $this->d[$n]; }
    protected function setUp(): void { $this->x = 1; }
    public function testMagic(): void { $this->assertSame(10, $this->x); }
}

final class ProviderTest extends Base
{
    private static int $n = 0;
    protected function setUp(): void { self::$n = 1; }
    public static function data(): array { self::$n = 5; return [[1]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('data')]
    public function testStatic(int $v): void { $this->assertSame(1, self::$n); }
}

final class ProviderOnlyTest extends Base
{
    private static int $n = 0;
    public static function data(): array { self::$n = 5; return [[1]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('data')]
    public function testStaticOnly(int $v): void { self::$n = 1; $this->assertSame(1, self::$n); }
}

final class ExplicitSetUpTest extends Base
{
    private int $x;
    protected function setUp(): void { $this->x = 1; }
    public function testExplicit(): void { $this->x = 5; $this->setUp(); $this->assertSame(1, $this->x); }
    public function testExplicitAfter(): void { $this->setUp(); $this->x = 5; $this->assertSame(5, $this->x); }
}
