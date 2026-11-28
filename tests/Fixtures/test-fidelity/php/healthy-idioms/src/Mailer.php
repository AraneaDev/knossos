<?php

namespace App;

class Mailer
{
    public function send(string $to, string $subject, array $headers = []): bool
    {
        return true;
    }

    public function ping(): bool
    {
        return true;
    }

    public function self(): self
    {
        return $this;
    }

    public function done(): void
    {
    }
}
