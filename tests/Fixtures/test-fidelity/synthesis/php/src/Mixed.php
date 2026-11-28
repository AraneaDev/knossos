<?php

namespace App;

class Mixed
{
    public function __construct(private string $dsn) {}

    public function open(): bool { return true; }

    protected function guarded(): void {}

    private function hidden(): void {}

    public static function make(): static { return new static(''); }

    public function self(): self { return $this; }
}
