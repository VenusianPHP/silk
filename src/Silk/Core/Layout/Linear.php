<?php

namespace Silk\Core\Layout;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Align;
use Silk\Core\Tree\Container;

/** Column and Row: children stacked on one axis, aligned per slot on the other. */
abstract class Linear extends Container
{
    public function __construct(
        string $name,
        protected float $spacing = 0,
        protected float $padding = 0,
    ) {
        parent::__construct($name);
    }

    abstract protected function horizontal(): bool;

    /** Swaps w/h for rows so the maths below reads as a column. */
    private function flip(float $main, float $cross): array
    {
        return $this->horizontal() ? [$cross, $main] : [$main, $cross];
    }

    protected function measureContent(Size $available): Size
    {
        $inner = new Size(max(0.0, $available->w - 2 * $this->padding), max(0.0, $available->h - 2 * $this->padding));
        $main = $cross = 0.0;
        $kids = $this->visibleChildren();
        foreach ($kids as $child) {
            $s = $child->measure($inner);
            [$m, $c] = $this->horizontal() ? [$s->w, $s->h] : [$s->h, $s->w];
            $main += $m;
            $cross = max($cross, $c);
        }
        $main += $this->spacing * max(0, count($kids) - 1) + 2 * $this->padding;
        $cross += 2 * $this->padding;
        [$h, $w] = $this->flip($main, $cross);

        return new Size($w, $h);
    }

    protected function arrangeContent(Rect $r): void
    {
        $kids = $this->visibleChildren();
        $p = $this->padding;
        $inner = new Size(max(0.0, $r->w - 2 * $p), max(0.0, $r->h - 2 * $p));
        [$innerMain, $innerCross] = $this->horizontal() ? [$inner->w, $inner->h] : [$inner->h, $inner->w];

        $sizes = [];
        $used = $this->spacing * max(0, count($kids) - 1);
        $grow = 0.0;
        foreach ($kids as $i => $child) {
            $sizes[$i] = $child->measure($inner);
            $used += $this->horizontal() ? $sizes[$i]->w : $sizes[$i]->h;
            $grow += $this->slot($child)->grow;
        }
        $extra = max(0.0, $innerMain - $used);

        $at = $p;
        foreach ($kids as $i => $child) {
            $slot = $this->slot($child);
            [$m, $c] = $this->horizontal() ? [$sizes[$i]->w, $sizes[$i]->h] : [$sizes[$i]->h, $sizes[$i]->w];
            if ($grow > 0) {
                $m += $extra * $slot->grow / $grow;
            }
            $c = $slot->align === Align::Fill ? $innerCross : min($c, $innerCross);
            $off = $p + match ($slot->align) {
                Align::Start, Align::Fill => 0.0,
                Align::Center => ($innerCross - $c) / 2,
                Align::End => $innerCross - $c,
            };
            $child->arrange($this->horizontal()
                ? new Rect($r->x + $at, $r->y + $off, $m, $c)
                : new Rect($r->x + $off, $r->y + $at, $c, $m));
            $at += $m + $this->spacing;
        }
    }
}
