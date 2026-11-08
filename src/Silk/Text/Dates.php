<?php

namespace Silk\Text;

use DateTimeImmutable;
use Silk\Contracts\Text\Translator;

/** Raven's dates (date_picker.c:22-163): month and day names through the translator, and the date format. */
final class Dates
{
    private const array MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private const array DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** $month 1..12 (Raven.Month%d). */
    public static function monthName(int $month, Translator $t): string
    {
        return $t->text("Raven.Month{$month}", self::MONTHS[($month - 1) % 12]);
    }

    /** $weekday 0 Sunday..6, any int wraps (Raven.Day%d). */
    public static function dayName(int $weekday, Translator $t): string
    {
        $w = (($weekday % 7) + 7) % 7;

        return $t->text("Raven.Day{$w}", self::DAYS[$w]);
    }

    /** YYYY, MMMM (name), MMM (short name), MM, M, DD, D, dddd (day name), ddd; the rest as is (date_picker.c:117-163). */
    public static function format(string $format, DateTimeImmutable $d, Translator $t): string
    {
        [$y, $m, $day, $w] = [(int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j'), (int) $d->format('w')];
        $out = '';
        for ($i = 0, $n = strlen($format); $i < $n;) {
            $rest = substr($format, $i);
            [$piece, $len] = match (true) {
                str_starts_with($rest, 'YYYY') => [sprintf('%04d', $y), 4],
                str_starts_with($rest, 'MMMM') => [self::monthName($m, $t), 4],
                str_starts_with($rest, 'MMM') => [mb_substr(self::monthName($m, $t), 0, 3), 3],
                str_starts_with($rest, 'MM') => [sprintf('%02d', $m), 2],
                str_starts_with($rest, 'M') => [(string) $m, 1],
                str_starts_with($rest, 'dddd') => [self::dayName($w, $t), 4],
                str_starts_with($rest, 'ddd') => [mb_substr(self::dayName($w, $t), 0, 3), 3],
                str_starts_with($rest, 'DD') => [sprintf('%02d', $day), 2],
                str_starts_with($rest, 'D') => [(string) $day, 1],
                default => [$format[$i], 1],
            };
            $out .= $piece;
            $i += $len;
        }

        return $out;
    }
}
