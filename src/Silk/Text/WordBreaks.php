<?php

namespace Silk\Text;

use IntlBreakIterator;

/** Word boundaries in UTF-8 byte offsets (UAX #29 through intl): where word moves stop. */
final class WordBreaks
{
    /** The end of the next word after $byte; the text's end when none. */
    public static function next(string $text, int $byte): int
    {
        foreach (self::segments($text) as [$start, $end, $word]) {
            if ($word && $end > $byte) {
                return $end;
            }
        }

        return strlen($text);
    }

    /** The start of the word before $byte; 0 when none. */
    public static function previous(string $text, int $byte): int
    {
        $found = 0;
        foreach (self::segments($text) as [$start, $end, $word]) {
            if ($start >= $byte) {
                break;
            }
            if ($word) {
                $found = $start;
            }
        }

        return $found;
    }

    /** @return array{int, int} the segment (word, spaces or punctuation) holding $byte */
    public static function around(string $text, int $byte): array
    {
        $last = [0, 0];
        foreach (self::segments($text) as [$start, $end]) {
            $last = [$start, $end];
            if ($byte < $end) {
                return $last;
            }
        }

        return $last;
    }

    /** @return list<array{int, int, bool}> start, end, whether it is a word */
    private static function segments(string $text): array
    {
        $it = IntlBreakIterator::createWordInstance();
        $it->setText($text);
        $out = [];
        $start = $it->first();
        for ($end = $it->next(); $end !== IntlBreakIterator::DONE; $start = $end, $end = $it->next()) {
            $out[] = [$start, $end, $it->getRuleStatus() !== IntlBreakIterator::WORD_NONE];
        }

        return $out;
    }
}
