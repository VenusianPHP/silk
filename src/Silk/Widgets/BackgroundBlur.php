<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Painter\GlassLook;
use Silk\Looks\GlassStyle;
use Silk\Contracts\Painter\BlurLook;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Container;
use Surface\NutsAndBolts\Color;

/**
 * Raven's BackgroundBlur (background_blur.c): one child over a blurred view of what is behind it, in one of four looks.
 * It has no TK twin; its API is Raven's.
 */
class BackgroundBlur extends Container
{
    use Widgets;

    private BlurStyle $style = BlurStyle::Frosted;

    /** Null: the theme's BlurRadius. */
    private ?float $radius = null;

    private ?Color $tint = null;

    /** Null: the style's. */
    private ?float $saturation = null;

    private ?float $grain = null;

    /** @var array{float, float, float, float}|null Null: the theme's CornerRadius on every corner. */
    private ?array $radii = null;

    /** @var array{float, float, float, float} left, top, right, bottom */
    private array $padding = [0.0, 0.0, 0.0, 0.0];

    private float $shadow = 0.0;

    public function setStyle(BlurStyle $style): static
    {
        $this->style = $style;

        return $this->invalidatePaint();
    }

    public function style(): BlurStyle
    {
        return $this->style;
    }

    /** The blur's radius in logical units; null: the theme's. */
    public function setRadius(?float $radius): static
    {
        $this->radius = $radius;

        return $this->invalidatePaint();
    }

    public function setTint(?Color $tint): static
    {
        $this->tint = $tint;

        return $this->invalidatePaint();
    }

    /** 1 leaves the colours, above 1 strengthens them; null: the style's. */
    public function setSaturation(?float $saturation): static
    {
        $this->saturation = $saturation;

        return $this->invalidatePaint();
    }

    /** 0..1; null: the style's. */
    public function setGrain(?float $grain): static
    {
        $this->grain = $grain;

        return $this->invalidatePaint();
    }

    public function setCornerRadius(float $topLeft, ?float $topRight = null, ?float $bottomRight = null, ?float $bottomLeft = null): static
    {
        $this->radii = [$topLeft, $topRight ?? $topLeft, $bottomRight ?? $topLeft, $bottomLeft ?? $topLeft];

        return $this->invalidatePaint();
    }

    public function setPadding(float $left, float $top, float $right, float $bottom): static
    {
        $this->padding = [$left, $top, $right, $bottom];

        return $this->invalidateLayout();
    }

    /** The shadow under the pane, its blur in logical units; 0 for none. */
    public function setShadow(float $blur): static
    {
        $this->shadow = max(0.0, $blur);

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
        $look = $this->look();
        $dark = $look->dark();
        $bounds = $this->bounds();
        $radius = $this->radius ?? $look->metric(Metric::BlurRadius);
        $radii = $this->radii ?? array_fill(0, 4, $look->metric(Metric::CornerRadius));
        $hairline = new Color(1, 1, 1, $dark ? 0.14 : 0.55);
        $shadowColor = $this->shadow > 0 ? $look->color(Role::Shadow) : null;
        // the blur of the OS only exists behind a window that is seen through
        $style = $this->style === BlurStyle::Native ? BlurStyle::Frosted : $this->style;

        if ($style === BlurStyle::Glass) {
            $p->glass($bounds, $radii, $this->glassLook($radius, $hairline, $shadowColor));

            return;
        }
        $p->blur($bounds, $radii, match ($style) {
            BlurStyle::Plain => new BlurLook($radius, $this->tint, $this->saturation ?? 1.0, $this->grain ?? 0.0, 0.0, null, 0.0, $this->shadow, $shadowColor),
            default => new BlurLook(
                $radius,
                $this->veil(0.52),
                $this->saturation ?? 1.35,
                $this->grain ?? 0.55,
                $dark ? 0.01 : 0.03,
                $hairline,
                1.0,
                $this->shadow,
                $shadowColor,
            ),
        });
    }

    /**
     * The glass of the Glass style (background_blur.c:84-98): a panel's glass in the look; in a solid look it is glass all the
     * same, tinted by the veil with a pale rim. Its frost follows the radius, 22 being all of it.
     */
    private function glassLook(float $radius, Color $hairline, ?Color $shadowColor): GlassLook
    {
        $look = $this->look();
        $glass = GlassStyle::of($look, SurfaceKind::Panel);
        $tint = $glass->tint;
        $rim = $glass->rim;
        if (! $look->material()->isGlass()) {
            $tint = $this->veil(0.4);
            $rim = new Color(1, 1, 1, $look->dark() ? 0.5 : 0.8);
        }

        return new GlassLook(
            $this->tint ?? $tint,
            $rim,
            max(0.0, min(1.0, $radius / 22.0)),
            $glass->refraction,
            $this->saturation ?? $glass->saturation,
            $glass->glow,
            $glass->bevel,
            $this->shadow,
            $shadowColor ?? $glass->shadowColor,
        );
    }

    /** The veil of the styles that have one: the widget's tint, else the panel colour at its share of alpha, never opaque (background_blur.c:56-64). */
    private function veil(float $strength): Color
    {
        if ($this->tint !== null) {
            return $this->tint;
        }
        $panel = $this->look()->color(Role::Panel);

        return new Color($panel->red, $panel->green, $panel->blue, min(1.0, $panel->alpha) * $strength);
    }
}
