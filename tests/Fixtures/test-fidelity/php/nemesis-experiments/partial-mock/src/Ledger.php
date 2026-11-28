<?php

namespace Experiments\PartialMock;

class Ledger
{
    public function post(int $cents): bool
    {
        return $cents > 0;
    }

    public function settle(): void
    {
    }

    public function balance(): int
    {
        return 0;
    }
}
