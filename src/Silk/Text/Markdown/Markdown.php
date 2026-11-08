<?php

namespace Silk\Text\Markdown;

/**
 * Markdown read once into blocks and spans (Raven widgets/markdown.c:144-1034, rvn_md_parse).
 *
 * blocks: paragraphs, # titles 1 to 6 and setext titles, lists (-, *, +, 1.) nested, task lists, > quotes,
 *         fenced code with its language, rules, tables with the alignment of their columns, lone images.
 * inline: **strong**, *emphasis*, ***both***, `code`, ~~struck~~, [links](url), <url>, bare http(s)
 *         addresses, \escapes, two spaces or a backslash at a line end for a break, the usual &entities;.
 *
 * Spans are [start, length, flags, link] into $text, every character shown with escapes resolved.
 */
final class Markdown
{
    public const int BOLD = 1;

    public const int ITALIC = 2;

    public const int CODE = 4;

    public const int STRIKE = 8;

    /** The line ends after this span. */
    public const int BREAK = 16;

    public const int PARAGRAPH = 0;

    public const int HEADING = 1;

    public const int CODE_BLOCK = 2;

    public const int RULE = 3;

    public const int TABLE = 4;

    public const int IMAGE = 5;

    public const int NO_MARKER = 0;

    public const int BULLET = 1;

    public const int NUMBER = 2;

    public const int TASK = 3;

    public const int TASK_DONE = 4;

    public const int MAX_COLUMNS = 32;

    private const array ENTITIES = [
        'amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'", 'nbsp' => "\u{A0}", 'copy' => "\u{A9}",
        'reg' => "\u{AE}", 'hellip' => "\u{2026}", 'mdash' => "\u{2014}", 'ndash' => "\u{2013}", 'rarr' => "\u{2192}",
        'larr' => "\u{2190}", 'times' => "\u{D7}",
    ];

    /** Every character shown; offset 0 is a space no span starts at (markdown.c:803). */
    public string $text = ' ';

    /** @var list<array{int, int, int, int}> [start, length, flags, link] */
    public array $spans = [];

    /**
     * @var list<array{type: int, level: int, quote: int, list: int, marker: int, number: int, first: int, count: int,
     *   codeStart: int, codeLength: int, language: string, firstCell: int, columns: int, rows: int, link: int}>
     */
    public array $blocks = [];

    /** @var list<array{int, int, int}> [first span, span count, align 0 left 1 centre 2 right] */
    public array $cells = [];

    /** @var list<string> */
    public array $links = [];

    // inline state
    private int $flags = 0;

    private int $link = -1;

    private int $spanStart = 0;

    private bool $fresh = true;

    // block state
    private string $paragraph = '';

    private bool $paragraphOpen = false;

    private int $quote = 0;

    private int $list = 0;

    private int $marker = self::NO_MARKER;

    private int $number = 0;

    private int $heading = 0;

    /** @var list<int> the indentation of the markers of the open lists */
    private array $indents = [];

    public static function parse(string $source): self
    {
        $md = new self;
        $md->read(self::dedent($source) ?? $source);

        return $md;
    }

    /** The text without its marks, blocks on their own lines, cells by tabs (rvn_md_plain_text, markdown.c:1758-1789). */
    public function plainText(): string
    {
        $out = [];
        foreach ($this->blocks as $block) {
            if ($block['type'] === self::CODE_BLOCK) {
                $out[] = substr($this->text, $block['codeStart'], $block['codeLength']);
            } elseif ($block['type'] === self::TABLE) {
                $table = '';
                for ($c = 0; $c < $block['columns'] * $block['rows']; $c++) {
                    [$first, $count] = $this->cells[$block['firstCell'] + $c];
                    $table .= ($c > 0 ? ($c % $block['columns'] ? "\t" : "\n") : '').$this->spansText($first, $count, false);
                }
                $out[] = $table;
            } else {
                $out[] = $this->spansText($block['first'], $block['count'], true);
            }
        }

        return implode("\n", $out);
    }

