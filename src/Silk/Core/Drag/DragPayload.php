<?php

namespace Silk\Core\Drag;

/** What Rdrag_begin carries: the chip's label, a type the targets check, and data (text) the drop hands over. */
final readonly class DragPayload
{
    public function __construct(
        public string $label,
        public string $kind,
        public string $data = '',
    ) {}
}
