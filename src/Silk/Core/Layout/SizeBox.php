<?php

namespace Silk\Core\Layout;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Align;
use Silk\Core\Tree\Container;

/**
 * Raven's SizeBox: its first child with size limits (fixed width or height, minimum and maximum) and an
 * optional aspect ratio (width / height). With a ratio the child gets the largest such rect that fits,
 * placed by its slot's align (Fill centres).
 */
class SizeBox extends Container
{
    public function __construct(
        string $name,
        private float $aspectRatio = 0.0,
        private ?float $width = null,
        private ?float $height = null,
        private float $minWidth = 0.0,
        private float $minHeight = 0.0,
        private float $maxWidth = INF,
        private float $maxHeight = INF,
    ) {
        parent::__construct($name);
    }

    public function setAspectRatio(float $ratio): static
    {
        $this->aspectRatio = max(0.0, $ratio);

        return $this->invalidateLayout();
    }

    /** null for no fixed size on that axis. */
    public function setSize(?float $width, ?float $height): static
    {
        [$this->width, $this->height] = [$width, $height];

        return $this->invalidateLayout();
    }

    public function setLimits(float $minWidth = 0.0, float $minHeight = 0.0, float $maxWidth = INF, float $maxHeight = INF): static
    {
        [$this->minWidth, $this->minHeight, $this->maxWidth, $this->maxHeight] = [$minWidth, $minHeight, $maxWidth, $maxHeight];

        return $this->invalidateLayout();
    }

    protected function measureContent(Size $available): Size
    {
        $child = $this->visibleChildren()[0] ?? null;
        $s = $child?->measure($available) ?? new Size(0, 0);

        return new Size(
            max($this->minWidth, min($this->maxWidth, $this->width ?? $s->w)),
            max($this->minHeight, min($this->maxHeight, $this->height ?? $s->h)),
        );
    }

    protected function arrangeContent(Rect $r): void
    {
        $child = $this->visibleChildren()[0] ?? null;
        if ($child === null) {
            return;
        }
        if ($this->aspectRatio <= 0 || $r->w <= 0 || $r->h <= 0) {
            $child->arrange($r);

            return;
        }
        [$w, $h] = [$r->w, $r->w / $this->aspectRatio];
        if ($h > $r->h) {
            [$w, $h] = [$r->h * $this->aspectRatio, $r->h];
        }
        $f = match ($this->slot($child)->align) {
            Align::Start => 0.0,
            Align::End => 1.0,
            default => 0.5,
        };
        $child->arrange(new Rect($r->x + ($r->w - $w) * $f, $r->y + ($r->h - $h) * $f, $w, $h));
    }
}
