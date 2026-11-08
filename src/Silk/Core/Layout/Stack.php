<?php

namespace Silk\Core\Layout;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Align;
use Silk\Core\Tree\Container;

/**
 * Raven's Overlay (named Stack here, apart from the overlay layer): children on top of each other, later on
 * top. It wants the largest child's size; each child is placed in the whole panel by its slot's align,
 * on both axes (Fill stretches).
 */
class Stack extends Container
{
    protected function measureContent(Size $available): Size
    {
        $w = $h = 0.0;
        foreach ($this->visibleChildren() as $child) {
            $s = $child->measure($available);
            [$w, $h] = [max($w, $s->w), max($h, $s->h)];
        }

        return new Size($w, $h);
    }

    protected function arrangeContent(Rect $r): void
    {
        foreach ($this->visibleChildren() as $child) {
            $align = $this->slot($child)->align;
            if ($align === Align::Fill) {
                $child->arrange($r);

                continue;
            }
            $s = $child->measure(new Size($r->w, $r->h));
            [$w, $h] = [min($s->w, $r->w), min($s->h, $r->h)];
            $f = match ($align) {
                Align::Start => 0.0,
                Align::Center => 0.5,
                Align::End => 1.0,
            };
            $child->arrange(new Rect($r->x + ($r->w - $w) * $f, $r->y + ($r->h - $h) * $f, $w, $h));
        }
    }
}
