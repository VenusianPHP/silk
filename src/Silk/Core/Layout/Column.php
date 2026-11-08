<?php

namespace Silk\Core\Layout;

/** Children top to bottom; `column(name, spacing, padding)` as TKColumn. */
class Column extends Linear
{
    protected function horizontal(): bool
    {
        return false;
    }
}
