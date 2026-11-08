<?php

namespace Silk\Contracts\Geometry;

/** Left, top, right and bottom edge distances in logical units. */
readonly class Insets
{
    public function __construct(
        public float $l,
        public float $t,
        public float $r,
        public float $b,
    ) {}
}
