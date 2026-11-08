<?php

namespace Silk\Painter;

/** Raven's pixel rules: rect edges snap, strokes are at least one pixel. */
final class Snap
{
    public static function edge(float $v, float $scale): int
    {
        return (int) floor($v * $scale + 0.5);
    }

    /** max(1, round(w * scale)); zero stays zero (no stroke). */
    public static function stroke(float $w, float $scale): int
    {
        return $w <= 0.0 ? 0 : max(1, (int) round($w * $scale));
    }
}
