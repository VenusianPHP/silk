<?php

namespace Silk\Widgets;

use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\NutsAndBolts\Color;

/** One tile, as the app gives it when it is drawn (RTileItem, raven_controls.h:440-454). */
final readonly class TileItem
{
    public function __construct(
        public string $text = '',
        public ?string $detail = null,
        public ?string $badge = null,
        public ?Framebuffer $icon = null,
        /** shortcut: drawn square until Surface's image() takes a radius (blocked: Surface image radius) */
        public float $iconRadius = 0.0,
        /** null: the theme's text colour */
        public ?Color $textColor = null,
    ) {}
}
