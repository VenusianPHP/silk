<?php

namespace Silk\Contracts\Geometry;

/** An axis-aligned rectangle in logical units. */
readonly class Rect
{
    public function __construct(
        public float $x,
        public float $y,
        public float $w,
        public float $h,
    ) {}

    /** The rect shrunk by the insets; never negative in size. */
    public function inset(Insets $i): Rect
    {
        return new Rect(
            $this->x + $i->l,
            $this->y + $i->t,
            max(0.0, $this->w - $i->l - $i->r),
            max(0.0, $this->h - $i->t - $i->b),
        );
    }

    /** The overlap, or null when the rects share no area. */
    public function intersect(Rect $o): ?Rect
    {
        $x = max($this->x, $o->x);
        $y = max($this->y, $o->y);
        $w = min($this->x + $this->w, $o->x + $o->w) - $x;
        $h = min($this->y + $this->h, $o->y + $o->h) - $y;

        return $w > 0.0 && $h > 0.0 ? new Rect($x, $y, $w, $h) : null;
    }

    /** The smallest rect holding both. */
    public function union(Rect $o): Rect
    {
        $x = min($this->x, $o->x);
        $y = min($this->y, $o->y);

        return new Rect($x, $y, max($this->x + $this->w, $o->x + $o->w) - $x, max($this->y + $this->h, $o->y + $o->h) - $y);
    }

    /** Grown by $by on every side. */
    public function grow(float $by): Rect
    {
        return new Rect($this->x - $by, $this->y - $by, $this->w + 2 * $by, $this->h + 2 * $by);
    }

    public function moved(float $dx, float $dy): Rect
    {
        return new Rect($this->x + $dx, $this->y + $dy, $this->w, $this->h);
    }

    /** Half-open: the left and top edges are inside, the right and bottom edges are not. */
    public function contains(float $x, float $y): bool
    {
        return $x >= $this->x && $x < $this->x + $this->w
            && $y >= $this->y && $y < $this->y + $this->h;
    }
}
