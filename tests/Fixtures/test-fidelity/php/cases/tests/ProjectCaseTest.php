<?php
namespace Tests;
use Tests\Support\ProjectTestCase;
final class ProjectCaseTest extends ProjectTestCase
{
    private int $limit;
    protected function setUp(): void
    {
        $this->limit = 3;
    }
    public function testLosesItsConstant(): void
    {
        $this->assertSame(3, $this->limit);
    }
}
