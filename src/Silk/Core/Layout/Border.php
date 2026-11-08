<?php

namespace Silk\Core\Layout;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Core\Tree\Container;
use Silk\Looks\GlassStyle;
use Silk\Contracts\Look\Role;
use Surface\NutsAndBolts\Color;

/**
 * Raven's Border: its first child inside padding, over a background with an optional outline, rounded
 * corners and a drop shadow. Without a background it is only padding.
 */
class Border extends Container
{
    private Color|Role|null $background = null;

    private Color|Role|null $outline = null;

    private float $outlineWidth = 0.0;

    /** @var array{float, float, float, float}|null Null: square, or in glass 20. */
    private ?array $radii = null;

    private float $shadowBlur = 0.0;

    private Color|Role|null $shadowColor = null;

    /** @var array{float, float, float, float} left, top, right, bottom */
    private array $padding;

    public function __construct(string $name, float $padding = 0.0)
    {
        parent::__construct($name);
        $this->padding = [$padding, $padding, $padding, $padding];
    }

    public function setPadding(float $left, float $top, float $right, float $bottom): static
    {
        $this->padding = [$left, $top, $right, $bottom];

        return $this->invalidateLayout();
    }

    /** A Color, or a Role read from the look at each paint (it follows a theme switch). */
    public function setBackground(Color|Role|null $color): static
    {
        $this->background = $color;

        return $this->invalidatePaint();
    }

    public function setOutline(Color|Role|null $color, float $width = 1.0): static
    {
        [$this->outline, $this->outlineWidth] = [$color, $width];

        return $this->invalidatePaint();
    }

    public function setCornerRadius(float $topLeft, ?float $topRight = null, ?float $bottomRight = null, ?float $bottomLeft = null): static
    {
        $this->radii = [$topLeft, $topRight ?? $topLeft, $bottomRight ?? $topLeft, $bottomLeft ?? $topLeft];

        return $this->invalidatePaint();
    }

    public function setShadow(float $blur, Color|Role|null $color): static
    {
        [$this->shadowBlur, $this->shadowColor] = [$blur, $color];

        return $this->invalidatePaint();
    }

    protected function measureContent(Size $available): Size
    {
        [$l, $t, $r, $b] = $this->padding;
        $child = $this->visibleChildren()[0] ?? null;
        $s = $child?->measure(new Size(max(0.0, $available->w - $l - $r), max(0.0, $available->h - $t - $b))) ?? new Size(0, 0);

        return new Size($s->w + $l + $r, $s->h + $t + $b);
    }

    protected function arrangeContent(Rect $r): void
    {
        [$l, $t, $right, $b] = $this->padding;
        ($this->visibleChildren()[0] ?? null)?->arrange(new Rect($r->x + $l, $r->y + $t, max(0.0, $r->w - $l - $right), max(0.0, $r->h - $t - $b)));
    }

    protected function paintSelf(Painter $p): void
    {
        $bounds = $this->bounds();
        // (a border without a colour is only padding, border.c:90)
        if ($this->tree()?->look !== null && $this->look()->material()->isGlass() && ($this->background !== null || ($this->outline !== null && $this->outlineWidth > 0))) {
            // a panel of glass is rounded unless told otherwise (border.c:117-126)
            $p->glass($bounds, $this->radii ?? array_fill(0, 4, 20.0), GlassStyle::of($this->look(), SurfaceKind::Panel)->withShadow($this->shadowBlur));

            return;
        }
        $radii = $this->radii ?? [0.0, 0.0, 0.0, 0.0];
        if ($this->shadowBlur > 0 && $this->shadowColor !== null) {
            $p->shadow($bounds, max($radii), $this->shadowBlur, $this->resolve($this->shadowColor));
        }
        if ($this->background !== null) {
            $p->rect($bounds, $this->resolve($this->background), $radii, $this->resolve($this->outline), $this->outline === null ? 0.0 : $this->outlineWidth);
        }
    }

    private function resolve(Color|Role|null $c): ?Color
    {
        return $c instanceof Role ? $this->look()->color($c) : $c;
    }
}
