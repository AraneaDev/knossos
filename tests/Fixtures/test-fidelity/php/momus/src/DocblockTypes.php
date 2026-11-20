<?php

namespace App;

interface CollabA
{
}

interface CollabB
{
}

class DocblockTypes
{
    /**
     * @param ?Invoice $maybe
     * @return Invoice[][]
     * @throws \DomainException when the ledger is out of sync
     */
    public function nested($maybe)
    {
        return [];
    }

    /**
     * @return array<int, Invoice>
     */
    public function genericMap()
    {
        return [];
    }

    /**
     * @param CollabA&CollabB $both
     * @return list<string>
     */
    public function combined($both)
    {
        return [];
    }

    /**
     * @return non-empty-list<int>
     */
    public function nonEmpty()
    {
        return [1];
    }

    /**
     * @return int|string
     */
    public function either()
    {
        return 0;
    }

    /**
     * @return CollabA&CollabB
     */
    public function both()
    {
        return null;
    }

    /**
     * @return callable(): int
     */
    public function factory()
    {
        return fn () => 0;
    }
}
