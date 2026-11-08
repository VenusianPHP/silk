<?php

namespace Silk\Painter;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Painter\Painter;
use Surface\NutsAndBolts\Color;

/**
 * A part's box with Raven's per-edge outline (rvn_paint_part, look.c:363-405): when the top or bottom edge has a
 * colour of its own, the fill is drawn alone and the outline in three bands (top, middle, bottom), each the
 * whole outline clipped to its band. Clips snap as Raven's (draw.c:392), so the bands land on the same pixels.
 */
final class PartBox
{
    public static function paint(Painter $p, Rect $r, ?Color $fill, float $radius, ?Color $border, float $width, ?Color $top = null, ?Color $bottom = null): void
    {
        $edges = (self::shows($top) && $top != $border) || (self::shows($bottom) && $bottom != $border);
        if (! $edges) {
            // an outline alone paints too (look.c:376-378)
            if (self::shows($fill) || ($width > 0 && self::shows($border))) {
                $p->rect($r, $fill ?? new Color(0, 0, 0, 0), $radius, $border, $width);
            }

            return;
        }
        $topBand = self::shows($top) ? max($width, 1.0) + 0.5 : 0.0;
        $bottomBand = self::shows($bottom) ? $width + 0.5 : 0.0;
        if (self::shows($fill)) {
            $p->rect($r, $fill, $radius);
        }
        self::band($p, $r, $radius, $width, $top, new Rect($r->x - 1, $r->y - 1, $r->w + 2, $topBand + 1));
        self::band($p, $r, $radius, $width, $border, new Rect($r->x - 1, $r->y + $topBand, $r->w + 2, $r->h - $topBand - $bottomBand));
        self::band($p, $r, $radius, $width, self::shows($bottom) ? $bottom : $border, new Rect($r->x - 1, $r->y + $r->h - $bottomBand, $r->w + 2, $bottomBand + 1));
    }

    private static function band(Painter $p, Rect $r, float $radius, float $width, ?Color $color, Rect $band): void
    {
        if (! self::shows($color) || $width <= 0 || $band->h <= 0) {
            return;
        }
        $p->pushClip($band);
        $p->rect($r, new Color(0, 0, 0, 0), $radius, $color, $width);
        $p->popClip();
    }

    private static function shows(?Color $c): bool
    {
        return $c !== null && $c->alpha > 0;
    }
}
