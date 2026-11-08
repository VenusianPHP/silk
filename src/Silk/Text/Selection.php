<?php

namespace Silk\Text;

/** A selection in byte offsets: the anchor stays put, the caret moves; empty when they meet. */
final readonly class Selection
{
    public function __construct(
        public int $anchor,
        public int $caret,
    ) {}

    public function start(): int
    {
        return min($this->anchor, $this->caret);
    }

    public function end(): int
    {
        return max($this->anchor, $this->caret);
    }

    public function isEmpty(): bool
    {
        return $this->anchor === $this->caret;
    }
}
