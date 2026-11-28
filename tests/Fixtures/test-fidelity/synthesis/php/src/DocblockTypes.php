<?php

// Momus test/integration/mcp.test.ts:699: docblock-only types render as mixed.
namespace App;

class DocblockTypes
{
    /**
     * @param ?Invoice $maybe
     * @return Invoice[][]
     */
    public function nested($maybe)
    {
        return [];
    }

    /**
     * @return int|string
     */
    public function either()
    {
        return 0;
    }
}
