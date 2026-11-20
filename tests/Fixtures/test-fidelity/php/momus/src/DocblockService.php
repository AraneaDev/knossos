<?php

namespace App;

class DocblockService
{
    /**
     * Fetch an invoice by its id.
     *
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
     * Publish the invoice to the ledger.
     *
     * @throws \RuntimeException when the ledger is unavailable
     * @throws InvalidArgumentException
     */
    public function publish()
    {
    }
}
