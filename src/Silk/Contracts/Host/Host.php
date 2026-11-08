<?php

namespace Silk\Contracts\Host;

/** What a Silk tree lives in: a staged window or a TKCanvas. */
interface Host
{
    /** Input, springs, layout, paint and present, as needed (spec 5.3). */
    public function frame(): void;

    public function present(): void;
}
