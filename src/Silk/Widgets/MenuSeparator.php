<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;

/** A menu's separator, as Raven's (menu.c:76, 405-409, 476-480): 7 tall, a 1-unit line its style's margin from the menu's edges. */
class MenuSeparator extends Separator
{
    public const float HEIGHT = 7.0;

    protected function measureContent(Size $available): Size
    {
        return new Size(0, self::HEIGHT);
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $menu = $this->parent()?->bounds() ?? $b;
        $margin = (float) ($look->part('Menu', 'Body', State::Normal)->extra['separatorMargin'] ?? 8.0);
        $p->rect(new Rect($menu->x + $margin, $b->y + floor($b->h / 2), $menu->w - 2 * $margin, 1.0), $look->color(Role::Separator));
    }
}
