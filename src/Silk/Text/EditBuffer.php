<?php

namespace Silk\Text;

use IntlBreakIterator;

/**
 * One line of editable text: a caret and selection on grapheme boundaries (byte offsets), word moves,
 * deletes, a one-entry kill buffer (DeleteToLineEnd, Yank), and undo and redo that group consecutive typing.
 */
class EditBuffer
{
    /** Undo steps kept. */
    public const int HISTORY = 200;

    private int $anchor;

    private int $caret;

    private ?IntlBreakIterator $graphemes = null;

    /** @var list<array{string, int, int}> */
    private array $undo = [];

    /** @var list<array{string, int, int}> */
    private array $redo = [];

    /** Whether the last change was typing that the next typing may join. */
    private bool $typing = false;

    private string $killed = '';

    public function __construct(private string $text = '')
    {
        $this->anchor = $this->caret = strlen($text);
    }

    public function text(): string
    {
        return $this->text;
    }

    /** Replace the whole text, as code does: the history is cleared and the caret goes to the end. */
    public function setText(string $text): void
    {
        $this->text = $text;
        $this->graphemes = null;
        $this->anchor = $this->caret = strlen($text);
        $this->undo = $this->redo = [];
        $this->typing = false;
    }

    public function selection(): Selection
    {
        return new Selection($this->anchor, $this->caret);
    }

    public function selectedText(): string
    {
        $s = $this->selection();

        return substr($this->text, $s->start(), $s->end() - $s->start());
    }

    public function killed(): string
    {
        return $this->killed;
    }

    /** Insert at the caret, replacing the selection. Typing joins the undo step of the typing before it. */
    public function insert(string $text, bool $typing = false): bool
    {
        if ($text === '' && $this->selection()->isEmpty()) {
            return false;
        }
        $this->remember($typing);
        $s = $this->selection();
        $this->replace($s->start(), $s->end(), $text);
        $this->typing = $typing;

        return true;
    }

    public function deleteBackward(): bool
    {
        return $this->deleteTo(fn () => $this->previousGrapheme($this->caret));
    }

    public function deleteForward(): bool
    {
        return $this->deleteTo(fn () => $this->nextGrapheme($this->caret));
    }

    public function deleteWordBackward(): bool
    {
        return $this->deleteTo(fn () => WordBreaks::previous($this->text, $this->caret));
    }

    public function deleteWordForward(): bool
    {
        return $this->deleteTo(fn () => WordBreaks::next($this->text, $this->caret));
    }

    /** Delete from the caret to the end into the kill buffer. */
    public function deleteToEnd(): bool
    {
        if ($this->caret === strlen($this->text)) {
            return false;
        }
        $this->killed = substr($this->text, $this->caret);

        return $this->deleteTo(fn () => strlen($this->text));
    }

    public function yank(): bool
    {
        return $this->insert($this->killed);
    }

    public function moveLeft(bool $word, bool $extend): void
    {
        $s = $this->selection();
        if (! $extend && ! $s->isEmpty() && ! $word) {
            $this->place($s->start(), false);

            return;
        }
        $this->place($word ? WordBreaks::previous($this->text, $this->caret) : $this->previousGrapheme($this->caret), $extend);
    }

    public function moveRight(bool $word, bool $extend): void
    {
        $s = $this->selection();
        if (! $extend && ! $s->isEmpty() && ! $word) {
            $this->place($s->end(), false);

            return;
        }
        $this->place($word ? WordBreaks::next($this->text, $this->caret) : $this->nextGrapheme($this->caret), $extend);
    }

    public function home(bool $extend): void
    {
        $this->place(0, $extend);
    }

    public function end(bool $extend): void
    {
        $this->place(strlen($this->text), $extend);
    }

    public function selectAll(): void
    {
        $this->anchor = 0;
        $this->caret = strlen($this->text);
        $this->typing = false;
    }

    /** Put the caret at the grapheme boundary nearest $byte. */
    public function setCaret(int $byte, bool $extend): void
    {
        $this->place($this->snap($byte), $extend);
    }

    public function selectWordAt(int $byte): void
    {
        [$start, $end] = WordBreaks::around($this->text, max(0, min(strlen($this->text), $byte)));
        $this->anchor = $start;
        $this->caret = $end;
        $this->typing = false;
    }

    public function undo(): bool
    {
        return $this->swap($this->undo, $this->redo);
    }

    public function redo(): bool
    {
        return $this->swap($this->redo, $this->undo);
    }

    /** @return list<int> every grapheme boundary, 0 and the end included */
    public function boundaries(): array
    {
        $it = $this->graphemes();
        $out = [];
        for ($b = $it->first(); $b !== IntlBreakIterator::DONE; $b = $it->next()) {
            $out[] = $b;
        }

        return $out ?: [0];
    }

    private function deleteTo(callable $other): bool
    {
        $s = $this->selection();
        if ($s->isEmpty()) {
            $to = $other();
            if ($to === $this->caret) {
                return false;
            }
            $s = new Selection($to, $this->caret);
        }
        $this->remember(false);
        $this->replace($s->start(), $s->end(), '');
        $this->typing = false;

        return true;
    }

    private function place(int $byte, bool $extend): void
    {
        $this->caret = $byte;
        if (! $extend) {
            $this->anchor = $byte;
        }
        $this->typing = false;
    }

    private function replace(int $from, int $to, string $with): void
    {
        $this->text = substr($this->text, 0, $from).$with.substr($this->text, $to);
        $this->graphemes = null;
        $this->anchor = $this->caret = $from + strlen($with);
    }

    private function remember(bool $typing): void
    {
        $this->redo = [];
        if ($typing && $this->typing) {
            return;
        }
        $this->undo[] = [$this->text, $this->anchor, $this->caret];
        if (count($this->undo) > self::HISTORY) {
            array_shift($this->undo);
        }
    }

    /** @param list<array{string, int, int}> $from @param list<array{string, int, int}> $to */
    private function swap(array &$from, array &$to): bool
    {
        if ($from === []) {
            return false;
        }
        $to[] = [$this->text, $this->anchor, $this->caret];
        [$this->text, $this->anchor, $this->caret] = array_pop($from);
        $this->graphemes = null;
        $this->typing = false;

        return true;
    }

    private function previousGrapheme(int $byte): int
    {
        if ($byte <= 0) {
            return 0;
        }
        $b = $this->graphemes()->preceding($byte);

        return $b === IntlBreakIterator::DONE ? 0 : $b;
    }

    private function nextGrapheme(int $byte): int
    {
        $len = strlen($this->text);
        if ($byte >= $len) {
            return $len;
        }
        $b = $this->graphemes()->following($byte);

        return $b === IntlBreakIterator::DONE ? $len : $b;
    }

    private function snap(int $byte): int
    {
        $len = strlen($this->text);
        $byte = max(0, min($len, $byte));

        return $byte === 0 || $byte === $len || $this->graphemes()->isBoundary($byte) ? $byte : $this->previousGrapheme($byte);
    }

    private function graphemes(): IntlBreakIterator
    {
        if ($this->graphemes === null) {
            $this->graphemes = IntlBreakIterator::createCharacterInstance();
            $this->graphemes->setText($this->text);
        }

        return $this->graphemes;
    }
}
