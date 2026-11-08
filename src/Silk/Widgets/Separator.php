<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Widget;

/** `separator(name, horizontal)` as TKSeparator. */
class Separator extends Widget
{
    public function __construct(string $name, private readonly bool $horizontal = true)
    {
        parent::__construct($name);
    }

    public function isHorizontal(): bool
    {
        return $this->horizontal;
    }

    protected function measureContent(Size $available): Size
    {
        $t = $this->look()->metric(Metric::BorderWidth);

        return $this->horizontal ? new Size(0, $t) : new Size($t, 0);
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $t = $look->metric(Metric::BorderWidth);
        $c = $look->color(Role::Separator);
        $this->horizontal
            ? $p->line($b->x, $b->y + $b->h / 2, $b->x + $b->w, $b->y + $b->h / 2, $c, $t)
            : $p->line($b->x + $b->w / 2, $b->y, $b->x + $b->w / 2, $b->y + $b->h, $c, $t);
    }
}
