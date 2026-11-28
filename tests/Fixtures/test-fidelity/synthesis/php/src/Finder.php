<?php

namespace App;

interface Finder
{
    public function find(int $id): ?Invoice;
}
