<?php

namespace Silk\Text\Editor;

use Silk\Contracts\Look\FontSpec;
use Silk\Text\TextMeasurer;

/**
 * The rows of a TextEditor and the horizontal places in them (Raven text_editor.c:513-711). With word wrap a
 * line is shown on several rows; where it is cut is computed once per line and per width and kept until the
 * line changes. Without it each line is one row and its width is kept the same way. Coordinates are those of
 * the text: x from the start of a row, y from the top of the first row.
 */
final class EditorRows
{
    public FontSpec $font;

    /** ceil(line height + 2) (text_editor.c:532). */
    public float $lineHeight = 0.0;

    public float $space = 0.0;

    public float $tab = 0.0;

    public int $totalRows = 1;

    /** Without wrap: the width of the longest line. */
    public float $maxWidth = 0.0;

    private bool $wrap = false;

    private float $wrapWidth = -1.0;

    /** @var list<?float> width of each line on one row; null: not known */
    private array $widths = [];

    /** @var list<?list<int>> where the rows after the first begin; null: not computed */
    private array $breaks = [];

    /** @var list<int> the first row of each line, and the total after the last (wrap only) */
    private array $rowStarts = [];

    private bool $dirty = true;

    /** @var array<string, float> the advance of each code point met, in the current font (font.c:634-636) */
    private array $chars = [];

    private string $fontKey = '';

    public function __construct(private readonly TextMeasurer $measurer) {}

    /** The font and the tab width the rows are measured with; a change forgets what was measured. */
    public function setFont(FontSpec $font, int $tabColumns): void
    {
        $key = "{$font->family}\0{$font->size}\0{$font->weight}\0".(int) $font->italic."\0{$tabColumns}";
        if ($key === $this->fontKey) {
            return;
        }
        $this->fontKey = $key;
        $this->font = $font;
        $this->chars = [];
        $m = $this->measurer->metrics($font);
        $this->lineHeight = ceil($m['lineHeight'] + 2.0);
        $this->space = $this->measurer->measure(' ', $font)->w ?: $font->size * 0.3;
        $this->tab = $this->space * max($tabColumns, 1);
        $this->widths = array_fill(0, count($this->widths), null);
        $this->breaks = array_fill(0, count($this->breaks), null);
        $this->dirty = true;
    }

    /** Applies the line splices of the text: changed lines are measured again, the others keep theirs. */
    public function splice(EditorText $doc): void
    {
        foreach ($doc->splices as [$at, $removed, $inserted]) {
            array_splice($this->widths, $at, $removed, array_fill(0, $inserted, null));
            array_splice($this->breaks, $at, $removed, array_fill(0, $inserted, null));
            $this->dirty = true;
        }
        $doc->splices = [];
    }

    // ------------------------------------------------------------ measuring

    private function char(string $c): float
    {
        return $this->chars[$c] ??= $this->measurer->measure($c, $this->font)->w;
    }

    /**
     * The x reached after text[from, to) starting at $x on the row; tabs jump to the next stop (rvn_ed_advance,
     * text_editor.c:556-567). A run's width is the sum of its code points' advances, as Raven's rvn_text_width
     * measures it (render/font.c:617-640: cmap advance per code point, no kerning); each advance is measured
     * once per font and kept.
     */
    public function advance(string $text, int $from, int $to, float $x = 0.0): float
    {
        $i = $from;
        while ($i < $to) {
            $next = self::next($text, $to, $i);
            $c = substr($text, $i, $next - $i);
            $x = $c === "\t" ? (floor($x / $this->tab + 0.001) + 1.0) * $this->tab : $x + $this->char($c);
            $i = $next;
        }

        return $x;
    }

    /** The byte offset in text[from, to) nearest $target (rvn_ed_offset_at). */
    public function offsetAt(string $text, int $from, int $to, float $target): int
    {
        $x = 0.0;
        $i = $from;
        while ($i < $to) {
            $next = self::next($text, $to, $i);
            $after = $this->advance($text, $i, $next, $x);
            if ($target < ($x + $after) * 0.5) {
                return $i;
            }
            $x = $after;
            $i = $next;
        }

        return $to;
    }

    public static function next(string $text, int $to, int $i): int
    {
        $i++;
        while ($i < $to && (ord($text[$i]) & 0xC0) === 0x80) {
            $i++;
        }

        return $i;
    }

    public static function previous(string $text, int $i): int
    {
        $i--;
        while ($i > 0 && (ord($text[$i]) & 0xC0) === 0x80) {
            $i--;
        }

        return max(0, $i);
    }

    // ----------------------------------------------------------------- rows

