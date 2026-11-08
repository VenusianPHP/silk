<?php

namespace Silk\Text\Editor;

/**
 * The text of a TextEditor: an array of lines (one PHP string each, so an edit touches one line), the caret
 * and the anchor, the markers and the history (Raven widgets/text_editor.c:175-487). A position is
 * [line, column], the column a byte offset in the line. Every change goes through replace(), which also
 * records what undoes it.
 */
final class EditorText
{
    /** Characters typed in a row are undone together, up to this many (text_editor.c:22). */
    public const int TYPING_GROUP = 48;

    /** text_editor.c:23 */
    public const int MAX_UNDO = 512;

    /** @var list<string> */
    public array $lines = [''];

    /** @var array{int, int} */
    public array $caret = [0, 0];

    /** @var array{int, int} */
    public array $anchor = [0, 0];

    /** Counts the changes of the text: what was computed for a version is forgotten with it. */
    public int $version = 0;

    /** The first line changed since the owner last looked (null: none). */
    public ?int $changedFrom = null;

    /** @var list<array{int, int, int}> the line splices since the view last looked: [at, removed, inserted] */
    public array $splices = [];

    /**
     * @var list<array{start: array{int, int}, removed: string, inserted: string, caretBefore: array{int, int},
     *   anchorBefore: array{int, int}, caretAfter: array{int, int}, typing: bool}>
     */
    private array $undo = [];

    /** @var list<array{start: array{int, int}, removed: string, inserted: string, caretBefore: array{int, int}, anchorBefore: array{int, int}, caretAfter: array{int, int}, typing: bool}> */
    private array $redo = [];

    /** @var list<array{line: int, from: int, to: int, kind: MarkerKind, message: string}> to -1: to the end of the line */
    public array $markers = [];

    // ------------------------------------------------------------ positions

    public static function cmp(array $a, array $b): int
    {
        return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
    }

    /** @return array{int, int} inside the text, never inside a character */
    public function clamp(array $pos): array
    {
        $line = max(0, min(count($this->lines) - 1, $pos[0]));
        $text = $this->lines[$line];
        $column = max(0, min(strlen($text), $pos[1]));
        while ($column > 0 && $column < strlen($text) && (ord($text[$column]) & 0xC0) === 0x80) {
            $column--;
        }

        return [$line, $column];
    }

    /** @return array{int, int} */
    public function selMin(): array
    {
        return self::cmp($this->caret, $this->anchor) <= 0 ? $this->caret : $this->anchor;
    }

    /** @return array{int, int} */
    public function selMax(): array
    {
        return self::cmp($this->caret, $this->anchor) <= 0 ? $this->anchor : $this->caret;
    }

    public function hasSelection(): bool
    {
        return self::cmp($this->caret, $this->anchor) !== 0;
    }

    /** @return array{int, int} */
    public function end(): array
    {
        $last = count($this->lines) - 1;

        return [$last, strlen($this->lines[$last])];
    }

    /** @return array{int, int} where inserting $text at $start ends; "\r" before a line break dropped, as replace() does */
    public static function after(array $start, string $text): array
    {
        $pieces = explode("\n", $text);
        foreach ($pieces as $i => $piece) {
            $length = strlen(rtrim($piece, "\r"));
            $start = $i === 0 ? [$start[0], $start[1] + $length] : [$start[0] + 1, $length];
        }

        return $start;
    }

    /** The text between two ordered positions, lines joined by "\n". */
    public function range(array $from, array $to): string
    {
        if ($from[0] === $to[0]) {
            return substr($this->lines[$from[0]], $from[1], $to[1] - $from[1]);
        }
        $out = substr($this->lines[$from[0]], $from[1]);
        for ($line = $from[0] + 1; $line < $to[0]; $line++) {
            $out .= "\n".$this->lines[$line];
        }

        return $out."\n".substr($this->lines[$to[0]], 0, $to[1]);
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }

    // -------------------------------------------------------------- history

    /**
     * The one function that changes the text (rvn_ed_replace, text_editor.c:339-447): replaces what lies
     * between two positions by $text and returns the position after it. $record stores the step.
     *
     * @return array{int, int}
     */
    public function replace(array $from, array $to, string $text, bool $record): array
    {
        if (self::cmp($from, $to) > 0) {
            [$from, $to] = [$to, $from];
        }
        if ($record) {
            $last = $this->undo === [] ? null : count($this->undo) - 1;
            $typed = $text !== '' && self::cmp($from, $to) === 0 && ! str_contains($text, "\n") && mb_strlen($text, 'UTF-8') === 1;
            $this->redo = [];
            $step = $last === null ? null : $this->undo[$last];
            if ($typed && $step !== null && $step['typing'] && $step['removed'] === '' && strlen($step['inserted']) < self::TYPING_GROUP
                && self::cmp($step['caretAfter'], $from) === 0) {
                // one more character of a run that is being typed: same step
                $this->undo[$last]['inserted'] .= $text;
                $this->undo[$last]['caretAfter'] = [$from[0], $from[1] + strlen($text)];
            } else {
                if (count($this->undo) === self::MAX_UNDO) {
                    array_shift($this->undo);
                }
                $this->undo[] = [
                    'start' => $from, 'removed' => $this->range($from, $to), 'inserted' => $text,
                    'caretBefore' => $this->caret, 'anchorBefore' => $this->anchor, 'caretAfter' => self::after($from, $text), 'typing' => $typed,
                ];
            }
        }
        $pieces = explode("\n", $text);
        // the markers below follow their lines; those of the lines that change are out of date
        $delta = (count($pieces) - 1) - ($to[0] - $from[0]);
        $kept = [];
        foreach ($this->markers as $marker) {
            if ($marker['line'] > $to[0]) {
                $marker['line'] += $delta;
                $kept[] = $marker;
            } elseif ($marker['line'] < $from[0]) {
                $kept[] = $marker;
            }
        }
        $this->markers = $kept;

        // what follows the range on its last line is kept aside, and put back after the new text
        $head = substr($this->lines[$from[0]], 0, $from[1]);
        $tail = substr($this->lines[$to[0]], $to[1]);
        $new = [];
        foreach ($pieces as $i => $piece) {
            $piece = rtrim($piece, "\r");
            $new[] = ($i === 0 ? $head : '').$piece;
        }
        $last = count($new) - 1;
        $end = [$from[0] + $last, strlen($new[$last])];
        $new[$last] .= $tail;
        array_splice($this->lines, $from[0], $to[0] - $from[0] + 1, $new);
        $this->splices[] = [$from[0], $to[0] - $from[0] + 1, count($new)];

        $this->version++;
        $this->changedFrom = $this->changedFrom === null ? $from[0] : min($this->changedFrom, $from[0]);

        return $end;
    }

