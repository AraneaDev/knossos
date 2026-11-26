<?php

// Momus test/integration/mcp.test.ts:664: docblock types, which Knossos does not read.
namespace App;

class DocblockService
{
    /**
     * @param int $id
     * @return Invoice
     */
    public function findById($id)
    {
        return null;
    }

    /**
     * @return int[]
     */
    public function fetchIds()
    {
        return [];
    }

    /**
     * @param string|null $label
     * @return void
     */
    public function tag($label)
    {
    }

    /**
     * @throws \RuntimeException when the ledger is unavailable
     */
    public function publish()
    {
    }
}
