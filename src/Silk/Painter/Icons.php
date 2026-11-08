<?php

namespace Silk\Painter;

use Silk\Contracts\Painter\Painter;
use Surface\NutsAndBolts\Color;

/** Raven's stroked icons (rvn_paint_icon, draw.c:771-800): two lines each, max(1.25, size x 0.14) wide. */
final class Icons
{
    public const string CHEVRON_RIGHT = 'chevron-right';

    public const string CHEVRON_LEFT = 'chevron-left';

    public const string CHEVRON_DOWN = 'chevron-down';

    public const string CHEVRON_UP = 'chevron-up';

    public const string CHECK = 'check';

    public const string CROSS = 'cross';

    public static function paint(Painter $p, string $icon, float $cx, float $cy, float $size, Color $color): void
    {
        [$h, $q, $t] = [$size * 0.5, $size * 0.25, max(1.25, $size * 0.14)];
        $lines = match ($icon) {
            self::CHEVRON_RIGHT => [[$cx - $q, $cy - $h, $cx + $q, $cy], [$cx + $q, $cy, $cx - $q, $cy + $h]],
            self::CHEVRON_LEFT => [[$cx + $q, $cy - $h, $cx - $q, $cy], [$cx - $q, $cy, $cx + $q, $cy + $h]],
            self::CHEVRON_DOWN => [[$cx - $h, $cy - $q, $cx, $cy + $q], [$cx, $cy + $q, $cx + $h, $cy - $q]],
            self::CHEVRON_UP => [[$cx - $h, $cy + $q, $cx, $cy - $q], [$cx, $cy - $q, $cx + $h, $cy + $q]],
            self::CHECK => [[$cx - $h, $cy, $cx - $h * 0.25, $cy + $h * 0.7], [$cx - $h * 0.25, $cy + $h * 0.7, $cx + $h, $cy - $h * 0.6]],
            self::CROSS => [[$cx - $h, $cy - $h, $cx + $h, $cy + $h], [$cx - $h, $cy + $h, $cx + $h, $cy - $h]],
        };
        foreach ($lines as [$x1, $y1, $x2, $y2]) {
            $p->line($x1, $y1, $x2, $y2, $color, $t);
        }
    }

    /** Raven rvn_paint_chevron (draw.c:802-812): (-q, -h) -> (q, 0) -> (-q, h), rotated by $angle. */
    public static function chevron(Painter $p, float $cx, float $cy, float $size, float $angle, Color $color): void
    {
        [$h, $q, $t] = [$size * 0.5, $size * 0.25, max(1.25, $size * 0.14)];
        [$c, $s] = [cos($angle), sin($angle)];
        [$ax, $ay] = [$cx - $q * $c + $h * $s, $cy - $q * $s - $h * $c];
        [$bx, $by] = [$cx + $q * $c, $cy + $q * $s];
        [$dx, $dy] = [$cx - $q * $c - $h * $s, $cy - $q * $s + $h * $c];
        $p->line($ax, $ay, $bx, $by, $color, $t);
        $p->line($bx, $by, $dx, $dy, $color, $t);
    }
}
