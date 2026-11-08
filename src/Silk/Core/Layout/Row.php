<?php

namespace Silk\Core\Layout;

/** Children left to right; `row(name, spacing, padding)` as TKRow. */
class Row extends Linear
{
    protected function horizontal(): bool
    {
        return true;
    }
}
