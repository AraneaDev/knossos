<?php

namespace Experiments\Mockery;

interface Notifier
{
    public function notify(string $message): string;
}