    private function spansText(int $first, int $count, bool $breaks): string
    {
        $text = '';
        for ($s = $first; $s < $first + $count; $s++) {
            [$start, $length, $flags] = $this->spans[$s];
            $text .= substr($this->text, $start, $length).($breaks && ($flags & self::BREAK) ? "\n" : '');
        }

        return $text;
    }

    // ------------------------------------------------------------- inline

    private function addLink(string $url): int
    {
        if (count($this->links) >= 32000) {
            return -1;
        }
        $this->links[] = $url;

        return count($this->links) - 1;
    }

    /** Closes the span being filled, if it holds anything. */
    private function flush(int $extra = 0): void
    {
        $end = strlen($this->text);
        if ($end > $this->spanStart || ($extra & self::BREAK)) {
            $this->spans[] = [$this->spanStart, $end - $this->spanStart, $this->flags | $extra, $this->link];
        }
        $this->spanStart = $end;
    }

    private function put(string $bytes): void
    {
        $this->text .= $bytes;
        if ($bytes !== '') {
            $this->fresh = false;
        }
    }

    private static function isSpace(string $c): bool
    {
        return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r";
    }

    private static function isPunct(string $c): bool
    {
        return ($c >= '!' && $c <= '/') || ($c >= ':' && $c <= '@') || ($c >= '[' && $c <= '`') || ($c >= '{' && $c <= '~');
    }

    private static function isWord(string $c): bool
    {
        return ctype_alnum($c) || (ord($c) & 0x80);
    }

    /** Is there, in [$p, $end), a run of $count marks that could close what one opens before $p? (markdown.c:206-238) */
    private static function hasCloser(string $s, int $p, int $end, string $mark, int $count): bool
    {
        $from = $p;
        while ($p < $end) {
            if ($s[$p] === '\\' && $p + 1 < $end) {
                $p += 2;

                continue;
            }
            if ($s[$p] === '`') {
                // marks inside code do not count
                $ticks = self::run($s, $p, $end, '`');
                $close = self::codeClose($s, $p + $ticks, $end, $ticks);
                $p = $close < $end ? $close + $ticks : $p + $ticks;

                continue;
            }
            if ($s[$p] === $mark) {
                $run = self::run($s, $p, $end, $mark);
                if ($run >= $count && $p > $from && ! self::isSpace($s[$p - 1])) {
                    return true;
                }
                $p += $run;

                continue;
            }
            $p++;
        }

        return false;
    }

    private static function run(string $s, int $p, int $end, string $c): int
    {
        $n = 0;
        while ($p + $n < $end && $s[$p + $n] === $c) {
            $n++;
        }

        return $n;
    }

    /** Where a run of exactly $ticks backquotes closes code from $at, or $end. */
    private static function codeClose(string $s, int $at, int $end, int $ticks): int
    {
        while ($at < $end) {
            $run = self::run($s, $at, $end, '`');
            if ($run === $ticks) {
                break;
            }
            $at += $run ?: 1;
        }

        return $at;
    }

    /** An entity at $p ("&amp;"): writes what it stands for and returns its length, or 0 (markdown.c:246-280). */
    private function entity(string $s, int $p, int $end): int
    {
        $semi = $p + 1;
        while ($semi < $end && $semi - $p < 10 && $s[$semi] !== ';' && ! self::isSpace($s[$semi])) {
            $semi++;
        }
        if ($semi >= $end || $s[$semi] !== ';') {
            return 0;
        }
        $name = substr($s, $p + 1, $semi - $p - 1);
        if (strlen($name) >= 2 && $name[0] === '#') {
            $hex = $name[1] === 'x' || $name[1] === 'X';
            $digits = substr($name, $hex ? 2 : 1);
            if ($digits === '' || ! ($hex ? ctype_xdigit($digits) : ctype_digit($digits))) {
                return 0;
            }
            $code = $hex ? hexdec($digits) : (int) $digits;
            if ($code === 0 || $code > 0x10FFFF || strlen($digits) > 8) {
                return 0;
            }
            $this->put(mb_chr((int) $code, 'UTF-8') ?: "\u{FFFD}");

            return $semi - $p + 1;
        }
        if (isset(self::ENTITIES[$name])) {
            $this->put(self::ENTITIES[$name]);

            return strlen($name) + 2;
        }

        return 0;
    }