    /** Replaces the selection by a text, as one step of the history. */
    public function insert(string $text): void
    {
        $this->caret = $this->anchor = $this->replace($this->selMin(), $this->selMax(), $text, true);
    }

    /** The whole text, the history forgotten, the caret at the start (rvn_ed_set_text). */
    public function reset(string $text): void
    {
        $this->undo = $this->redo = [];
        $this->replace([0, 0], $this->end(), $text, false);
        $this->caret = $this->anchor = [0, 0];
    }

    public function breakTyping(): void
    {
        if ($this->undo !== []) {
            $this->undo[count($this->undo) - 1]['typing'] = false;
        }
    }

    public function canUndo(): bool
    {
        return $this->undo !== [];
    }

    public function canRedo(): bool
    {
        return $this->redo !== [];
    }

    public function undo(): bool
    {
        $edit = array_pop($this->undo);
        if ($edit === null) {
            return false;
        }
        $edit['typing'] = false;
        $this->replace($edit['start'], self::after($edit['start'], $edit['inserted']), $edit['removed'], false);
        $this->caret = $this->clamp($edit['caretBefore']);
        $this->anchor = $this->clamp($edit['anchorBefore']);
        $this->redo[] = $edit;

        return true;
    }

    public function redo(): bool
    {
        $edit = array_pop($this->redo);
        if ($edit === null) {
            return false;
        }
        $this->replace($edit['start'], self::after($edit['start'], $edit['removed']), $edit['inserted'], false);
        $this->caret = $this->anchor = $this->clamp($edit['caretAfter']);
        $this->undo[] = $edit;

        return true;
    }

    // ------------------------------------------------------- moving by text

    /** text_editor.c:1135-1139: 0 space, 1 word, 2 punctuation */
    public static function charClass(string $c): int
    {
        if ($c === ' ' || $c === "\t") {
            return 0;
        }

        return ctype_alnum($c) || $c === '_' || ord($c) >= 0x80 ? 1 : 2;
    }

    /** @return array{int, int} */
    public function charLeft(array $pos): array
    {
        if ($pos[1] > 0) {
            $text = $this->lines[$pos[0]];
            $c = $pos[1] - 1;
            while ($c > 0 && (ord($text[$c]) & 0xC0) === 0x80) {
                $c--;
            }

            return [$pos[0], $c];
        }

        return $pos[0] > 0 ? [$pos[0] - 1, strlen($this->lines[$pos[0] - 1])] : $pos;
    }

    /** @return array{int, int} */
    public function charRight(array $pos): array
    {
        $text = $this->lines[$pos[0]];
        if ($pos[1] < strlen($text)) {
            $c = $pos[1] + 1;
            while ($c < strlen($text) && (ord($text[$c]) & 0xC0) === 0x80) {
                $c++;
            }

            return [$pos[0], $c];
        }

        return $pos[0] + 1 < count($this->lines) ? [$pos[0] + 1, 0] : $pos;
    }

    /** @return array{int, int} */
    public function wordLeft(array $pos): array
    {
        $text = $this->lines[$pos[0]];
        if ($pos[1] === 0) {
            return $this->charLeft($pos);
        }
        $c = $pos[1];
        while ($c > 0 && self::charClass($text[$c - 1]) === 0) {
            $c--;
        }
        if ($c === 0) {
            return [$pos[0], 0];
        }
        $kind = self::charClass($text[$c - 1]);
        while ($c > 0 && self::charClass($text[$c - 1]) === $kind) {
            $c--;
        }

        return [$pos[0], $c];
    }

    /** @return array{int, int} */
    public function wordRight(array $pos): array
    {
        $text = $this->lines[$pos[0]];
        $length = strlen($text);
        if ($pos[1] >= $length) {
            return $this->charRight($pos);
        }
        $c = $pos[1];
        $kind = self::charClass($text[$c]);
        while ($c < $length && self::charClass($text[$c]) === $kind) {
            $c++;
        }
        while ($c < $length && self::charClass($text[$c]) === 0) {
            $c++;
        }

        return [$pos[0], $c];
    }
}
