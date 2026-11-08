<?php

namespace Silk\Painter;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Painter\Painter;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\LinearGradient;

/** Raven's drawing helpers that the Painter does not have itself (widgets/canvas_look.c), ported as they are. */
final class RavenPaint
{
    /**
     * A rect shading from $from to $to, left to right or top to bottom (rvn_paint_gradient, canvas_look.c:155-200),
     * as one linear gradient over the rect, its corners rounded by $radii. Raven paints slices a unit wide, each in the
     * colour at its middle; the gradient takes the colour at each pixel's centre, so a pixel can differ by a level.
     *
     * @param  array{float, float, float, float}|null  $radii  top left, top right, bottom right, bottom left
     */
    public static function gradient(Painter $p, Rect $r, ?array $radii, Color $from, Color $to, bool $horizontal): void
    {
        if ($r->w <= 0.0 || $r->h <= 0.0) {
            return;
        }
        if (self::bytes($from) === self::bytes($to)) {
            $p->rect($r, $from, $radii ?? [0.0, 0.0, 0.0, 0.0]);

            return;
        }
        $gradient = $horizontal
            ? new LinearGradient($r->x, $r->y, $r->x + $r->w, $r->y, [[0.0, $from], [1.0, $to]])
            : new LinearGradient($r->x, $r->y, $r->x, $r->y + $r->h, [[0.0, $from], [1.0, $to]]);
        $p->gradient($r, $gradient, $radii ?? 0.0);
    }

    /**
     * A mark centred on ($cx, $cy), $half from its middle to its edge; hollow: only its outline, $ring wide, the
     * inside in $inside (rvn_paint_mark, canvas_look.c:230-243).
     */
    public static function mark(Painter $p, MarkShape $shape, float $cx, float $cy, float $half, Color $color, bool $hollow = false, float $ring = 0.0, ?Color $inside = null): void
    {
        if ($half <= 0.0) {
            return;
        }
        if ($shape === MarkShape::Ring) {
            $shape = MarkShape::Circle;
            $hollow = true;
        }
        if ($shape === MarkShape::None) {
            return;
        }
        self::markFill($p, $shape, $cx, $cy, $half, $color);
        if ($hollow && $inside !== null) {
            $inner = $half - max(1.0, min($half * 0.6, $ring));
            // (the arrow keeps its point: its hole is a little to the left)
            self::markFill($p, $shape, $shape === MarkShape::Arrow ? $cx - ($half - $inner) * 0.3 : $cx, $cy, $inner, $inside);
        }
    }

    /** The same mark, a little larger, as light around it (rvn_paint_mark_glow, canvas_look.c:245-252). */
    public static function markGlow(Painter $p, MarkShape $shape, float $cx, float $cy, float $half, Color $color, float $glow): void
    {
        if ($glow <= 0.01 || $half <= 0.0 || $shape === MarkShape::None) {
            return;
        }
        $spread = $half * 1.6 + 6.0 * $glow;
        $p->shadow(new Rect($cx - $half, $cy - $half, $half * 2.0, $half * 2.0), $half, $spread,
            new Color($color->red, $color->green, $color->blue, $color->alpha * max(0.0, min(0.9, 0.65 * $glow))));
    }

    /** The mark filled with one colour (canvas_look.c:207-228). */
    private static function markFill(Painter $p, MarkShape $shape, float $cx, float $cy, float $half, Color $color): void
    {
        switch ($shape) {
            case MarkShape::Square:
                $p->rect(new Rect($cx - $half, $cy - $half, $half * 2.0, $half * 2.0), $color, $half * 0.28);
                break;
            case MarkShape::Diamond:
                $d = $half * 1.25;
                $p->triangle($cx - $d, $cy, $cx, $cy - $d, $cx + $d, $cy, $color);
                $p->triangle($cx - $d, $cy, $cx, $cy + $d, $cx + $d, $cy, $color);
                break;
            case MarkShape::Triangle:
                $p->triangle($cx - $half * 0.9, $cy - $half * 1.1, $cx + $half * 1.15, $cy, $cx - $half * 0.9, $cy + $half * 1.1, $color);
                break;
            case MarkShape::Arrow:
                $body = $half * 0.35;
                $p->rect(new Rect($cx - $half, $cy - $half, $half + $body, $half * 2.0), $color, $half * 0.2);
                $p->triangle($cx + $body - 0.5, $cy - $half, $cx + $half * 1.25, $cy, $cx + $body - 0.5, $cy + $half, $color);
                break;
            default:
                $p->rect(new Rect($cx - $half, $cy - $half, $half * 2.0, $half * 2.0), $color, $half);
        }
    }

    /** Raven rvn_color_mix (base.h:120-128): per 8-bit channel, rounded. */
    public static function mix(Color $a, Color $b, float $t): Color
    {
        $t = max(0.0, min(1.0, $t));
        [$x, $y] = [self::bytes($a), self::bytes($b)];
        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $out[] = ((int) ($x[$i] + ($y[$i] - $x[$i]) * $t + 0.5)) & 0xFF;
        }

        return Color::rgba($out[0], $out[1], $out[2], $out[3] / 255);
    }

    /**
     * The ink for text on a fill: $text when it reads on it (WCAG contrast $min or more), else whichever of $text and
     * $alt reads better. For fills a look makes the accent (Raven's high-contrast looks do for the active tab) where
     * the text colour cannot be read; $under is what the fill lies on, for a see-through fill.
     */
    public static function inkOn(Color $text, Color $fill, Color $under, Color $alt, float $min = 3.0): Color
    {
        $base = self::over($fill, self::over($under, new Color(0.5, 0.5, 0.5, 1.0)));
        $ratio = fn (Color $c) => self::contrast(self::over($c, $base), $base);

        return $ratio($text) >= $min || $ratio($text) >= $ratio($alt) ? $text : $alt;
    }

    /** $top laid over $under, by its alpha: an opaque colour. */
    public static function over(Color $top, Color $under): Color
    {
        $a = $top->alpha;

        return new Color($top->red * $a + $under->red * (1 - $a), $top->green * $a + $under->green * (1 - $a), $top->blue * $a + $under->blue * (1 - $a), 1.0);
    }

    /** The WCAG contrast ratio of two opaque colours, 1 to 21. */
    public static function contrast(Color $a, Color $b): float
    {
        $lum = function (Color $c): float {
            $f = fn (float $v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

            return 0.2126 * $f($c->red) + 0.7152 * $f($c->green) + 0.0722 * $f($c->blue);
        };
        [$x, $y] = [$lum($a), $lum($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** @return array{int, int, int, int} the colour as Raven's 8-bit channels */
    private static function bytes(Color $c): array
    {
        return [(int) round($c->red * 255), (int) round($c->green * 255), (int) round($c->blue * 255), (int) round($c->alpha * 255)];
    }
}