    /** The end of an address from $p, without the punctuation that ends a sentence (markdown.c:283-289). */
    private static function urlEnd(string $s, int $p, int $end): int
    {
        $stop = $p;
        while ($stop < $end && ! self::isSpace($s[$stop]) && $s[$stop] !== '<' && $s[$stop] !== '>' && $s[$stop] !== '"') {
            $stop++;
        }
        while ($stop > $p && str_contains(".,;:!?)'*_", $s[$stop - 1])) {
            $stop--;
        }

        return $stop;
    }

    /**
     * "[text](url)" at $p (on the '['): [after, textEnd, url], or null (markdown.c:298-341).
     *
     * @return array{int, int, string}|null
     */
    private static function linkParts(string $s, int $p, int $end): ?array
    {
        $close = $p + 1;
        $nest = 1;
        while ($close < $end) {
            if ($s[$close] === '\\' && $close + 1 < $end) {
                $close += 2;

                continue;
            }
            if ($s[$close] === '[') {
                $nest++;
            } elseif ($s[$close] === ']' && --$nest === 0) {
                break;
            }
            $close++;
        }
        if ($close >= $end || $close + 1 >= $end || $s[$close + 1] !== '(') {
            return null;
        }
        $q = $close + 2;
        while ($q < $end && ($s[$q] === ' ' || $s[$q] === "\n")) {
            $q++;
        }
        $url = $q;
        if ($q < $end && $s[$q] === '<') {
            $url = ++$q;
            while ($q < $end && $s[$q] !== '>' && $s[$q] !== "\n") {
                $q++;
            }
            if ($q >= $end || $s[$q] !== '>') {
                return null;
            }
            $urlLength = $q - $url;
            $q++;
        } else {
            $parens = 0;
            while ($q < $end && ! self::isSpace($s[$q])) {
                if ($s[$q] === '(') {
                    $parens++;
                } elseif ($s[$q] === ')' && $parens-- === 0) {
                    break;
                }
                $q++;
            }
            $urlLength = $q - $url;
        }
        // an optional title, ignored: ("title")
        while ($q < $end && ($s[$q] === ' ' || $s[$q] === "\n")) {
            $q++;
        }
        if ($q < $end && ($s[$q] === '"' || $s[$q] === "'")) {
            $quote = $s[$q++];
            while ($q < $end && $s[$q] !== $quote) {
                $q++;
            }
            if ($q < $end) {
                $q++;
            }
            while ($q < $end && ($s[$q] === ' ' || $s[$q] === "\n")) {
                $q++;
            }
        }
        if ($q >= $end || $s[$q] !== ')') {
            return null;
        }

        return [$q + 1, $close, substr($s, $url, $urlLength)];
    }

