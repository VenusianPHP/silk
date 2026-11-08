<?php

namespace Silk\Widgets\Nodes;

use Silk\Looks\Canvas\CanvasShape;
use Surface\NutsAndBolts\Path;

/**
 * The way a wire goes (node_graph.c:642-872): one cubic curve (Bezier, Straight) or several in a row (Step:
 * straight runs and rounded corners; Angled). A piece is [x0, y0, c1x, c1y, c2x, c2y, x1, y1].
 */
final class Wire
{
    /**
     * The curve from an output at (x0, y0) to an input at (x1, y1), its middle pushed by the sway (rvn_ng_wire): it
     * leaves the pins straight before turning, more when it has to come back, and hangs a little, as a cable does.
     *
     * @return list<float>
     */
    public static function curve(float $x0, float $y0, float $x1, float $y1, float $swayX, float $swayY): array
    {
        [$dx, $dy] = [$x1 - $x0, $y1 - $y0];
        $distance = sqrt($dx * $dx + $dy * $dy);
        $pull = $dx >= 0 ? max(26.0, min(170.0, $dx * 0.5)) : max(46.0, min(240.0, 46.0 - $dx * 0.35 + abs($dy) * 0.12));
        $hang = min($distance * 0.07, 20.0);
        $pull = min($pull, max($distance * 0.9, 10.0));

        return [$x0, $y0, $x0 + $pull + $swayX, $y0 + $hang + $swayY, $x1 - $pull + $swayX, $y1 + $hang + $swayY, $x1, $y1];
    }

    /** The part of a piece from its start to t (de Casteljau; rvn_ng_curve_start). @param list<float> $p @return list<float> */
    public static function curveStart(array $p, float $t): array
    {
        $lerp = fn (float $a, float $b) => $a + ($b - $a) * $t;
        [$ax, $ay, $bx, $by, $cx, $cy] = [$lerp($p[0], $p[2]), $lerp($p[1], $p[3]), $lerp($p[2], $p[4]), $lerp($p[3], $p[5]), $lerp($p[4], $p[6]), $lerp($p[5], $p[7])];
        [$abx, $aby, $bcx, $bcy] = [$lerp($ax, $bx), $lerp($ay, $by), $lerp($bx, $cx), $lerp($by, $cy)];

        return [$p[0], $p[1], $ax, $ay, $abx, $aby, $abx + ($bcx - $abx) * $t, $aby + ($bcy - $aby) * $t];
    }

    /** @return list<float> a straight piece (rvn_ng_path_line) */
    private static function line(float $x0, float $y0, float $x1, float $y1): array
    {
        return [$x0, $y0, $x0 + ($x1 - $x0) / 3, $y0 + ($y1 - $y0) / 3, $x0 + ($x1 - $x0) * 2 / 3, $y0 + ($y1 - $y0) * 2 / 3, $x1, $y1];
    }

    /**
     * Through the points of a polyline, turning with rounded corners of $radius at most (rvn_ng_path_polyline).
     *
     * @param  list<array{float, float}>  $xy
     * @return list<list<float>>
     */
    private static function polyline(array $xy, float $radius): array
    {
        $out = [];
        [$px, $py] = $xy[0];
        for ($i = 1; $i < count($xy) - 1; $i++) {
            [[$cx, $cy], [$nx, $ny]] = [$xy[$i], $xy[$i + 1]];
            $in = sqrt(($cx - $px) ** 2 + ($cy - $py) ** 2);
            $outLen = sqrt(($nx - $cx) ** 2 + ($ny - $cy) ** 2);
            if ($in < 0.01) {
                continue;
            }
            $r = min($radius, $in * 0.5, $outLen * 0.5);
            [$ax, $ay] = [$cx - ($cx - $px) / $in * $r, $cy - ($cy - $py) / $in * $r];
            [$bx, $by] = $outLen > 0.01 ? [$cx + ($nx - $cx) / $outLen * $r, $cy + ($ny - $cy) / $outLen * $r] : [$cx, $cy];
            $out[] = self::line($px, $py, $ax, $ay);
            // a rounded corner around (cx, cy) (rvn_ng_path_corner)
            $r > 0.3 && $out[] = [$ax, $ay, $ax + ($cx - $ax) * 0.55, $ay + ($cy - $ay) * 0.55, $bx + ($cx - $bx) * 0.55, $by + ($cy - $by) * 0.55, $bx, $by];
            [$px, $py] = [$bx, $by];
        }
        $out[] = self::line($px, $py, ...$xy[count($xy) - 1]);

        return array_slice($out, 0, 10);
    }

