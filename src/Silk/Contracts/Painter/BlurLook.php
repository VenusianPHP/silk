<?php

namespace Silk\Contracts\Painter;

use Surface\NutsAndBolts\Color;

/**
 * What Raven's blur primitive takes (rvn_blur_t, draw.h): the radius in logical units, the colours of the veil over it,
 * how strongly its colours show (saturation 1 leaves them), a grain 0..1, a light added over the whole, a hairline, and
 * the shadow under the pane (blur in logical units, 0 for none).
 */
final readonly class BlurLook
{
    public function __construct(
        public float $radius,
        public ?Color $tint = null,
        public float $saturation = 1.0,
        public float $grain = 0.0,
        public float $light = 0.0,
        public ?Color $border = null,
        public float $borderWidth = 0.0,
        public float $shadow = 0.0,
        public ?Color $shadowColor = null,
    ) {}
}
