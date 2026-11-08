<?php

namespace Silk\Contracts\Look;

/** A look's font: a fontconfig family, a size in logical units, a CSS weight 100..900, italic or not. */
final readonly class FontSpec
{
    public function __construct(
        public string $family = 'sans-serif',
        public float $size = 13.0,
        public int $weight = 400,
        public bool $italic = false,
    ) {}
}
