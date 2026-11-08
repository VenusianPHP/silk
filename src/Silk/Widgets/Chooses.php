<?php

namespace Silk\Widgets;

/** What a DropdownList reports a chosen row to. */
interface Chooses
{
    /** A user's choice of row $index of the shown list. */
    public function choose(int $index): void;
}