    /** Reads inline text into spans, with the looks on when it starts (rvn_md_inline, markdown.c:344-508). */
    private function inline(string $s, int $p, int $end, int $depth): void
    {
        $start = $p;
        $spacePending = false;
        while ($p < $end) {
            $c = $s[$p];
            $previous = $p > $start ? $s[$p - 1] : ' ';

            // white space, new lines included, is one space; two spaces before a line end are a line break
            if (self::isSpace($c)) {
                $q = $p;
                $newline = false;
                $spaces = 0;
                while ($q < $end && self::isSpace($s[$q])) {
                    if ($s[$q] === "\n") {
                        if (! $newline && $spaces >= 2) {
                            $this->flush(self::BREAK);
                            $this->fresh = true;
                        }
                        $newline = true;
                    } elseif (! $newline) {
                        $spaces++;
                    }
                    $q++;
                }
                $spacePending = ! $this->fresh;
                $p = $q;

                continue;
            }
            if ($spacePending) {
                $this->put(' ');
                $spacePending = false;
            }

            if ($c === '\\' && $p + 1 < $end) {
                if ($s[$p + 1] === "\n") {
                    $this->flush(self::BREAK);
                    $this->fresh = true;
                    $p += 2;

                    continue;
                }
                if (self::isPunct($s[$p + 1])) {
                    $this->put($s[$p + 1]);
                    $p += 2;

                    continue;
                }
            }

            if ($c === '`') {
                $ticks = self::run($s, $p, $end, '`');
                $content = $p + $ticks;
                $close = self::codeClose($s, $content, $end, $ticks);
                if ($close < $end) {
                    $length = $close - $content;
                    // one space of padding on each side is not part of the code
                    if ($length >= 2 && $s[$content] === ' ' && $s[$content + $length - 1] === ' ') {
                        $content++;
                        $length -= 2;
                    }
                    $this->flush();
                    $this->flags |= self::CODE;
                    $this->put(str_replace("\n", ' ', substr($s, $content, $length)));
                    $this->flush();
                    $this->flags &= ~self::CODE;
                    $p = $close + $ticks;

                    continue;
                }
            }

            if ($c === '*' || $c === '_' || $c === '~') {
                $run = self::run($s, $p, $end, $c);
                $used = 0;
                $next = $p + $run < $end ? $s[$p + $run] : ' ';
                while ($used < $run) {
                    $left = $run - $used;
                    $count = $c === '~' ? 2 : ($left >= 2 ? 2 : 1);
                    $flag = $c === '~' ? self::STRIKE : ($count === 2 ? self::BOLD : self::ITALIC);
                    $insideWord = $c === '_' && self::isWord($previous) && self::isWord($next);
                    if ($left < $count) {
                        break;
                    }
                    if ($this->flags & $flag) {
                        // closes: what is before must be text
                        if (self::isSpace($previous) || $insideWord) {
                            break;
                        }
                        $this->flush();
                        $this->flags &= ~$flag;
                    } else {
                        if (self::isSpace($next) || $insideWord || ! self::hasCloser($s, $p + $run, $end, $c, $count)) {
                            break;
                        }
                        $this->flush();
                        $this->flags |= $flag;
                    }
                    $used += $count;
                }
                // marks that neither open nor close anything are text
                if ($used < $run) {
                    $this->put(str_repeat($c, $run - $used));
                }
                $p += $run;

                continue;
            }

            if (($c === '[' || ($c === '!' && $p + 1 < $end && $s[$p + 1] === '[')) && $depth < 4) {
                $open = $c === '!' ? $p + 1 : $p;
                $parts = self::linkParts($s, $open, $end);
                if ($parts !== null) {
                    [$after, $textEnd, $url] = $parts;
                    $outer = $this->link;
                    $this->flush();
                    $this->link = $this->addLink($url);
                    // (an image inside a line of text is shown by its alternative text)
                    $this->inline($s, $open + 1, $textEnd, $depth + 1);
                    $this->flush();
                    $this->link = $outer;
                    $p = $after;

                    continue;
                }
            }

            if ($c === '<') {
                $close = $p + 1;
                while ($close < $end && $s[$close] !== '>' && ! self::isSpace($s[$close])) {
                    $close++;
                }
                $inside = substr($s, $p + 1, max(0, $close - $p - 1));
                if ($close < $end && $s[$close] === '>' && $close - $p > 4 && (str_contains($inside, ':') || str_contains($inside, '@'))) {
                    $outer = $this->link;
                    $this->flush();
                    $this->link = $this->addLink($inside);
                    $this->put($inside);
                    $this->flush();
                    $this->link = $outer;
                    $p = $close + 1;

                    continue;
                }
            }

            if ($c === 'h' && $this->link < 0 && ! self::isWord($previous) && $end - $p > 8
                && (substr_compare($s, 'http://', $p, 7) === 0 || substr_compare($s, 'https://', $p, 8) === 0)) {
                $stop = self::urlEnd($s, $p, $end);
                if ($stop - $p > 8) {
                    $this->flush();
                    $this->link = $this->addLink(substr($s, $p, $stop - $p));
                    $this->put(substr($s, $p, $stop - $p));
                    $this->flush();
                    $this->link = -1;
                    $p = $stop;

                    continue;
                }
            }

            if ($c === '&') {
                $used = $this->entity($s, $p, $end);
                if ($used) {
                    $p += $used;

                    continue;
                }
            }

            $this->put($c);
            $p++;
        }
    }

