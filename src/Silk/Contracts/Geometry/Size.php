<?php

namespace Silk\Contracts\Geometry;

/** A width and height in logical units. */
readonly class Size
{
    public function __construct(
        public float $w,
        public float $h,
    ) {}
}
