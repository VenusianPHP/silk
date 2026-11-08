<?php

namespace Silk\Painter;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Look\Look;
use Silk\Contracts\Look\Material;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Painter\Painter;
use Silk\Looks\GlassStyle;

/** Raven's rvn_paint_surface (widgets/common.c:178): a kind of surface painted as the look's glass, faded and shadowless when disabled. */
final class Surfaces
{
    public static function paint(Painter $p, Look $look, Rect $r, float $radius, SurfaceKind $kind, float $hover = 0.0, float $press = 0.0, bool $enabled = true, ?Material $material = null): void
    {
        $glass = GlassStyle::of($look, $kind, $hover, $press, $material);
        if (! $enabled) {
            $p->pushOpacity(0.5);
            $glass = new \Silk\Contracts\Painter\GlassLook($glass->tint, $glass->rim, $glass->frost, $glass->refraction, $glass->saturation, $glass->glow, $glass->bevel, 0.0, $glass->shadowColor);
        }
        $p->glass($r, $radius, $glass);
        if (! $enabled) {
            $p->popOpacity();
        }
    }
}
