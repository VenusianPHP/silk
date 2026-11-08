<?php

namespace Silk\Widgets;

use Closure;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Widget;

/**
 * A hand-drawn view in a Silk tree (a PictureViewer-style canvas): $draw(RenderingEngine $engine, Rect
 * $deviceRect, float $scale) runs inside paint() with the engine clipped to the widget, in device pixels.
 * The engine's transform and clip are put back afterwards. invalidate() repaints it. It wants no size of its
 * own: give it room with a grow slot or a SizeBox.
 */
class Sketch extends Widget
{
    public function __construct(string $name, private Closure $draw)
    {
        parent::__construct($name);
    }

    public function setDraw(Closure $draw): static
    {
        $this->draw = $draw;

        return $this->invalidatePaint();
    }

    public function invalidate(): static
    {
        return $this->invalidatePaint();
    }

    protected function measureContent(Size $available): Size
    {
        return new Size(0, 0);
    }

    public function paint(Painter $p): void
    {
        $p->sketch($this->bounds(), $this->draw);
    }
}
