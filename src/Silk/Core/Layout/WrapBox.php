<?php

namespace Silk\Core\Layout;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Core\Tree\Container;

/**
 * Raven's WrapBox: children flow left to right at their own size and go on a new line when the width is
 * used up. Its height depends on the width it is offered, which Silk's measure already has.
 */
class WrapBox extends Container
{
    public function __construct(string $name, private float $spacing = 0)
    {
        parent::__construct($name);
    }

    protected function measureContent(Size $available): Size
    {
        return $this->flow($available->w, null);
    }

    protected function arrangeContent(Rect $r): void
    {
        $this->flow($r->w, $r);
    }

    /** Walks the children as they flow in $width; with $place, gives them their bounds. */
    private function flow(float $width, ?Rect $place): Size
    {
        $x = $y = $line = $used = 0.0;
        foreach ($this->visibleChildren() as $child) {
            $s = $child->measure(new Size($width, INF));
            if ($x > 0 && $width > 0 && $x + $s->w > $width + 0.01) {
                [$x, $y, $line] = [0.0, $y + $line + $this->spacing, 0.0];
            }
            $place && $child->arrange(new Rect($place->x + $x, $place->y + $y, $s->w, $s->h));
            $x += $s->w;
            $used = max($used, $x);
            $x += $this->spacing;
            $line = max($line, $s->h);
        }

        return new Size($used, $y + $line);
    }
}
