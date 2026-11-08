<?php

namespace Silk\Painter;

use Surface\Contracts\Drawing\RenderingEngine;
use Surface\NutsAndBolts\Color;

/**
 * Silk's primitives on the RenderingEngine UI ops (roundRect, shadow), in device pixels. Lines have
 * no engine op yet: they stay one capsule contour built here.
 */
final class EngineLowering
{
    /** Flatness in pixels, as RenderingEngine::FLATNESS. */
    private const float FLATNESS = 0.1;

    /** @param array{float, float, float, float} $radii [tl, tr, br, bl], already clamped */
    public static function rect(RenderingEngine $e, float $x, float $y, float $w, float $h, array $radii, Color $fill, ?Color $border = null, float $borderWidth = 0.0): void
    {
        if ($w > 0 && $h > 0) {
            $e->roundRect($x, $y, $w, $h, $radii, $fill, $border, $borderWidth);
        }
    }

    /** The shadow is set blur/4 down: Raven's convention, which this painter applies (Surface's shadow() moves a box only by the offset it is given). */
    public static function shadow(RenderingEngine $e, float $x, float $y, float $w, float $h, float $radius, float $blur, Color $color): void
    {
        if ($w > 0 && $h > 0) {
            $blur = max(0.0, $blur);
            $e->shadow($x, $y, $w, $h, $radius, $blur, $color, 0.0, $blur / 4);
        }
    }

    /** A line as one capsule contour: the segment with round caps. */
    public static function line(RenderingEngine $e, float $x1, float $y1, float $x2, float $y2, Color $color, float $thickness): void
    {
        $r = $thickness / 2;
        if ($r <= 0) {
            return;
        }
        $len = hypot($x2 - $x1, $y2 - $y1);
        // the normal's angle; a zero-length line is a dot
        $a = $len > 0 ? atan2($x2 - $x1, -($y2 - $y1)) : 0.0;
        $sides = 2 * self::sides($r, self::grow($e));
        $points = [];
        foreach ([[$x2, $y2, $a], [$x1, $y1, $a + M_PI]] as [$cx, $cy, $start]) {
            for ($i = 0; $i <= $sides; $i++) {
                $t = $start - M_PI * $i / $sides;
                $points[] = [$cx + $r * cos($t), $cy + $r * sin($t)];
            }
        }
        $e->fillPath([$points], $color);
    }

    /** @return array{float, float, float, float} */
    public static function clamp(array $radii, float $w, float $h): array
    {
        $limit = max(0.0, min($w, $h) / 2);

        return array_map(fn ($r) => min(max(0.0, (float) $r), $limit), array_values($radii));
    }

    private static function grow(RenderingEngine $e): float
    {
        return sqrt(abs($e->matrix()->determinant()));
    }

    private static function sides(float $r, float $s): int
    {
        return (int) max(1, min(256, ceil(M_PI / 2 * sqrt($r * $s / (2 * self::FLATNESS)))));
    }
}
