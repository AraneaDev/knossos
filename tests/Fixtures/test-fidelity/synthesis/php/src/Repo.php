<?php

namespace App;

use App\FixturesEnum\Lane;

interface Repo extends Finder
{
    public function save(Invoice $invoice): void;

    public function count(): int;

    public function lane(Lane $lane): Lane;

    public function rates(): array|float|null;

    public function fail(): never;
}
