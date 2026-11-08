<?php

namespace Silk\Painter;

use Silk\Contracts\Geometry\Rect;
use Surface\NutsAndBolts\Path;

/** A rounded rect as a Path: a straight side between four quarter circles, each a cubic Bézier (the usual 0.5523 handle). */
final class RoundedPath
{
    private const float HANDLE = 0.5522847498307936;

    /** @param array{float, float, float, float} $radii top left, top right, bottom right, bottom left, each held to half the shorter side */
    public static function of(Rect $r, array $radii): Path
    {
        [$tl, $tr, $br, $bl] = $radii;
        [$x0, $y0, $x1, $y1] = [$r->x, $r->y, $r->x + $r->w, $r->y + $r->h];
        $k = self::HANDLE;

        return (new Path())
            ->moveTo($x0 + $tl, $y0)
            ->lineTo($x1 - $tr, $y0)
            ->cubicTo($x1 - $tr + $k * $tr, $y0, $x1, $y0 + $tr - $k * $tr, $x1, $y0 + $tr)
            ->lineTo($x1, $y1 - $br)
            ->cubicTo($x1, $y1 - $br + $k * $br, $x1 - $br + $k * $br, $y1, $x1 - $br, $y1)
            ->lineTo($x0 + $bl, $y1)
            ->cubicTo($x0 + $bl - $k * $bl, $y1, $x0, $y1 - $bl + $k * $bl, $x0, $y1 - $bl)
            ->lineTo($x0, $y0 + $tl)
            ->cubicTo($x0, $y0 + $tl - $k * $tl, $x0 + $tl - $k * $tl, $y0, $x0 + $tl, $y0)
            ->close();
    }
}
