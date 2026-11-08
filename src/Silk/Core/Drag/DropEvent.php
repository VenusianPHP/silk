<?php

namespace Silk\Core\Drag;

use Silk\Contracts\Widget\Widget;

/** What a drop callback is told (RDropEvent, raven_window.h:286-301): the phase, the pointer in the host and in the target's layout, and the drag. */
final readonly class DropEvent
{
    public function __construct(
        public DropPhase $phase,
        public float $x,
        public float $y,
        public float $localX,
        public float $localY,
        /** Rdrag_begin's type */
        public string $type,
        /** its data */
        public string $data,
        public Widget $source,
    ) {}
}