    /** @return array{int, int} the first span of the inline text, and how many */
    private function readInline(string $s): array
    {
        $this->flags = 0;
        $this->link = -1;
        $this->fresh = true;
        $this->spanStart = strlen($this->text);
        $first = count($this->spans);
        $this->inline($s, 0, strlen($s), 0);
        $this->flush();

        return [$first, count($this->spans) - $first];
    }

    // ------------------------------------------------------------- blocks

    private function addBlock(int $type): int
    {
        $this->blocks[] = [
            'type' => $type, 'level' => 0, 'quote' => 0, 'list' => 0, 'marker' => self::NO_MARKER, 'number' => 0,
            'first' => 0, 'count' => 0, 'codeStart' => 0, 'codeLength' => 0, 'language' => '', 'firstCell' => 0,
            'columns' => 0, 'rows' => 0, 'link' => -1,
        ];

        return count($this->blocks) - 1;
    }

    private function endParagraph(): void
    {
        if (! $this->paragraphOpen) {
            return;
        }
        $this->paragraphOpen = false;
        $image = $this->heading ? null : self::loneImage($this->paragraph);
        if ($image !== null) {
            $link = $this->addLink($image[1]);
            $b = $this->addBlock(self::IMAGE);
            $this->blocks[$b]['link'] = $link;
            [$this->blocks[$b]['first'], $this->blocks[$b]['count']] = $this->readInline($image[0]);
        } else {
            $b = $this->addBlock($this->heading ? self::HEADING : self::PARAGRAPH);
            $this->blocks[$b]['level'] = $this->heading;
            [$this->blocks[$b]['first'], $this->blocks[$b]['count']] = $this->readInline($this->paragraph);
        }
        $this->blocks[$b]['quote'] = $this->quote;
        $this->blocks[$b]['list'] = $this->list;
        $this->blocks[$b]['marker'] = $this->marker;
        $this->blocks[$b]['number'] = $this->number;
        $this->marker = self::NO_MARKER;
        $this->heading = 0;
        $this->paragraph = '';
    }

    /** @return array{string, string}|null [alt, url] when the paragraph is nothing but an image */
    private static function loneImage(string $s): ?array
    {
        $text = trim($s, " \t\n\r");
        if (strlen($text) < 5 || $text[0] !== '!' || $text[1] !== '[') {
            return null;
        }
        $parts = self::linkParts($text, 1, strlen($text));
        if ($parts === null || $parts[0] !== strlen($text)) {
            return null;
        }

        return [substr($text, 2, $parts[1] - 2), $parts[2]];
    }

    private function paragraphLine(string $line): void
    {
        $this->paragraph .= ($this->paragraphOpen ? "\n" : '').$line;
        $this->paragraphOpen = true;
    }

    /** "---", "***", "___", with or without spaces. */
    private static function isRule(string $line): bool
    {
        $marks = str_replace([' ', "\t"], '', $line);

        return strlen($marks) >= 3 && strspn($marks, $marks[0]) === strlen($marks) && str_contains('-*_', $marks[0]);
    }

    /** "|---|:-:|--:|": the line under the header of a table; its column count, or 0 (markdown.c:583-614). */
    private static function tableRule(string $line): int
    {
        $i = 0;
        $n = strlen($line);
        $count = 0;
        $anyPipe = false;
        $skip = function () use ($line, $n, &$i) {
            while ($i < $n && ($line[$i] === ' ' || $line[$i] === "\t")) {
                $i++;
            }
        };
        $skip();
        if ($i < $n && $line[$i] === '|') {
            $anyPipe = true;
            $i++;
        }
        for (;;) {
            $skip();
            if ($i < $n && $line[$i] === ':') {
                $i++;
            }
            $dashes = strspn($line, '-', $i);
            $i += $dashes;
            if ($i < $n && $line[$i] === ':') {
                $i++;
            }
            $skip();
            if ($dashes === 0) {
                break;
            }
            $count++;
            if ($i < $n && $line[$i] === '|') {
                $anyPipe = true;
                $i++;

                continue;
            }
            break;
        }
        $skip();

        return $i === $n && $count > 0 && $anyPipe ? $count : 0;
    }

