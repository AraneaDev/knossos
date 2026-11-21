<?php

namespace Tests;

use App\MagicChild;
use App\Post;
use App\UsesMagic;
use Mockery;
use PHPUnit\Framework\TestCase;

final class MagicMockeryTest extends TestCase
{
    public function testMockeryOnInheritedMagic(): void
    {
        $post = Mockery::mock(Post::class);
        $post->shouldReceive('magicCount')->andReturn(3);
        $m = Mockery::mock(UsesMagic::class);
        $m->shouldReceive('anything')->andReturn(1);
        $c = Mockery::mock(MagicChild::class);
        $c->shouldReceive('anything')->andReturn(1);
        $this->assertSame(1, (new \App\Service($post, $m, $c))->run());
    }
}
