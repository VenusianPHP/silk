<?php

namespace Silk\Painter;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Look\Look;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Painter\Painter;
use Surface\NutsAndBolts\Color;

/**
 * Raven's rvn_paint_knob (widgets/common.c:209-226): a white capsule with a soft shadow; picked up it grows (3 × press)
 * and turns into a drop of clear glass, the solid knob fading into it.
 */
final class Knob
{
    public static function paint(Painter $p, Look $look, Rect $r, float $press, bool $enabled = true): void
    {
        $radius = min($r->w, $r->h) * 0.5;
        if ($press > 0.02 && $enabled) {
            $grow = 3.0 * $press;
            Surfaces::paint($p, $look, new Rect($r->x - $grow * 1.4, $r->y - $grow, $r->w + $grow * 2.8, $r->h + $grow * 2.0), $radius + $grow, SurfaceKind::Lens, 0.0, 1.0);
            // the solid knob fades into it
            $p->pushOpacity(max(0.0, 1.0 - $press));
            $p->rect($r, new Color(1, 1, 1, 1), $radius);
            $p->popOpacity();

            return;
        }
        // Raven offsets this shadow by 1.5; the painter moves every shadow blur/4 down already
        $p->shadow($r, $radius, 5.0, new Color(0, 0, 0, 0.28), 0.0, 1.5 - 5.0 / 4);
        $p->rect($r, $enabled ? new Color(1, 1, 1, 1) : new Color(0.85, 0.85, 0.87, 1), $radius);
    }
}