    /** Cuts a line into rows no wider than $width, at spaces when it can (rvn_ed_wrap_line, text_editor.c:587-615). */
    private function wrapLine(string $text, float $width): array
    {
        $breaks = [];
        $length = strlen($text);
        $i = $rowStart = 0;
        $lastSpace = -1;
        $x = 0.0;
        while ($i < $length) {
            $next = self::next($text, $length, $i);
            $after = $this->advance($text, $i, $next, $x);
            if ($after > $width && $i > $rowStart) {
                $cut = $lastSpace > $rowStart ? $lastSpace : $i;
                $breaks[] = $cut;
                $rowStart = $cut;
                $lastSpace = -1;
                $x = $this->advance($text, $cut, $i);

                continue;
            }
            if ($text[$i] === ' ' || $text[$i] === "\t") {
                $lastSpace = $next;
            }
            $x = $after;
            $i = $next;
        }

        return $breaks;
    }

    /** Brings the row tables and the size of the content up to date (rvn_ed_update_rows, text_editor.c:638-674). */
    public function update(EditorText $doc, bool $wrap, float $width): void
    {
        $this->splice($doc);
        $count = count($doc->lines);
        if (count($this->widths) !== $count) {
            $this->widths = array_pad(array_slice($this->widths, 0, $count), $count, null);
            $this->breaks = array_pad(array_slice($this->breaks, 0, $count), $count, null);
        }
        if ($wrap !== $this->wrap || ($wrap && $this->wrapWidth !== $width)) {
            $this->wrap = $wrap;
            $this->wrapWidth = $width;
            $this->breaks = array_fill(0, $count, null);
            $this->dirty = true;
        }
        if (! $this->dirty) {
            return;
        }
        $this->dirty = false;
        if ($wrap) {
            $rows = 0;
            $this->rowStarts = [];
            $cut = max($width, $this->space * 4.0);
            foreach ($doc->lines as $i => $text) {
                $this->breaks[$i] ??= $this->wrapLine($text, $cut);
                $this->rowStarts[] = $rows;
                $rows += count($this->breaks[$i]) + 1;
            }
            $this->rowStarts[] = $rows;
            $this->totalRows = $rows;
            $this->maxWidth = 0.0;
        } else {
            $widest = 0.0;
            foreach ($doc->lines as $i => $text) {
                $this->widths[$i] ??= $this->advance($text, 0, strlen($text));
                $widest = max($widest, $this->widths[$i]);
            }
            $this->totalRows = $count;
            $this->maxWidth = $widest;
        }
    }

    public function rowCount(int $line): int
    {
        return $this->wrap ? count($this->breaks[$line] ?? []) + 1 : 1;
    }

    /** @return array{int, int} the bytes of a line shown on one of its rows (rvn_ed_row_range) */
    public function rowRange(EditorText $doc, int $line, int $row): array
    {
        $breaks = $this->wrap ? ($this->breaks[$line] ?? []) : [];
        if ($breaks === []) {
            return [0, strlen($doc->lines[$line])];
        }

        return [$row > 0 ? $breaks[$row - 1] : 0, $breaks[$row] ?? strlen($doc->lines[$line])];
    }

    /** The row of a line showing a column: a column on a cut starts the row after it (rvn_ed_row_of). */
    public function rowOf(int $line, int $column): int
    {
        $breaks = $this->wrap ? ($this->breaks[$line] ?? []) : [];
        $row = 0;
        while ($row < count($breaks) && $breaks[$row] <= $column) {
            $row++;
        }

        return $row;
    }

    public function firstRow(int $line): int
    {
        return $this->wrap ? ($this->rowStarts[$line] ?? $line) : $line;
    }

    /** @return array{int, int} the line and its row of a row counted from the top (rvn_ed_locate_row) */
    public function locate(int $global, int $lineCount): array
    {
        $global = max(0, min($this->totalRows - 1, $global));
        if (! $this->wrap) {
            return [min($global, $lineCount - 1), 0];
        }
        [$low, $high] = [0, $lineCount - 1];
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            $this->rowStarts[$middle] <= $global ? $low = $middle : $high = $middle - 1;
        }

        return [$low, $global - $this->rowStarts[$low]];
    }

    /** @return array{float, float} where a position is drawn, in the text's coordinates (rvn_ed_pos_point) */
    public function point(EditorText $doc, array $pos): array
    {
        $row = $this->rowOf($pos[0], $pos[1]);
        [$begin] = $this->rowRange($doc, $pos[0], $row);

        return [$this->advance($doc->lines[$pos[0]], $begin, $pos[1]), ($this->firstRow($pos[0]) + $row) * $this->lineHeight];
    }

    /** @return array{int, int} the position under a point of the text (rvn_ed_point_pos, text_editor.c:702-715) */
    public function position(EditorText $doc, float $x, float $y): array
    {
        $global = (int) max(0, min($this->totalRows, floor($y / $this->lineHeight)));
        [$line, $row] = $this->locate($global, count($doc->lines));
        [$begin, $end] = $this->rowRange($doc, $line, $row);
        $text = $doc->lines[$line];
        $column = $this->offsetAt($text, $begin, $end, $x);
        // the end of a wrapped row is the position before the cut, not the start of the next row
        if ($this->wrap && $column === $end && $row + 1 < $this->rowCount($line) && $column > $begin) {
            $column = self::previous($text, $column);
        }

        return [$line, $column];
    }
}