    /** @return list<string> the cells of a row of a table (markdown.c:617-645) */
    private static function splitRow(string $line): array
    {
        $n = strlen($line);
        $i = strspn($line, " \t");
        if ($i < $n && $line[$i] === '|') {
            $i++;
        }
        $cellStart = $i;
        $cells = [];
        for (; $i <= $n; $i++) {
            $atEnd = $i === $n;
            if (! $atEnd && $line[$i] === '\\' && $i + 1 < $n) {
                $i++;

                continue;
            }
            if ($atEnd || $line[$i] === '|') {
                $cell = trim(substr($line, $cellStart, $i - $cellStart), " \t");
                // (the pipe that closes the row is not followed by a cell)
                if ($atEnd && $cell === '' && $cells !== []) {
                    break;
                }
                $cells[] = $cell;
                $cellStart = $i + 1;
            }
        }

        return $cells;
    }

    private static function cellAlign(string $rule, int $column): int
    {
        $cell = self::splitRow($rule)[$column] ?? '';
        if ($cell === '' || $column >= 64) {
            return 0;
        }
        $left = $cell[0] === ':';
        $right = $cell[strlen($cell) - 1] === ':';

        return $left && $right ? 1 : ($right ? 2 : 0);
    }

    /**
     * Without the indentation the lines share, past the first and blank ones (tabs count as one); null when
     * there is none to remove (markdown.c:666-695).
     */
    private static function dedent(string $source): ?string
    {
        $lines = explode("\n", $source);
        $common = PHP_INT_MAX;
        foreach ($lines as $i => $line) {
            $indent = strspn($line, " \t");
            if ($i > 0 && $indent < strlen($line) && $indent < $common) {
                $common = $indent;
            }
        }
        if ($common === PHP_INT_MAX || $common === 0) {
            return null;
        }

        return implode("\n", array_map(fn ($line) => substr($line, min($common, strspn($line, " \t"))), $lines));
    }

    /** Strips up to $quote quote marks from a line; how many it found, and the rest. @return array{int, string} */
    private static function quoteMarks(string $line, int $quote): array
    {
        $found = 0;
        $at = 0;
        $n = strlen($line);
        while ($found < $quote && $at < $n) {
            $at += strspn($line, ' ', $at);
            if ($at < $n && $line[$at] === '>') {
                $at++;
                $found++;
            } else {
                break;
            }
        }

        return [$found, substr($line, $at)];
    }

