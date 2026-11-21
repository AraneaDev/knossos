<?php

namespace Tests\Support;

trait Records
{
    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}
