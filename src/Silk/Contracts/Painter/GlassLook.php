<?php

namespace Silk\Contracts\Painter;

use Surface\NutsAndBolts\Color;

/**
 * What Raven's glass primitive takes (rvn_glass_t, draw.h): a thick, rounded slab over what is behind it. Lengths are
 * logical units. $frost 0..1 mixes the sharp backdrop with a wide blurred one; $refraction is how far the rim bends
 * what is behind it; $saturation 1 leaves the colours; $glow is added over the whole; $bevel is the rim's width (0: from the
 * size, as Raven); $tint is the colour of the glass; $rim the light on its edge (colour and strength).
 */
final readonly class GlassLook
{
    public function __construct(
        public ?Color $tint = null,
        public ?Color $rim = null,
        public float $frost = 1.0,
        public float $refraction = 10.0,
        public float $saturation = 1.5,
        public float $glow = 0.0,
        public float $bevel = 0.0,
        public float $shadow = 0.0,
        public ?Color $shadowColor = null,
    ) {}

    /** The same glass with a shadow of its own blur (Raven sets glass.shadow from the widget). */
    public function withShadow(float $blur): self
    {
        return new self($this->tint, $this->rim, $this->frost, $this->refraction, $this->saturation, $this->glow, $this->bevel, $blur, $this->shadowColor);
    }
}
