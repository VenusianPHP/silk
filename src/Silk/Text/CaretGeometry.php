<?php

namespace Silk\Text;

use IntlBreakIterator;
use Surface\Contracts\Fonts\ShapedRun;

/**
 * Where the caret goes: an x for every grapheme boundary of a shaped line, from its glyph clusters. Inside a
 * cluster of several graphemes (a ligature) the cluster's advance is split evenly. Left to right only.
 */
final class CaretGeometry
{
    /** @var array<int, float> x per grapheme boundary, keys ascending */
    private array $stops = [0 => 0.0];

    public function __construct(?ShapedRun $run = null)
    {
        if ($run !== null) {
            $this->stops = self::fromRun($run);
        }
    }

    /** Without a driver: each boundary's x is the measured width of the text before it. */
    public static function measured(string $text, callable $width): self
    {
        $geometry = new self;
        $geometry->stops = [];
        foreach (self::boundaries($text) as $b) {
            $geometry->stops[$b] = (float) $width(substr($text, 0, $b));
        }

        return $geometry;
    }

    /** @return array<int, float> */
    public function stops(): array
    {
        return $this->stops;
    }

    public function xAt(int $byte): float
    {
        if (isset($this->stops[$byte])) {
            return $this->stops[$byte];
        }
        $x = 0.0;
        foreach ($this->stops as $b => $stop) {
            if ($b > $byte) {
                break;
            }
            $x = $stop;
        }

        return $x;
    }

    /** The boundary nearest $x. */
    public function byteAt(float $x): int
    {
        $best = 0;
        $distance = INF;
        foreach ($this->stops as $b => $stop) {
            $d = abs($stop - $x);
            if ($d < $distance) {
                [$best, $distance] = [$b, $d];
            }
        }

        return $best;
    }

    /** @return array<int, float> */
    private static function fromRun(ShapedRun $run): array
    {
        $text = $run->text;
        $length = strlen($text);
        // each cluster's pen start and advance, in logical order
        $clusters = [];
        $pen = 0.0;
        foreach ($run->glyphs as $glyph) {
            $clusters[$glyph->cluster] ??= [$pen, 0.0];
            $clusters[$glyph->cluster][1] += $glyph->advance;
            $pen += $glyph->advance;
        }
        ksort($clusters);
        $starts = array_keys($clusters);
        $stops = [];
        $bounds = self::boundaries($text);
        foreach ($starts as $k => $start) {
            $end = $starts[$k + 1] ?? $length;
            [$x, $advance] = $clusters[$start];
            $inside = array_values(array_filter($bounds, fn (int $b) => $b >= $start && $b < $end));
            $n = max(1, count($inside));
            foreach ($inside as $i => $b) {
                $stops[$b] = $x + $advance * $i / $n;
            }
        }
        $stops[0] ??= 0.0;
        $stops[$length] = $pen;
        ksort($stops);

        return $stops;
    }

    /** @return list<int> grapheme boundaries, 0 and the end included */
    private static function boundaries(string $text): array
    {
        $it = IntlBreakIterator::createCharacterInstance();
        $it->setText($text);
        $out = [];
        for ($b = $it->first(); $b !== IntlBreakIterator::DONE; $b = $it->next()) {
            $out[] = $b;
        }

        return $out ?: [0];
    }
}
