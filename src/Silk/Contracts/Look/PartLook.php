<?php

namespace Silk\Contracts\Look;

use Surface\NutsAndBolts\Color;

/**
 * The resolved look of one widget part in one state. $shadow is the blur of a part shadow (Raven look->shadow), 0 for none.
 * $extra holds a style's other numbers by name (width, height, size, inset, thin, thick, ...), for the widget that reads them.
 */
readonly class PartLook
{
    public function __construct(
        public ?Color $background = null,
        public ?Color $border = null,
        public ?Color $text = null,
        public float $radius = 0.0,
        public float $borderWidth = 0.0,
        public float $shadow = 0.0,
        public ?Color $shadowColor = null,
        /** @var array<string, float|string> */
        public array $extra = [],
        /** An edge in a colour of its own (Raven border_top / border_bottom); null: the border's. */
        public ?Color $borderTop = null,
        public ?Color $borderBottom = null,
    ) {}
}
