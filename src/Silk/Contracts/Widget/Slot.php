<?php

namespace Silk\Contracts\Widget;

/** Per-child placement in a container: main-axis grow share and cross-axis alignment. */
readonly class Slot
{
    public function __construct(
        public float $grow = 0.0,
        public Align $align = Align::Fill,
    ) {}
}