    /** rvn_md_parse (markdown.c:697-1034). */
    private function read(string $source): void
    {
        $lines = explode("\n", $source);
        $count = count($lines);
        $inCode = false;
        $fenceLength = 0;
        $fenceIndent = 0;
        $fenceMark = '';
        $code = -1;
        $blankBefore = true;

        for ($n = 0; $n < $count; $n++) {
            $raw = $lines[$n];
            if (str_ends_with($raw, "\r")) {
                $raw = substr($raw, 0, -1);
            }

            // the quotes the line is in
            $at = 0;
            $quote = 0;
            for (;;) {
                $spaces = 0;
                $mark = $at;
                while ($mark < strlen($raw) && $raw[$mark] === ' ' && $spaces < 3) {
                    $mark++;
                    $spaces++;
                }
                if ($mark < strlen($raw) && $raw[$mark] === '>' && ! $inCode) {
                    $quote++;
                    $at = $mark + 1;
                    if ($at < strlen($raw) && $raw[$at] === ' ') {
                        $at++;
                    }

                    continue;
                }
                break;
            }
            $line = substr($raw, $at);
            $indent = 0;
            $lead = 0;
            while ($lead < strlen($line) && ($line[$lead] === ' ' || $line[$lead] === "\t")) {
                $indent += $line[$lead] === "\t" ? 4 : 1;
                $lead++;
            }
            $q = substr($line, $lead);
            $length = strlen($q);

            if ($inCode) {
                $run = strspn($q, $fenceMark);
                // the fence that closes: at least as long as the one that opened, alone on its line
                if ($run >= $fenceLength && rtrim(substr($q, $run), " \t") === '') {
                    $inCode = false;
                    $blankBefore = false;
                } else {
                    // the line as written, less the indentation of the fence
                    $skip = min($fenceIndent, strspn($line, ' '));
                    if ($this->blocks[$code]['codeLength'] > 0) {
                        $this->text .= "\n";
                    }
                    $this->text .= substr($line, $skip);
                    $this->blocks[$code]['codeLength'] = strlen($this->text) - $this->blocks[$code]['codeStart'];
                }

                continue;
            }

            if ($length === 0) {
                $this->endParagraph();
                $blankBefore = true;

                continue;
            }
            if ($quote !== $this->quote) {
                $this->endParagraph();
                // (a quote starts over: the lists that were open are not its lists)
                if ($blankBefore || $quote > $this->quote) {
                    $this->indents = [];
                    $this->list = 0;
                }
                $this->quote = min($quote, 8);
            }

            // fenced code
            if (($q[0] === '`' || $q[0] === '~') && $length >= 3) {
                $run = strspn($q, $q[0]);
                if ($run >= 3 && ($q[0] === '~' || ! str_contains(substr($q, $run), '`'))) {
                    $this->endParagraph();
                    $info = ltrim(substr($q, $run), " \t");
                    $info = substr($info, 0, strcspn($info, " \t\n\r{,"));
                    $code = $this->addBlock(self::CODE_BLOCK);
                    $this->blocks[$code]['quote'] = $this->quote;
                    $this->blocks[$code]['list'] = $indent >= 2 && $this->indents !== [] ? $this->list : 0;
                    $this->blocks[$code]['language'] = $info;
                    $this->text .= $info."\n";
                    $this->blocks[$code]['codeStart'] = strlen($this->text);
                    $inCode = true;
                    $fenceMark = $q[0];
                    $fenceLength = $run;
                    $fenceIndent = $indent;
                    $blankBefore = false;

                    continue;
                }
            }

            // a title
            if ($q[0] === '#') {
                $level = strspn($q, '#');
                if ($level <= 6 && ($level === $length || $q[$level] === ' ' || $q[$level] === "\t")) {
                    $title = trim(substr($q, $level), " \t");
                    // closing marks: "## Title ##"
                    $bare = rtrim($title, '#');
                    if ($bare !== $title && ($bare === '' || str_ends_with($bare, ' '))) {
                        $title = rtrim($bare, " \t");
                    }
                    $this->endParagraph();
                    $this->indents = [];
                    $this->list = 0;
                    $this->heading = $level;
                    $this->paragraphLine($title);
                    $this->endParagraph();
                    $blankBefore = false;

                    continue;
                }
            }

            // a rule (a line of dashes right under a paragraph makes it a title instead)
            if (self::isRule($q)) {
                if ($this->paragraphOpen && $q[0] === '-' && ! str_contains($q, ' ')) {
                    $this->heading = 2;
                    $this->endParagraph();
                } else {
                    $this->endParagraph();
                    $this->indents = [];
                    $this->list = 0;
                    $this->blocks[$this->addBlock(self::RULE)]['quote'] = $this->quote;
                }
                $blankBefore = false;

                continue;
            }
            if ($this->paragraphOpen && $length >= 2 && $q[0] === '=' && strspn($q, "= \t") >= $length) {
                $this->heading = 1;
                $this->endParagraph();
                $blankBefore = false;

                continue;
            }

            // a table: a row, then the line of dashes
            if (! $this->paragraphOpen && str_contains($q, '|') && $n + 1 < $count) {
                [$ruleQuote, $rule] = self::quoteMarks(rtrim($lines[$n + 1], "\r"), $quote);
                $columns = $ruleQuote === $quote ? self::tableRule($rule) : 0;
                if ($columns > 0) {
                    $columns = min($columns, self::MAX_COLUMNS);
                    $t = $this->addBlock(self::TABLE);
                    $this->blocks[$t]['quote'] = $this->quote;
                    $this->blocks[$t]['columns'] = $columns;
                    $this->blocks[$t]['firstCell'] = count($this->cells);
                    $n++;
                    $row = $q;
                    $rows = 0;
                    for (;;) {
                        $found = self::splitRow($row);
                        for ($c = 0; $c < $columns; $c++) {
                            [$first, $spans] = $c < count($found) ? $this->readInline($found[$c]) : [count($this->spans), 0];
                            $this->cells[] = [$first, $spans, self::cellAlign($rule, $c)];
                        }
                        $rows++;
                        // the next row, as long as there is one
                        if ($n + 1 >= $count) {
                            break;
                        }
                        [$rowQuote, $next] = self::quoteMarks($lines[$n + 1], $quote);
                        $next = rtrim(ltrim($next, " \t"), "\r");
                        if ($rowQuote !== $quote || $next === '' || ! str_contains($next, '|')) {
                            break;
                        }
                        $row = $next;
                        $n++;
                    }
                    $this->blocks[$t]['rows'] = $rows;
                    $blankBefore = false;

                    continue;
                }
            }

            // a list item
            $marker = self::NO_MARKER;
            $number = 0;
            $text = '';
            if (($q[0] === '-' || $q[0] === '*' || $q[0] === '+') && $length >= 2 && ($q[1] === ' ' || $q[1] === "\t")) {
                $marker = self::BULLET;
                $text = substr($q, 2);
            } elseif (ctype_digit($q[0])) {
                $digits = min(strspn($q, '0123456789'), 9);
                $number = (int) substr($q, 0, $digits);
                if ($digits + 1 < $length && ($q[$digits] === '.' || $q[$digits] === ')') && ($q[$digits + 1] === ' ' || $q[$digits + 1] === "\t")) {
                    $marker = self::NUMBER;
                    $text = substr($q, $digits + 2);
                }
            }
            // (a number in the middle of a paragraph does not start a list unless it is 1)
            if ($marker === self::NUMBER && $this->paragraphOpen && $this->indents === [] && $number !== 1) {
                $marker = self::NO_MARKER;
            }
            if ($marker !== self::NO_MARKER) {
                $this->endParagraph();
                while ($this->indents !== [] && end($this->indents) > $indent) {
                    array_pop($this->indents);
                }
                if ($this->indents === [] || $indent >= end($this->indents) + 2) {
                    if (count($this->indents) < 8) {
                        $this->indents[] = $indent;
                    }
                }
                $this->list = count($this->indents);
                $text = ltrim($text, " \t");
                if ($marker === self::BULLET && strlen($text) >= 3 && $text[0] === '[' && $text[2] === ']'
                    && str_contains(' xX', $text[1]) && (strlen($text) === 3 || $text[3] === ' ')) {
                    $marker = $text[1] === ' ' ? self::TASK : self::TASK_DONE;
                    $text = ltrim(substr($text, 3), " \t");
                }
                $this->marker = $marker;
                $this->number = $number;
                $this->paragraphLine($text);
                $blankBefore = false;

                continue;
            }

            // text: more of the paragraph, or a new one
            if (! $this->paragraphOpen) {
                if ($this->indents !== [] && $blankBefore && $indent < 2) {
                    // not indented after a blank line: the lists are over
                    $this->indents = [];
                    $this->list = 0;
                }
                $this->marker = self::NO_MARKER;
            }
            // (with the spaces at its end: two of them ask for a line break)
            $this->paragraphLine($q);
            $blankBefore = false;
        }
        $this->endParagraph();
    }
}
