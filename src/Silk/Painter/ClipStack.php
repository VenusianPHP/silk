<?php

namespace Silk\Painter;

use LogicException;
use Silk\Contracts\Geometry\Rect;

/** Silk's own clip stack (spec D1): each push intersects with the current clip. */
class ClipStack
{
    /** @var list<Rect> */
    private array $stack = [];

    public function __construct(private readonly Rect $base) {}

    public function push(Rect $r): void
    {
        $c = $this->current();
        $this->stack[] = $c->intersect($r) ?? new Rect($c->x, $c->y, 0, 0);
    }

    public function pop(): void
    {
        if ($this->stack === []) {
            throw new LogicException('popClip() without a pushClip().');
        }
        array_pop($this->stack);
    }

    public function current(): Rect
    {
        return $this->stack === [] ? $this->base : $this->stack[array_key_last($this->stack)];
    }

    public function depth(): int
    {
        return count($this->stack);
    }

    public function isEmpty(): bool
    {
        $c = $this->current();

        return $c->w <= 0 || $c->h <= 0;
    }
}