    /**
     * The wire from an output to an input in a shape; Bezier (and anything else) hangs and swings (rvn_ng_path_build).
     *
     * @return list<list<float>>
     */
    public static function build(CanvasShape $shape, float $x0, float $y0, float $x1, float $y1, float $swayX = 0.0, float $swayY = 0.0): array
    {
        $out = 22.0;

        return match ($shape) {
            CanvasShape::Straight => [self::line($x0, $y0, $x1, $y1)],
            // out of the pins straight, then across
            CanvasShape::Angled => self::polyline([[$x0, $y0], [$x0 + ($lead = min($out, max(abs($x1 - $x0) * 0.25, 8.0))), $y0], [$x1 - $lead, $y1], [$x1, $y1]], 6.0),
            CanvasShape::Step => match (true) {
                abs($y1 - $y0) < 1.0 => [self::line($x0, $y0, $x1, $y1)],
                // forwards: across at the middle
                $x1 - $x0 >= $out * 2 => self::polyline([[$x0, $y0], [$m = floor(($x0 + $x1) * 0.5), $y0], [$m, $y1], [$x1, $y1]], 10.0),
                // backwards: out, down to between the two, back, and in
                default => self::polyline([[$x0, $y0], [$x0 + $out, $y0], [$x0 + $out, $b = ($y0 + $y1) * 0.5], [$x1 - $out, $b], [$x1 - $out, $y1], [$x1, $y1]], 10.0),
            },
            default => [self::curve($x0, $y0, $x1, $y1, $swayX, $swayY)],
        };
    }

    /**
     * The length of each piece (roughly: the mean of the chord and the control polygon) and of the whole
     * (rvn_ng_path_lengths).
     *
     * @param  list<list<float>>  $pieces
     * @return array{list<float>, float}
     */
    public static function lengths(array $pieces): array
    {
        $lengths = [];
        foreach ($pieces as $s) {
            $d = fn (int $a, int $b) => sqrt(($s[$b] - $s[$a]) ** 2 + ($s[$b + 1] - $s[$a + 1]) ** 2);
            $lengths[] = max(($d(0, 6) + $d(0, 2) + $d(2, 4) + $d(4, 6)) * 0.5, 0.001);
        }

        return [$lengths, array_sum($lengths)];
    }

    /**
     * The part of a wire from its start to t, 0..1 of its length (rvn_ng_path_start).
     *
     * @param  list<list<float>>  $pieces
     * @return list<list<float>>
     */
    public static function start(array $pieces, float $t): array
    {
        [$lengths, $total] = self::lengths($pieces);
        [$wanted, $at, $out] = [$total * max(0.0, min(1.0, $t)), 0.0, []];
        foreach ($pieces as $i => $piece) {
            if ($at + $lengths[$i] <= $wanted) {
                $out[] = $piece;
                $at += $lengths[$i];

                continue;
            }
            $out[] = self::curveStart($piece, ($wanted - $at) / $lengths[$i]);
            break;
        }

        return $out;
    }

    /** A point of a piece at t. @param list<float> $p @return array{float, float} */
    public static function point(array $p, float $t): array
    {
        $u = 1 - $t;
        [$a, $b, $c, $d] = [$u * $u * $u, 3 * $u * $u * $t, 3 * $u * $t * $t, $t * $t * $t];

        return [$a * $p[0] + $b * $p[2] + $c * $p[4] + $d * $p[6], $a * $p[1] + $b * $p[3] + $c * $p[5] + $d * $p[7]];
    }

    /**
     * How far a point is from a wire: each piece followed as 24 straight bits (rvn_ng_curve_distance).
     *
     * @param  list<list<float>>  $pieces
     */
    public static function distance(array $pieces, float $x, float $y): float
    {
        $best = INF;
        foreach ($pieces as $p) {
            [$fx, $fy] = [$p[0], $p[1]];
            for ($i = 1; $i <= 24; $i++) {
                [$tx, $ty] = self::point($p, $i / 24);
                [$bx, $by, $px, $py] = [$tx - $fx, $ty - $fy, $x - $fx, $y - $fy];
                $h = max(0.0, min(1.0, ($px * $bx + $py * $by) / max($bx * $bx + $by * $by, 1e-6)));
                $best = min($best, ($px - $bx * $h) ** 2 + ($py - $by * $h) ** 2);
                [$fx, $fy] = [$tx, $ty];
            }
        }

        return sqrt($best);
    }

    /** A piece as a Surface path. @param list<float> $p */
    public static function path(array $p): Path
    {
        return (new Path)->moveTo($p[0], $p[1])->cubicTo($p[2], $p[3], $p[4], $p[5], $p[6], $p[7]);
    }
}
