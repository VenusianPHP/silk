<?php

namespace Silk\Widgets;

use Silk\Painter\Surfaces;
use Silk\Contracts\Look\SurfaceKind;
use InvalidArgumentException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\FontRole;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\Look;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\SyntaxToken;
use Silk\Contracts\Mail\ZoomChanged;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\LookBlend;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Widget;
use Silk\Text\CaretBlink;
use Silk\Text\Editor\EditorRows;
use Silk\Text\Editor\EditorText;
use Silk\Text\Editor\MarkerKind;
use Silk\Text\Syntax\Language;
use Silk\Text\Syntax\Syntax;
use Silk\Widgets\Concerns\MeasuresText;
use Silk\Widgets\Editing\CompletionList;
use Silk\Widgets\Rows\RowScroll;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\Services\ClipboardText;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\ViewScrolled;
use Surface\Contracts\Windows\Services\Clipboard;
use Surface\Contracts\Windows\Styling\ScrollPosition;
use Surface\Contracts\Windows\Styling\TextAlignment;
use Surface\Contracts\Windows\Styling\TextRangeStyle;
use Surface\NutsAndBolts\Color;

/**
 * Multi-line text editing, as Raven's widgets/text_editor.c, with the API of the TK twin TKTextArea (it owns
 * value/setValue, wrap on by default, tab width 8, line backgrounds, range styles, selected lines, scrolling
 * and the mail) and Raven's own on top (language, Markdown, markers, completion, zoom, auto-pairs).
 * Positions are [line, byte column]. TextChanged is posted for the user's edits only.
 */
class TextEditor extends Widget
{
    use MeasuresText;

    protected EditorText $doc;

    private bool $readOnly = false;

    private bool $wrap = true;

    private int $tabWidth = 8;

    private bool $tabSpaces = false;

    private bool $pairs = true;

    private TextAlignment $alignment = TextAlignment::LEFT;

    private ?Color $textColor = null;

    private ?FontSpec $font = null;

    /** @var array<int, Color> */
    private array $lineBackgrounds = [];

    /** @var list<TextRangeStyle> */
    private array $rangeStyles = [];

    private ?Language $language = null;

    /** What the lexer is in at the start of each line, valid for lines [0, lexValid). @var list<int> */
    private array $lexIn = [0];

    private int $lexValid = 1;

    /** Horizontal place kept while moving up and down; null: take it from the caret. */
    protected ?float $goalX = null;

    protected bool $hasFocus = false;

    private ?Clipboard $clipboard = null;

    private ?string $pasteRequest = null;

    private ?EditorRows $rows = null;

    private RowScroll $vbar;

    private RowScroll $hbar;

    private float $zoom = 1.0;

    private bool $zoomable = true;

    private bool $lineNumbers = false;

    private ?string $placeholder = null;

    private bool $watchScroll = false;

    private bool $selecting = false;

    private bool $revealPending = false;

    /** The caret glides to its place (SNAPPY) and blinks once it rests there; the frame eases to Focus. */
    private Spring $caretX;

    private Spring $caretY;

    private bool $caretPlaced = false;

    private Spring $focusEase;

    private CaretBlink $blink;

    /** @var array{float, float} */
    private array $pointer = [-1.0, -1.0];

    private ?CompletionList $complete = null;

    private bool $completion = true;

    /** @var (\Closure(string, TextEditor): void)|null */
    private ?\Closure $completionCallback = null;

    private bool $collecting = false;

    public function __construct(string $name, string $value = '')
    {
        parent::__construct($name);
        $this->doc = new EditorText;
        $this->vbar = new RowScroll;
        $this->hbar = new RowScroll;
        $this->caretX = new Spring(0.0, SpringPreset::Snappy);
        $this->caretY = new Spring(0.0, SpringPreset::Snappy);
        $this->focusEase = new Spring(0.0, SpringPreset::Snappy);
        $this->blink = new CaretBlink;
        $this->focusable = true;
        $value !== '' && $this->setValue($value);
    }

    // ------------------------------------------------------------- TK twin

    public function value(): string
    {
        return $this->doc->text();
    }

    /** "\r\n" and "\r" become "\n"; the history is forgotten, the caret goes to the start (rvn_ed_set_text). */
    public function setValue(string $value): static
    {
        $this->doc->reset(str_replace(["\r\n", "\r"], "\n", $value));
        $this->goalX = null;
        $this->changed();

        return $this->invalidateLayout();
    }

    public function setFont(FontSpec $font): static
    {
        $this->font = $font;

        return $this->invalidateLayout();
    }

    public function setTextColor(?Color $color): static
    {
        $this->textColor = $color;

        return $this->invalidatePaint();
    }

    /** Read-only text still selects and copies. */
    public function setReadOnly(bool $readOnly): static
    {
        $this->readOnly = $readOnly;

        return $this->invalidatePaint();
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /** True (the default) wraps at the view's width; false scrolls sideways. */
    public function setWrap(bool $wrap): static
    {
        $this->wrap = $wrap;

        return $this->invalidateLayout();
    }

    public function wraps(): bool
    {
        return $this->wrap;
    }

    /** Tab stops every $columns spaces; 8 until set. */
    public function setTabWidth(int $columns): static
    {
        if ($columns < 1) {
            throw new InvalidArgumentException("A tab is at least 1 column wide, got {$columns}.");
        }
        $this->tabWidth = $columns;

        return $this->invalidateLayout();
    }

    public function tabWidth(): int
    {
        return $this->tabWidth;
    }

    public function setAlignment(TextAlignment $alignment): static
    {
        $this->alignment = $alignment;

        return $this->invalidatePaint();
    }

    public function alignment(): TextAlignment
    {
        return $this->alignment;
    }

    /** @param array<int, Color> $backgrounds full-width backgrounds by 0-based line; replaces the previous set */
    public function setLineBackgrounds(array $backgrounds): static
    {
        $this->lineBackgrounds = $backgrounds;

        return $this->invalidatePaint();
    }

    /** @param list<TextRangeStyle> $styles character runs' colours; replaces the previous set */
    public function setRangeStyles(array $styles): static
    {
        $this->rangeStyles = $styles;

        return $this->invalidatePaint();
    }

    public function lineCount(): int
    {
        return count($this->doc->lines);
    }

    /** The first selected line: the caret's line when nothing is selected. */
    public function caretLine(): int
    {
        return $this->doc->selMin()[0];
    }

    /** @return array{int, int} the first and last selected lines, inclusive */
    public function selectedLines(): array
    {
        return [$this->doc->selMin()[0], $this->doc->selMax()[0]];
    }

    /** Selects whole lines, the "\n" of the last included. */
    public function selectLines(int $first, int $last): static
    {
        [$first, $last] = [min($first, $last), max($first, $last)];
        $this->doc->anchor = $this->doc->clamp([$first, 0]);
        $end = $last + 1 < $this->lineCount() ? [$last + 1, 0] : [$last, PHP_INT_MAX];
        $this->doc->caret = $this->doc->clamp($end);

        return $this->moved();
    }

    // ----------------------------------------------------------- Raven API

    public function setText(string $text): static
    {
        return $this->setValue($text);
    }

    public function text(): string
    {
        return $this->value();
    }

    public function length(): int
    {
        return strlen($this->value());
    }

    public function line(int $line): ?string
    {
        return $this->doc->lines[$line] ?? null;
    }

    /** Inserts at the caret over the selection, as one step of the history (code: no mail, as the twin says). */
    public function insertText(string $text): static
    {
        $this->doc->breakTyping();
        $this->doc->insert($text);
        $this->doc->breakTyping();
        $this->changed();

        return $this->moved();
    }

    /** @return array{int, int} */
    public function cursor(): array
    {
        return $this->doc->caret;
    }

    public function setCursor(int $line, int $column): static
    {
        $this->doc->caret = $this->doc->anchor = $this->doc->clamp([$line, $column]);
        $this->doc->breakTyping();

        return $this->moved();
    }

    public function select(int $fromLine, int $fromColumn, int $toLine, int $toColumn): static
    {
        $this->doc->anchor = $this->doc->clamp([$fromLine, $fromColumn]);
        $this->doc->caret = $this->doc->clamp([$toLine, $toColumn]);
        $this->doc->breakTyping();

        return $this->moved();
    }

    public function selectAll(): static
    {
        $this->doc->anchor = [0, 0];
        $this->doc->caret = $this->doc->end();
        $this->doc->breakTyping();

        return $this->invalidatePaint();
    }

    /** @return array{array{int, int}, array{int, int}} ordered */
    public function selection(): array
    {
        return [$this->doc->selMin(), $this->doc->selMax()];
    }

    public function selectedText(): string
    {
        return $this->doc->range($this->doc->selMin(), $this->doc->selMax());
    }

    public function undo(): static
    {
        if (! $this->readOnly && $this->doc->undo()) {
            $this->changed();
            $this->moved();
        }

        return $this;
    }

    public function redo(): static
    {
        if (! $this->readOnly && $this->doc->redo()) {
            $this->changed();
            $this->moved();
        }

        return $this;
    }

    public function canUndo(): bool
    {
        return $this->doc->canUndo() && ! $this->readOnly;
    }

    public function canRedo(): bool
    {
        return $this->doc->canRedo() && ! $this->readOnly;
    }

    /** Raven's name for the tab width; 1 to 16 (text_editor.c:2699-2707). */
    public function setTabSize(int $spaces): static
    {
        if ($spaces < 1 || $spaces > 16) {
            throw new InvalidArgumentException("The tab size is between 1 and 16, got {$spaces}.");
        }

        return $this->setTabWidth($spaces);
    }

    /** Tab writes spaces up to the next stop instead of a tab character. */
    public function setTabInsertsSpaces(bool $on = true): static
    {
        $this->tabSpaces = $on;

        return $this;
    }

    /** Brackets and quotes typed in pairs, Enter indenting after them (on by default; needs a language). */
    public function setAutoPairs(bool $on = true): static
    {
        $this->pairs = $on;

        return $this;
    }

    /** The language of the text, by name, alias or file extension (Syntax::find); null: none. */
    public function setLanguage(?string $name): static
    {
        $this->language = Syntax::find($name);
        $this->lexIn = [0];
        $this->lexValid = 1;

        return $this->invalidatePaint();
    }

    public function language(): ?string
    {
        return $this->language?->name;
    }

    /** Markdown as it is written (Raven Rtext_editor_set_markdown); false removes the language. */
    public function setMarkdown(bool $on = true): static
    {
        return $this->setLanguage($on ? 'Markdown' : null);
    }

    public function isMarkdown(): bool
    {
        return $this->language?->is(Language::FORMATTED_TEXT) ?? false;
    }

    /** The kind of token at a position, null for plain text (Rtext_editor_get_token). */
    public function token(int $line, int $column): ?SyntaxToken
    {
        if ($this->language === null || ! isset($this->doc->lines[$line])) {
            return null;
        }
        foreach ($this->spans($line) as [$start, $end, $token]) {
            if ($column >= $start && $column < $end) {
                return $token;
            }
        }

        return null;
    }

    public function addMarker(int $line, int $fromColumn, int $toColumn, MarkerKind $kind, string $message = ''): static
    {
        if (isset($this->doc->lines[$line])) {
            $this->doc->markers[] = ['line' => $line, 'from' => max(0, $fromColumn), 'to' => $toColumn, 'kind' => $kind, 'message' => $message];
        }

        return $this->invalidatePaint();
    }

    public function clearMarkers(): static
    {
        $this->doc->markers = [];

        return $this->invalidatePaint();
    }

    public function markerCount(): int
    {
        return count($this->doc->markers);
    }

    /** Copy, cut and paste go through this clipboard; without one they do nothing. */
    public function setClipboard(?Clipboard $clipboard): static
    {
        $this->clipboard = $clipboard;

        return $this;
    }

    // ---------------------------------------------------------- proposals

    /** False stops the editor proposing words by itself; Ctrl+Space still does. On by default. */
    public function setCompletion(bool $on = true): static
    {
        $this->completion = $on;
        $on || $this->completeClose();

        return $this;
    }

    /** Proposes words for what is before the caret, as Ctrl+Space does. */
    public function showCompletions(): static
    {
        $this->completeUpdate(1);

        return $this;
    }

    public function isCompleting(): bool
    {
        return $this->complete?->isOpen() ?? false;
    }

    public function completions(): ?CompletionList
    {
        return $this->complete;
    }

    /**
     * Called each time the editor gathers its proposals, with the start of the word typed: add your own with
     * addCompletion() (Raven RCompletionCallback). They are listed before the others.
     *
     * @param  (\Closure(string, TextEditor): void)|null  $callback
     */
    public function setCompletionCallback(?\Closure $callback): static
    {
        $this->completionCallback = $callback;

        return $this;
    }

    /** Adds a proposal; only from a completion callback. One that does not continue the word typed is left out. */
    public function addCompletion(string $text, ?string $detail = null): static
    {
        if (! $this->collecting || $this->complete === null) {
            throw new \LogicException('addCompletion: only from a completion callback.');
        }
        $list = $this->complete;
        $typed = substr($this->doc->lines[$list->start[0]], $list->start[1], $list->prefixLength);
        if ($text !== '' && strlen($text) >= $list->prefixLength && strncasecmp($text, $typed, $list->prefixLength) === 0) {
            $list->addItem($text, $detail === '' ? null : $detail, 0);
        }

        return $this;
    }

    private static function isWordChar(string $c): bool
    {
        return ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80;
    }

    private function completeClose(): void
    {
        $this->complete?->isOpen() && $this->complete->close();
    }

    /** Puts the chosen word in place of what was typed of it (rvn_ed_complete_accept, text_editor.c:1679-1696). */
    private function completeAccept(int $index): void
    {
        $list = $this->complete;
        $text = $list?->items[$index]['text'] ?? null;
        $this->completeClose();
        $doc = $this->doc;
        if ($text === null || $list->start[0] !== $doc->caret[0] || $list->start[1] > $doc->caret[1] || $this->readOnly) {
            return;
        }
        $doc->breakTyping();
        $doc->caret = $doc->anchor = $doc->replace($list->start, $doc->caret, $text, true);
        $doc->breakTyping();
        $this->moved();
        $this->notify();
    }

    /**
     * Gathers the proposals for the word before the caret and shows them, or hides the list when there are none
     * (rvn_ed_complete_update, text_editor.c:1796-1890). Mode 0: a character was typed (two needed); 1: asked
     * for (Ctrl+Space), any start; 2: the list is shown and the word changed.
     */
    private function completeUpdate(int $mode): void
    {
        $doc = $this->doc;
        $line = $doc->lines[$doc->caret[0]];
        if ($this->tree() === null || $this->readOnly || $doc->hasSelection() || ($mode === 0 && (! $this->completion || $this->language === null))) {
            $this->completeClose();

            return;
        }
        $start = $doc->caret[1];
        while ($start > 0 && self::isWordChar($line[$start - 1])) {
            $start--;
        }
        $length = $doc->caret[1] - $start;
        if ($length >= 128 || ($mode === 0 && $length < 2) || ($mode === 2 && $length < 1) || ($length > 0 && ctype_digit($line[$start]))) {
            $this->completeClose();

            return;
        }
        $prefix = substr($line, $start, $length);
        $list = $this->complete ??= new CompletionList($this->name().'.completion', fn (int $i) => $this->completeAccept($i));
        // another word than the one the list was shown for: it moves there
        if ($list->isOpen() && $list->start !== [$doc->caret[0], $start]) {
            $list->close();
        }
        $list->items = [];
        $list->start = [$doc->caret[0], $start];
        $list->prefixLength = $length;
        if ($this->completionCallback !== null) {
            $this->collecting = true;
            try {
                ($this->completionCallback)($prefix, $this);
            } finally {
                $this->collecting = false;
            }
        }
        foreach ($this->language === null ? [] : ['keyword', 'type', 'constant'] as $k => $detail) {
            foreach ($this->language->words($k) as $word) {
                if (strlen($word) > $length && strncasecmp($word, $prefix, $length) === 0) {
                    $list->addItem($word, $detail, strncmp($word, $prefix, $length) === 0 ? 1 : 2);
                }
            }
        }
        $length > 0 && $this->completeDocument($list, $prefix);
        $list->finish();
        if ($list->items === []) {
            $this->completeClose();

            return;
        }
        if (! $list->isOpen()) {
            [, , $area] = $this->update();
            [$x, $y] = $this->pointOf($list->start);
            $lineHeight = $this->rows->lineHeight;
            $list->in($this->tree())->openAt($area->x + $x - $this->hbar->offset - 6.0, $area->y + $y - $this->vbar->offset + $lineHeight + 3.0);
        }
    }

    /** The names of the text that start so, from the lines around the caret (rvn_ed_complete_document, text_editor.c:1767-1794). */
    private function completeDocument(CompletionList $list, string $prefix): void
    {
        $doc = $this->doc;
        $length = strlen($prefix);
        $last = min($doc->caret[0] + 4000, count($doc->lines) - 1);
        for ($line = max($doc->caret[0] - 4000, 0); $line <= $last && count($list->items) < CompletionList::MAX_ITEMS; $line++) {
            $text = $doc->lines[$line];
            $n = strlen($text);
            $i = 0;
            while ($i < $n) {
                if (! self::isWordChar($text[$i])) {
                    $i++;

                    continue;
                }
                $start = $i;
                while ($i < $n && self::isWordChar($text[$i])) {
                    $i++;
                }
                // long enough to be worth choosing, not a number, and not the word being typed itself
                if ($i - $start < 3 || $i - $start <= $length || ctype_digit($text[$start]) || ($line === $doc->caret[0] && $start === $list->start[1])) {
                    continue;
                }
                $word = substr($text, $start, $i - $start);
                if (strncasecmp($word, $prefix, $length) === 0) {
                    $list->addItem($word, null, strncmp($word, $prefix, $length) === 0 ? 1 : 2);
                }
            }
        }
    }

    // ------------------------------------------------------------- lexing

    /** @return list<array{int, int, SyntaxToken}> the spans of a line, its start state carried from the line before */
    protected function spans(int $line): array
    {
        $this->lexUntil($line);
        $spans = [];
        Syntax::line($this->language, $this->doc->lines[$line], $this->lexIn[$line], $spans);

        return $spans;
    }

    /** rvn_ed_lex_until (text_editor.c:798-809). */
    private function lexUntil(int $line): void
    {
        if ($this->doc->changedFrom !== null) {
            $this->lexValid = min($this->lexValid, $this->doc->changedFrom + 1);
            array_splice($this->lexIn, $this->lexValid);
            $this->doc->changedFrom = null;
        }
        while ($this->lexValid <= $line && $this->lexValid < count($this->doc->lines)) {
            $previous = $this->lexValid - 1;
            $this->lexIn[$this->lexValid] = Syntax::line($this->language, $this->doc->lines[$previous], $this->lexIn[$previous]);
            $this->lexValid++;
        }
    }

    /** Not inside a comment or a text (rvn_ed_is_code, text_editor.c:816-830). */
    private function isCode(int $line, int $column): bool
    {
        if ($this->language === null) {
            return true;
        }
        foreach ($this->spans($line) as [$start, $end, $token]) {
            if ($column >= $start && $column < $end) {
                return $token !== SyntaxToken::Comment && $token !== SyntaxToken::String;
            }
        }

        return true;
    }

    private function pairsLanguage(): ?Language
    {
        return $this->pairs ? $this->language : null;
    }

    // -------------------------------------------------- help while typing

    private function indentUnit(): string
    {
        return $this->tabSpaces ? str_repeat(' ', max(1, min(16, $this->tabWidth))) : "\t";
    }

    private static function closer(string $c): string
    {
        return match ($c) {
            '(' => ')', '[' => ']', '{' => '}', '"' => '"', "'" => "'", '`' => '`',
            default => '',
        };
    }

    /** Is a quote one the language writes its texts with? (text_editor.c:1335-1351) */
    private static function isQuote(Language $l, string $c): bool
    {
        if ($c !== '"' && $c !== "'" && $c !== '`') {
            return false;
        }
        if ($l->is(Language::MARKUP)) {
            return $c !== '`';
        }
        // (an apostrophe is rarely the start of a text where it holds a single character)
        if ($c === "'" && $l->is(Language::CHAR_LITERALS)) {
            return false;
        }
        if (str_contains($l->stringQuotes, $c)) {
            return true;
        }
        foreach ($l->multiline as [$open]) {
            if ($open === $c) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enter: the new line takes the indentation of the one it comes from, one level deeper after an opening
     * bracket (a colon in Python, an opening tag); between a bracket and its closer the closer goes down to a
     * line of its own (rvn_ed_new_line, text_editor.c:1287-1333).
     */
    private function newLine(): void
    {
        $from = $this->doc->selMin();
        $line = $this->doc->lines[$from[0]];
        $desc = $this->pairsLanguage();
        $unit = $this->indentUnit();
        $indent = min(strspn($line, " \t"), $from[1], 128);
        $deeper = $split = false;
        if ($desc !== null && ! $this->doc->hasSelection()) {
            $last = $from[1];
            while ($last > 0 && ($line[$last - 1] === ' ' || $line[$last - 1] === "\t")) {
                $last--;
            }
            $before = $last > 0 ? $line[$last - 1] : '';
            $after = $from[1] < strlen($line) ? $line[$from[1]] : '';
            if ($before === '{' || $before === '[' || $before === '(') {
                $deeper = true;
                $split = $after !== '' && $after === self::closer($before);
            } elseif ($before === ':' && $desc->is(Language::INDENT_ON_COLON)) {
                $deeper = true;
            } elseif ($before === '>' && $desc->is(Language::MARKUP) && $last >= 2 && $line[$last - 2] !== '/') {
                // after an opening tag, not after a closing one
                $open = $last - 2;
                while ($open > 0 && $line[$open] !== '<') {
                    $open--;
                }
                if ($line[$open] === '<' && ! in_array($line[$open + 1] ?? '', ['/', '!', '?'], true)) {
                    $deeper = true;
                    $split = $after === '<' && ($line[$from[1] + 1] ?? '') === '/';
                }
            }
        }
        $lead = substr($line, 0, $indent);
        $text = "\n".$lead.($deeper ? $unit : '').($split ? "\n".$lead : '');
        $this->doc->breakTyping();
        $this->doc->insert($text);
        $this->doc->breakTyping();
        if ($split) {
            $this->doc->caret = $this->doc->anchor = [$from[0] + 1, $indent + strlen($unit)];
        }
    }

    /** A character typed: with a language, brackets and quotes come in pairs (rvn_ed_type, text_editor.c:1363-1450). */
    private function type(string $char): void
    {
        $desc = $this->pairsLanguage();
        $doc = $this->doc;
        $line = $doc->lines[$doc->caret[0]];
        $column = $doc->caret[1];
        $selection = $doc->hasSelection();
        $c = strlen($char) === 1 ? $char : '';
        $next = ! $selection && $column < strlen($line) ? $line[$column] : '';
        $previous = ! $selection && $column > 0 ? $line[$column - 1] : '';
        $closer = self::closer($c);
        $quote = $desc !== null && $c !== '' && self::isQuote($desc, $c);
        $room = $next === '' || $next === ' ' || $next === "\t" || str_contains(')]}>,;', $next);

        if ($desc === null || $c === '') {
            $doc->insert($char);

            return;
        }
        // the closing character is already there: step over it
        if (! $selection && $next === $c && (str_contains(')]}', $c) || $quote)) {
            $doc->caret = $doc->anchor = [$doc->caret[0], $column + 1];

            return;
        }
        if ($closer !== '' && ($quote || str_contains('([{', $c))) {
            if ($selection) {
                // around what is selected
                $doc->breakTyping();
                $doc->insert($c.$this->selectedText().$closer);
                $doc->breakTyping();

                return;
            }
            // in a comment or in a text, a bracket is only a character
            if ($room && $this->isCode($doc->caret[0], max($column - 1, 0))
                && (! $quote || (EditorText::charClass($previous ?: ' ') !== 1 && $previous !== $c && $previous !== '\\'))) {
                $doc->breakTyping();
                $doc->insert($c.$closer);
                $doc->breakTyping();
                $doc->caret = $doc->anchor = [$doc->caret[0], $doc->caret[1] - 1];

                return;
            }
        }
        if ($c === '}' && ! $selection && $column > 0 && strspn($line, " \t") >= $column) {
            // alone on its line, a closing brace comes back one level
            $cut = $line[$column - 1] === "\t" ? 1 : min($this->tabWidth, strspn(strrev(substr($line, 0, $column)), ' '));
            $doc->breakTyping();
            $doc->caret = $doc->anchor = $doc->replace([$doc->caret[0], $column - $cut], $doc->caret, '}', true);
            $doc->breakTyping();

            return;
        }
        if ($c === '>' && ! $selection && $desc->is(Language::MARKUP) && ! in_array($previous, ['/', '-', '?'], true)) {
            // the end of an opening tag: its closing tag comes with it
            $open = $column - 1;
            while ($open >= 0 && $line[$open] !== '<' && $line[$open] !== '>') {
                $open--;
            }
            if ($open >= 0 && $line[$open] === '<' && $open + 1 < $column && EditorText::charClass($line[$open + 1]) === 1) {
                $nameEnd = $open + 1;
                while ($nameEnd < $column && $line[$nameEnd] !== ' ' && $line[$nameEnd] !== "\t") {
                    $nameEnd++;
                }
                $name = substr($line, $open + 1, $nameEnd - $open - 1);
                if (! ($desc->is(Language::EMBEDS) && self::isVoidTag($name)) && $nameEnd - $open < 80) {
                    $caret = $doc->caret;
                    $doc->breakTyping();
                    $doc->insert("></{$name}>");
                    $doc->breakTyping();
                    $doc->caret = $doc->anchor = [$caret[0], $caret[1] + 1];

                    return;
                }
            }
        }
        $doc->insert($char);
    }

    private static function isVoidTag(string $name): bool
    {
        return in_array(strtolower($name), ['br', 'hr', 'img', 'input', 'meta', 'link', 'area', 'base', 'col', 'embed', 'source', 'track', 'wbr'], true);
    }

    /** Is the caret between an opening bracket or quote and its closer, nothing between? (text_editor.c:1903-1912) */
    private function betweenPair(): bool
    {
        $desc = $this->pairsLanguage();
        $line = $this->doc->lines[$this->doc->caret[0]];
        $c = $this->doc->caret[1];
        if ($desc === null || $c <= 0 || $c >= strlen($line)) {
            return false;
        }
        $before = $line[$c - 1];
        if (self::closer($before) === '' || self::closer($before) !== $line[$c]) {
            return false;
        }

        return str_contains('([{', $before) || self::isQuote($desc, $before);
    }

    /** Tab and Shift+Tab over several lines: one level in or out for each (text_editor.c:1205-1246). */
    private function indentLines(bool $outdent): void
    {
        $doc = $this->doc;
        [$from, $to] = [$doc->selMin(), $doc->selMax()];
        [$first, $last] = [$from[0], $to[0]];
        // a selection that ends at the start of a line does not include that line
        if ($last > $first && $to[1] === 0) {
            $last--;
        }
        $unit = $this->indentUnit();
        $out = [];
        for ($line = $first; $line <= $last; $line++) {
            $l = $doc->lines[$line];
            if ($outdent) {
                $skip = ($l[0] ?? '') === "\t" ? 1 : min($this->tabWidth, strspn($l, ' '));
                $out[] = substr($l, $skip);
            } else {
                $out[] = ($l !== '' ? $unit : '').$l;
            }
        }
        $doc->breakTyping();
        $doc->replace([$first, 0], [$last, strlen($doc->lines[$last])], implode("\n", $out), true);
        $doc->breakTyping();
        // the lines stay selected, to indent again
        $doc->anchor = [$first, 0];
        $doc->caret = $last + 1 < count($doc->lines) && $to[1] === 0 && $to[0] > $first ? [$last + 1, 0] : [$last, strlen($doc->lines[$last])];
    }

    /** Ctrl+/: the selected lines commented out, or back in when they all are (text_editor.c:1452-1513). */
    private function toggleComment(): bool
    {
        $mark = $this->language?->lineComment;
        if ($mark === null) {
            return false;
        }
        $doc = $this->doc;
        [$from, $to] = [$doc->selMin(), $doc->selMax()];
        [$first, $last] = [$from[0], $to[0]];
        if ($last > $first && $to[1] === 0) {
            $last--;
        }
        $min = PHP_INT_MAX;
        $all = true;
        $any = false;
        for ($line = $first; $line <= $last; $line++) {
            $l = $doc->lines[$line];
            $indent = strspn($l, " \t");
            if ($indent === strlen($l)) {
                continue;
            }
            $any = true;
            $min = min($min, $indent);
            $all = $all && substr($l, $indent, strlen($mark)) === $mark;
        }
        if (! $any) {
            return true;
        }
        $out = [];
        for ($line = $first; $line <= $last; $line++) {
            $l = $doc->lines[$line];
            $indent = strspn($l, " \t");
            if ($indent === strlen($l)) {
                $out[] = $l;
            } elseif ($all) {
                $skip = $indent + strlen($mark);
                $skip += ($l[$skip] ?? '') === ' ' ? 1 : 0;
                $out[] = substr($l, 0, $indent).substr($l, $skip);
            } else {
                // the marks line up, at the indentation of the least indented line
                $out[] = substr($l, 0, $min).$mark.' '.substr($l, $min);
            }
        }
        $doc->breakTyping();
        $doc->replace([$first, 0], [$last, strlen($doc->lines[$last])], implode("\n", $out), true);
        $doc->breakTyping();
        if ($first === $last && EditorText::cmp($from, $to) === 0) {
            $doc->caret = $doc->anchor = $doc->clamp([$first, $from[1] + ($all ? -(strlen($mark) + 1) : strlen($mark) + 1)]);
        } else {
            $doc->anchor = [$first, 0];
            $doc->caret = [$first === $last ? $first : $last, strlen($doc->lines[$last])];
        }

        return true;
    }

    /** Tab with no lines selected: a tab, or spaces up to the next stop (text_editor.c:2140-2165). */
    private function tab(): void
    {
        $this->doc->breakTyping();
        if (! $this->tabSpaces) {
            $this->doc->insert("\t");

            return;
        }
        $from = $this->doc->selMin();
        $count = max(1, min(16, $this->tabWidth));
        $columns = 0;
        // the column on screen: a tab before the caret counts for what it shows
        foreach (mb_str_split(substr($this->doc->lines[$from[0]], 0, $from[1])) as $ch) {
            $columns += $ch === "\t" ? $count - $columns % $count : 1;
        }
        $this->doc->insert(str_repeat(' ', $count - $columns % $count));
    }

    // ------------------------------------------------------------ view

    /** text_editor.c:19-20 */
    public const float PAD_X = 8.0;

    public const float PAD_Y = 6.0;

    /** text_editor.c:1954-1956 */
    public const float ZOOM_MIN = 0.5;

    public const float ZOOM_MAX = 4.0;

    public const float ZOOM_STEP = 1.1;

    /** The rows and the place of the text: [rows, gutter width, text area (tree coordinates), bar size] (rvn_ed_metrics). */
    protected function view(): array
    {
        $rows = $this->rows ??= new EditorRows($this->measurer());
        $f = $this->editorFont();
        $font = new FontSpec($f->family, $f->size * $this->zoom, $f->weight, $f->italic);
        $rows->setFont($font, $this->tabWidth);
        $bar = $this->look()->metric(Metric::ScrollbarSize);
        $gutter = 0.0;
        if ($this->lineNumbers) {
            $digits = max(2, strlen((string) $this->lineCount()));
            $gutter = ceil($this->measurer()->measure('0', $font)->w * $digits + 18.0);
        }
        $b = $this->bounds();
        $x = $gutter + self::PAD_X;
        // with word wrap the room of the scrollbar is always kept: the text does not flow again when it appears
        $w = max(0.0, $b->w - $x - self::PAD_X - (($this->wrap || $this->vbar->needed()) ? $bar : 0.0));
        $h = max(0.0, $b->h - self::PAD_Y * 2 - ((! $this->wrap && $this->hbar->needed()) ? $bar : 0.0));

        return [$rows, $gutter, new Rect($b->x + $x, $b->y + self::PAD_Y, $w, $h), $bar];
    }

    /** The rows and the bars up to date; twice, as the bars take room (rvn_text_editor_arrange). */
    protected function update(): array
    {
        for ($pass = 0; $pass < 2; $pass++) {
            [$rows, , $area] = $view = $this->view();
            $rows->update($this->doc, $this->wrap, $area->w);
            $this->vbar->content = $rows->totalRows * $rows->lineHeight;
            $this->vbar->viewport = $area->h;
            $this->vbar->clamp();
            // room for the caret after the longest line
            $this->hbar->content = $this->wrap ? 0.0 : $rows->maxWidth + $rows->space * 2.0;
            $this->hbar->viewport = $area->w;
            $this->hbar->clamp();
        }

        return $view;
    }

    /** Where a row starts across in the text area: the twin's alignment of a row narrower than the view. */
    private function shift(EditorRows $rows, float $width, int $line, int $row): float
    {
        if ($this->alignment === TextAlignment::LEFT) {
            return 0.0;
        }
        [$begin, $end] = $rows->rowRange($this->doc, $line, $row);
        $room = max(0.0, $width - $rows->advance($this->doc->lines[$line], $begin, $end));

        return $this->alignment === TextAlignment::CENTER ? floor($room / 2) : $room;
    }

    /** @return array{float, float} a position's place in the text's coordinates, alignment included */
    protected function pointOf(array $pos): array
    {
        [$rows, , $area] = $this->update();
        [$x, $y] = $rows->point($this->doc, $pos);

        return [$x + $this->shift($rows, $area->w, $pos[0], $rows->rowOf($pos[0], $pos[1])), $y];
    }

    /** @return array{int, int} the position at a point of the text's coordinates */
    protected function positionAt(float $x, float $y): array
    {
        [$rows, , $area] = $this->update();
        [$line, $row] = $rows->locate((int) max(0, floor($y / $rows->lineHeight)), $this->lineCount());

        return $rows->position($this->doc, $x - $this->shift($rows, $area->w, $line, $row), $y);
    }

    /** Up or down by $rows rows, keeping the horizontal place (rvn_ed_move_rows, text_editor.c:1176-1183). */
    protected function moveRows(array $pos, int $rows): array
    {
        [$r] = $this->update();
        [$x] = $this->pointOf($pos);
        $global = $r->firstRow($pos[0]) + $r->rowOf($pos[0], $pos[1]) + $rows;
        $this->goalX ??= $x;
        if ($global < 0) {
            return [0, 0];
        }
        if ($global >= $r->totalRows) {
            return $this->doc->end();
        }

        return $this->positionAt($this->goalX, ($global + 0.5) * $r->lineHeight);
    }

    /** @return array{int, int} the bytes of the row of a position */
    protected function rowRange(int $line, int $column): array
    {
        [$rows] = $this->update();

        return $rows->rowRange($this->doc, $line, $rows->rowOf($line, $column));
    }

    /** The last place of a row: before its cut when the line goes on (text_editor.c:2112-2125). */
    protected function rowEnd(int $line, int $column): int
    {
        [$rows] = $this->update();
        $row = $rows->rowOf($line, $column);
        [$begin, $end] = $rows->rowRange($this->doc, $line, $row);
        if ($this->wrap && $row + 1 < $rows->rowCount($line) && $end > $begin) {
            $end = EditorRows::previous($this->doc->lines[$line], $end);
        }

        return $end;
    }

    protected function pageRows(): int
    {
        [$rows, , $area] = $this->update();

        return max((int) ($area->h / $rows->lineHeight) - 1, 1);
    }

    /** Text with a language is code: it lines up better in the monospace face (text_editor.c:492-496). */
    protected function editorFont(): FontSpec
    {
        return $this->font ?? $this->textStyle($this->language !== null ? FontRole::Mono : FontRole::Ui);
    }

    /** Scrolls as little as needed to show the caret (rvn_ed_reveal, text_editor.c:728-751). */
    private function reveal(): void
    {
        if ($this->bounds()->w <= 0 || $this->bounds()->h <= 0) {
            $this->revealPending = true;

            return;
        }
        [$rows] = $this->update();
        [$x, $y] = $this->pointOf($this->doc->caret);
        if ($y < $this->vbar->offset) {
            $this->vbar->set($y);
        } elseif ($y + $rows->lineHeight > $this->vbar->offset + $this->vbar->viewport) {
            $this->vbar->set($y + $rows->lineHeight - $this->vbar->viewport);
        }
        if (! $this->wrap) {
            if ($x < $this->hbar->offset + $rows->space) {
                $this->hbar->set($x - $rows->space * 4.0);
            } elseif ($x > $this->hbar->offset + $this->hbar->viewport - $rows->space * 2.0) {
                $this->hbar->set($x - $this->hbar->viewport + $rows->space * 6.0);
            }
        }
        $this->revealPending = false;
    }

    /** @return array{Rect, Rect} the vertical and horizontal tracks (rvn_ed_tracks) */
    private function tracks(float $gutter, float $bar): array
    {
        $b = $this->bounds();
        $hNeeded = ! $this->wrap && $this->hbar->needed();

        return [
            new Rect($b->x + $b->w - $bar - 1, $b->y + 1, $bar, $b->h - 2 - ($hNeeded ? $bar : 0.0)),
            new Rect($b->x + $gutter + 1, $b->y + $b->h - $bar - 1, $b->w - $gutter - 2 - ($this->vbar->needed() ? $bar : 0.0), $bar),
        ];
    }

    public function setLineNumbers(bool $on = true): static
    {
        $this->lineNumbers = $on;

        return $this->invalidatePaint();
    }

    public function setPlaceholder(?string $text): static
    {
        $this->placeholder = $text;

        return $this->invalidatePaint();
    }

    /** Ctrl + wheel and Ctrl + '+', '-', '0' zoom the text (on by default). */
    public function setZoomable(bool $on = true): static
    {
        $this->zoomable = $on;

        return $this;
    }

    public function zoom(): float
    {
        return $this->zoom;
    }

    /** From the program: the top of the view stays where it is and nobody is told (text_editor.c:2856-2866). */
    public function setZoom(float $zoom): static
    {
        [, , $area] = $this->update();
        $this->zoomAround($zoom <= 0.0 ? 1.0 : $zoom, $area->x, $area->y);

        return $this;
    }

    /** The user zoomed: around $x, $y, and the mail says so (RTextEditorZoomCallback). */
    private function userZoom(float $zoom, float $x, float $y): void
    {
        if ($this->zoomAround($zoom, $x, $y)) {
            $this->completeClose();
            $this->post(new ZoomChanged($this->window(), $this->path(), $this->uuid(), $this->zoom));
        }
    }

    /** @return array{float, float} the caret's place, else the middle of the view (rvn_ed_zoom_key) */
    private function caretAnchor(): array
    {
        [, , $area] = $this->update();
        [$x, $y] = $this->pointOf($this->doc->caret);
        $x = $area->x + $x - $this->hbar->offset;
        $y = $area->y + $y - $this->vbar->offset;

        return $y < $area->y || $y > $area->y + $area->h ? [$area->x, $area->y + $area->h * 0.5] : [$x, $y];
    }

    /**
     * Zooms the text, the point ($ax, $ay) staying over the same text (rvn_ed_set_zoom, text_editor.c:1965-2014).
     * True when the zoom changed.
     */
    private function zoomAround(float $zoom, float $ax, float $ay): bool
    {
        $zoom = max(self::ZOOM_MIN, min(self::ZOOM_MAX, $zoom));
        // (back to 100 % when it passes near it: the wheel finds it again)
        if (abs($zoom - 1.0) < 0.03) {
            $zoom = 1.0;
        }
        $zoom = floor($zoom * 100.0 + 0.5) / 100.0;
        if (abs($zoom - $this->zoom) < 0.001) {
            return false;
        }
        [$before, , $area] = $this->update();
        $ax = max(0.0, min($area->w, $ax - $area->x));
        $ay = max(0.0, min($area->h, $ay - $area->y));
        // what is under the anchor: a place in the text, and where in its row (rows change with the size)
        $tx = $this->hbar->offset + $ax;
        $ty = $this->vbar->offset + $ay;
        $pos = $this->positionAt($tx, $ty);
        [$px, $py] = $this->pointOf($pos);
        $fy = ($ty - $py) / $before->lineHeight;
        $fx = ($tx - $px) / $before->font->size;
        $this->zoom = $zoom;
        [$after] = $this->update();
        [$px, $py] = $this->pointOf($pos);
        $this->vbar->set($py + $fy * $after->lineHeight - $ay);
        $this->wrap || $this->hbar->set($px + $fx * $after->font->size - $ax);
        // no glide from where things were at the other size
        $this->vbar->shown->snap($this->vbar->offset);
        $this->hbar->shown->snap($this->hbar->offset);
        $this->caretPlaced = false;
        $this->invalidatePaint();

        return true;
    }

    // ------------------------------------------------- TK twin: scrolling

    /** The visible rect's top-left and size as fractions of the content. */
    public function scrollPosition(): ScrollPosition
    {
        $this->update();

        return ScrollPosition::of($this->hbar->offset, $this->hbar->viewport, max($this->hbar->content, $this->hbar->viewport),
            $this->vbar->offset, $this->vbar->viewport, max($this->vbar->content, $this->vbar->viewport));
    }

    /** Scrolls to the top-left given as content fractions, kept inside the content (no mail: code). */
    public function scrollTo(float $x, float $y): static
    {
        $this->update();
        $this->hbar->set(ScrollPosition::offset($x, $this->hbar->viewport, max($this->hbar->content, $this->hbar->viewport)));
        $this->vbar->set(ScrollPosition::offset($y, $this->vbar->viewport, max($this->vbar->content, $this->vbar->viewport)));

        return $this->invalidatePaint();
    }

    /** The line at the top of the view, as near as the end of the text allows. */
    public function scrollToLine(int $line): static
    {
        $this->update();
        $this->vbar->set($this->pointOf($this->doc->clamp([$line, 0]))[1]);

        return $this->invalidatePaint();
    }

    /** ViewScrolled is posted for the user's scrolls while watched. */
    public function watchScroll(bool $on = true): static
    {
        $this->watchScroll = $on;

        return $this;
    }

    public function isWatchingScroll(): bool
    {
        return $this->watchScroll;
    }

    private function scrolled(): void
    {
        $this->invalidatePaint();
        $this->watchScroll && $this->post(new ViewScrolled($this->window(), $this->path(), $this->uuid(), $this->scrollPosition()));
    }

    // ---------------------------------------------------------- events

    public function handles(object $mail): bool
    {
        if (! $mail instanceof ClipboardText || $mail->id !== $this->pasteRequest) {
            return false;
        }
        $this->pasteRequest = null;
        if ($mail->text !== null && $mail->text !== '' && ! $this->readOnly && $this->isEnabled()) {
            $this->doc->breakTyping();
            $this->doc->insert($mail->text);
            $this->doc->breakTyping();
            $this->moved();
            $this->notify();
        }

        return true;
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return match ($e->type) {
            EventType::FocusGained => $this->focus(true),
            EventType::FocusLost => $this->focus(false),
            EventType::Text => $this->typed($e->text ?? ''),
            EventType::Edit => $e->command !== null && $this->command($e->command),
            EventType::KeyDown => $this->key($e),
            EventType::Wheel => $this->wheel($e),
            EventType::MouseDown, EventType::MouseMove, EventType::MouseUp => $this->mouse($e),
            EventType::Leave => $this->leave(),
            default => false,
        };
    }

    private function focus(bool $on): bool
    {
        $this->hasFocus = $on;
        if ($on) {
            $this->blink->start();
        } else {
            $this->blink->stop();
            $this->completeClose();
            $this->selecting = false;
            $this->doc->breakTyping();
        }
        $this->focusEase->target($on ? 1.0 : 0.0);
        $this->invalidatePaint();

        return false;
    }

    private function leave(): bool
    {
        $this->pointer = [-1.0, -1.0];
        $this->invalidatePaint();

        return false;
    }

    /** Ctrl + wheel zooms around the pointer; Shift + wheel scrolls sideways; at an end it bubbles (text_editor.c:2256-2273). */
    private function wheel(SilkEvent $e): bool
    {
        $this->completeClose();
        if ($e->modifiers->ctrl && $this->zoomable) {
            $e->wheelY != 0.0 && $this->userZoom($this->zoom * self::ZOOM_STEP ** $e->wheelY, $e->x, $e->y);

            return true;
        }
        [$rows] = $this->update();
        if ($e->modifiers->shift && ! $this->wrap) {
            $changed = $this->hbar->wheel($e->wheelY * $rows->space * 12.0);
        } else {
            $changed = $this->vbar->wheel($e->wheelY * $rows->lineHeight * 3.0);
            if ($e->wheelX != 0.0 && ! $this->wrap) {
                $changed = $this->hbar->wheel($e->wheelX * $rows->space * 12.0) || $changed;
            }
        }
        $changed ? $this->scrolled() : $this->invalidatePaint();

        return $changed;
    }

    /** The bars, then the selection by the mouse: a drag, a word, a line (text_editor.c:2274-2357). */
    private function mouse(SilkEvent $e): bool
    {
        [, $gutter, $area, $bar] = $this->update();
        $this->pointer = [$e->x, $e->y];
        if (! $this->selecting) {
            [$vertical, $horizontal] = $this->tracks($gutter, $bar);
            if ($this->vbar->event($e, $vertical, true) || (! $this->wrap && $this->hbar->event($e, $horizontal, false))) {
                $this->scrolled();

                return true;
            }
        }
        if ($e->type === EventType::MouseUp) {
            if ($e->button !== MouseButton::LEFT || ! $this->selecting) {
                return false;
            }
            $this->selecting = false;

            return true;
        }
        if ($e->type === EventType::MouseMove && ! $this->selecting) {
            $this->markerTip($area, $e->x, $e->y);
            $this->invalidatePaint();

            return false;
        }
        if ($e->type === EventType::MouseDown && $e->button !== MouseButton::LEFT) {
            return false;
        }
        $pos = $this->positionAt($e->x - $area->x + $this->hbar->offset, $e->y - $area->y + $this->vbar->offset);
        $doc = $this->doc;
        if ($e->type === EventType::MouseDown) {
            if ($e->clicks >= 3) {
                // the whole line, with its line break
                $doc->anchor = [$pos[0], 0];
                $doc->caret = $pos[0] + 1 < $this->lineCount() ? [$pos[0] + 1, 0] : [$pos[0], strlen($doc->lines[$pos[0]])];
            } elseif ($e->clicks === 2) {
                $line = $doc->lines[$pos[0]];
                [$begin, $end] = [$pos[1], $pos[1]];
                if ($line !== '') {
                    $begin = min($begin, strlen($line) - 1);
                    $kind = EditorText::charClass($line[$begin]);
                    $end = $begin;
                    while ($begin > 0 && EditorText::charClass($line[$begin - 1]) === $kind) {
                        $begin--;
                    }
                    while ($end < strlen($line) && EditorText::charClass($line[$end]) === $kind) {
                        $end++;
                    }
                }
                $doc->anchor = [$pos[0], $begin];
                $doc->caret = [$pos[0], $end];
            } else {
                $doc->caret = $pos;
                $e->modifiers->shift || $doc->anchor = $pos;
                $this->selecting = true;
            }
            $this->completeClose();
            $doc->breakTyping();
        } else {
            $doc->caret = $pos;
        }
        $this->goalX = null;
        $this->moved();

        return true;
    }

    /** The message of the marker under the pointer is the editor's tooltip (text_editor.c:2296-2316). */
    private function markerTip(Rect $area, float $x, float $y): void
    {
        $message = null;
        if ($this->doc->markers !== [] && $area->contains($x, $y)) {
            [$rows] = $this->update();
            $tx = $x - $area->x + $this->hbar->offset;
            $ty = $y - $area->y + $this->vbar->offset;
            $pos = $this->positionAt($tx, $ty);
            [$px, $py] = $this->pointOf($pos);
            // (the nearest position is not under the pointer past the end of the line, or below the text)
            if ($ty >= $py && $ty < $py + $rows->lineHeight && abs($tx - $px) <= $rows->space) {
                foreach ($this->doc->markers as $m) {
                    $to = $m['to'] < 0 ? strlen($this->doc->lines[$m['line']]) : $m['to'];
                    if ($m['line'] === $pos[0] && $pos[1] >= $m['from'] && $pos[1] <= max($to, $m['from'] + 1)) {
                        $message = $m['message'] !== '' ? $m['message'] : null;
                        break;
                    }
                }
            }
        }
        if ($message !== $this->tooltip()) {
            $this->setTooltip($message);
        }
    }

    /** Text events: each character typed (text_editor.c:2240-2256). */
    private function typed(string $text): bool
    {
        if (! $this->hasFocus || $this->readOnly) {
            return false;
        }
        $version = $this->doc->version;
        $caret = $this->doc->caret;
        foreach (mb_str_split($text) as $char) {
            if (mb_ord($char) < 32 || mb_ord($char) === 127) {
                continue;
            }
            $this->type($char);
        }
        $this->moved();
        ($this->doc->version !== $version) && $this->notify();
        // each character of a word narrows the proposals down; anything else ends the word
        $lastChar = mb_substr($text, -1);
        if ($lastChar !== '' && (strlen($lastChar) > 1 || self::isWordChar($lastChar))) {
            $this->completeUpdate($this->isCompleting() ? 2 : 0);
        } else {
            $this->completeClose();
        }

        return $this->doc->version !== $version || $this->doc->caret !== $caret;
    }

    /** Keys no EditCommand carries: Ctrl+/ (rvn_ed_key, text_editor.c:2056-2060). */
    private function key(SilkEvent $e): bool
    {
        $command = $e->modifiers->ctrl || $e->modifiers->meta;
        // Ctrl + '+' (or '='), '-', '0', also on the keypad: around the caret (rvn_ed_zoom_key)
        if ($command && ! $e->modifiers->alt && $this->zoomable) {
            $zoom = match ($e->key) {
                Key::EQUALS, Key::NUMPAD_PLUS, Key::NUMPAD_EQUALS => $this->zoom * self::ZOOM_STEP,
                Key::MINUS, Key::NUMPAD_MINUS => $this->zoom / self::ZOOM_STEP,
                Key::DIGIT_0, Key::NUMPAD_0 => 1.0,
                default => null,
            };
            if ($zoom !== null) {
                $this->userZoom($zoom, ...$this->caretAnchor());

                return true;
            }
        }
        if ($command && $e->key === Key::SPACE) {
            $this->completeUpdate(1);

            return true;
        }
        if ($command && $e->key === Key::SLASH) {
            if (! $this->readOnly && $this->toggleComment()) {
                $this->moved();
                $this->notify();
            }

            return true;
        }

        return false;
    }

    /** The platform's editing commands, as Raven's keys do them (rvn_ed_key, text_editor.c:2038-2221). */
    protected function command(EditCommand $c): bool
    {
        // while proposing, the list takes the keys that move in it or choose from it (rvn_edc_event)
        if ($this->isCompleting()) {
            $listKey = match ($c) {
                EditCommand::MoveUp => 'up',
                EditCommand::MoveDown => 'down',
                EditCommand::PageUp => 'pageUp',
                EditCommand::PageDown => 'pageDown',
                EditCommand::InsertNewline, EditCommand::InsertTab => 'accept',
                default => null,
            };
            if ($listKey !== null && $this->complete->key($listKey)) {
                return true;
            }
            if ($c === EditCommand::Cancel) {
                $this->completeClose();

                return true;
            }
        }
        $handled = $this->editCommand($c);
        // erasing narrows the proposals back; anything else is another matter
        if (in_array($c, [EditCommand::DeleteBackward, EditCommand::DeleteForward], true)) {
            $this->isCompleting() && $this->completeUpdate(2);
        } else {
            $this->completeClose();
        }

        return $handled;
    }

    private function editCommand(EditCommand $c): bool
    {
        $doc = $this->doc;
        $select = in_array($c, [EditCommand::SelectLeft, EditCommand::SelectRight, EditCommand::SelectWordLeft, EditCommand::SelectWordRight,
            EditCommand::SelectUp, EditCommand::SelectDown, EditCommand::SelectPageUp, EditCommand::SelectPageDown,
            EditCommand::SelectToLineStart, EditCommand::SelectToLineEnd, EditCommand::SelectToDocumentStart, EditCommand::SelectToDocumentEnd], true);
        switch ($c) {
            case EditCommand::MoveLeft:
            case EditCommand::SelectLeft:
                return $this->go(! $select && $doc->hasSelection() ? $doc->selMin() : $doc->charLeft($doc->caret), $select);
            case EditCommand::MoveRight:
            case EditCommand::SelectRight:
                return $this->go(! $select && $doc->hasSelection() ? $doc->selMax() : $doc->charRight($doc->caret), $select);
            case EditCommand::MoveWordLeft:
            case EditCommand::SelectWordLeft:
                return $this->go($doc->wordLeft($doc->caret), $select);
            case EditCommand::MoveWordRight:
            case EditCommand::SelectWordRight:
                return $this->go($doc->wordRight($doc->caret), $select);
            case EditCommand::MoveUp:
            case EditCommand::SelectUp:
                return $this->go($this->moveRows($doc->caret, -1), $select, true);
            case EditCommand::MoveDown:
            case EditCommand::SelectDown:
                return $this->go($this->moveRows($doc->caret, 1), $select, true);
            case EditCommand::PageUp:
            case EditCommand::SelectPageUp:
                return $this->go($this->moveRows($doc->caret, -$this->pageRows()), $select, true);
            case EditCommand::PageDown:
            case EditCommand::SelectPageDown:
                return $this->go($this->moveRows($doc->caret, $this->pageRows()), $select, true);
            case EditCommand::MoveToLineStart:
            case EditCommand::SelectToLineStart:
                // the start of the row, or the first character that is not a space when already there
                [$begin, $end] = $this->rowRange(...$doc->caret);
                $line = $doc->lines[$doc->caret[0]];
                $indent = $begin + strspn($line, " \t", $begin, $end - $begin);

                return $this->go([$doc->caret[0], $doc->caret[1] === $indent || $indent === $end ? $begin : $indent], $select);
            case EditCommand::MoveToLineEnd:
            case EditCommand::SelectToLineEnd:
                return $this->go([$doc->caret[0], $this->rowEnd(...$doc->caret)], $select);
            case EditCommand::MoveToDocumentStart:
            case EditCommand::SelectToDocumentStart:
                return $this->go([0, 0], $select);
            case EditCommand::MoveToDocumentEnd:
            case EditCommand::SelectToDocumentEnd:
                return $this->go($doc->end(), $select);
            case EditCommand::SelectAll:
                $this->selectAll();

                return true;
            case EditCommand::Copy:
                $this->copy();

                return true;
            case EditCommand::Cut:
                $this->copy();

                return $this->edit(fn () => $doc->hasSelection() && $this->deleteSelection());
            case EditCommand::Paste:
                if (! $this->readOnly && $this->clipboard !== null) {
                    $this->pasteRequest = $this->clipboard->requestText();
                }

                return true;
            case EditCommand::Undo:
                return $this->edit(fn () => $doc->undo());
            case EditCommand::Redo:
                return $this->edit(fn () => $doc->redo());
            case EditCommand::DeleteBackward:
            case EditCommand::DeleteForward:
            case EditCommand::DeleteWordBackward:
            case EditCommand::DeleteWordForward:
            case EditCommand::DeleteToLineEnd:
                return $this->edit(function () use ($c, $doc) {
                    if (! $doc->hasSelection()) {
                        $doc->anchor = match ($c) {
                            EditCommand::DeleteBackward => $doc->charLeft($doc->caret),
                            EditCommand::DeleteForward => $doc->charRight($doc->caret),
                            EditCommand::DeleteWordBackward => $doc->wordLeft($doc->caret),
                            EditCommand::DeleteWordForward => $doc->wordRight($doc->caret),
                            default => [$doc->caret[0], $this->rowEnd(...$doc->caret)],
                        };
                        if ($c === EditCommand::DeleteBackward && $this->betweenPair()) {
                            // a pair with nothing in it goes away as it came: together
                            $doc->caret = $doc->charRight($doc->caret);
                        }
                    }

                    return $this->deleteSelection();
                });
            case EditCommand::InsertNewline:
                return $this->edit(fn () => $this->newLine() ?? true);
            case EditCommand::InsertTab:
                return $this->edit(function () use ($doc) {
                    $doc->selMin()[0] !== $doc->selMax()[0] ? $this->indentLines(false) : $this->tab();

                    return true;
                });
            case EditCommand::InsertBacktab:
                return $this->edit(fn () => $this->indentLines(true) ?? true);
            default:
                return false;
        }
    }

    /** A change by the user; read-only takes the key and changes nothing. */
    private function edit(\Closure $change): bool
    {
        if ($this->readOnly) {
            return true;
        }
        $this->doc->breakTyping();
        if ($change()) {
            $this->moved();
            $this->notify();
        }

        return true;
    }

    private function deleteSelection(): bool
    {
        if (! $this->doc->hasSelection()) {
            return false;
        }
        $this->doc->insert('');

        return true;
    }

    private function copy(): void
    {
        $this->doc->hasSelection() && $this->clipboard?->setText($this->selectedText());
    }

    /** Moves the caret, extending the selection with Shift (rvn_ed_go). */
    private function go(array $target, bool $extend, bool $keepGoal = false): bool
    {
        $this->doc->caret = $this->doc->clamp($target);
        $extend || $this->doc->anchor = $this->doc->caret;
        $this->doc->breakTyping();
        $keepGoal || $this->goalX = null;
        $this->moved();

        return true;
    }

    /** The caret moved: scrolled into view and solid for a moment (rvn_ed_caret_moved). */
    protected function moved(): static
    {
        $this->blink->restart();
        $this->reveal();

        return $this->invalidatePaint();
    }

    /** The text changed: the rows of the changed lines are measured again at the next look. */
    protected function changed(): void
    {
        $this->invalidatePaint();
    }

    /** The user changed the text (rvn_ed_notify). */
    private function notify(): void
    {
        $this->changed();
        $this->post(new TextChanged($this->window(), $this->path(), $this->uuid(), $this->value()));
    }

    /** An editor is given its size by its parent; by itself it asks for a few lines (text_editor.c:763-770). */
    protected function measureContent(Size $available): Size
    {
        [$rows] = $this->view();

        return new Size(220.0, $rows->lineHeight * 5.0 + self::PAD_Y * 2);
    }

    public function springs(): array
    {
        return [...$this->vbar->springs(), ...$this->hbar->springs(), $this->caretX, $this->caretY, $this->focusEase, $this->blink];
    }

    public function settleMotion(): void
    {
        parent::settleMotion();
        if ($this->revealPending) {
            $this->reveal();
        }
    }

    // --------------------------------------------------------------- paint

    /** rvn_text_editor_paint (text_editor.c:943-1123). */
    public function paint(Painter $p): void
    {
        [$rows, $gutter, $area, $bar] = $this->update();
        $this->revealPending && $this->reveal();
        $look = $this->look();
        $b = $this->bounds();
        $doc = $this->doc;
        $focused = $this->hasFocus;
        $hasSelection = $doc->hasSelection();
        [$selMin, $selMax] = [$doc->selMin(), $doc->selMax()];
        $text = $this->textColor ?? $this->color($this->isEnabled() ? Role::Text : Role::TextDisabled, $look);
        $selection = $look->color(Role::Selection);
        $focused || $selection = self::fade($selection, 0.55);
        $dim = $look->color(Role::TextPlaceholder);
        $focus = max(0.0, min(1.0, $this->focusEase->value()));
        $lh = $rows->lineHeight;
        $font = $rows->font;
        $ascent = $this->measurer()->metrics($font)['ascent'];

        // frame
        if ($look->material()->isGlass()) {
            // text_editor.c:968-973: a field of darker glass; the focus a 1.5 ring of the accent
            $r = min($look->metric(Metric::CornerRadius), 14.0);
            Surfaces::paint($p, $look, $b, $r, SurfaceKind::Input);
            if ($focus > 0.01) {
                $f = $look->color(Role::Focus);
                $p->rect($b, new Color(0, 0, 0, 0), $r, new Color($f->red, $f->green, $f->blue, $f->alpha * $focus), 1.5);
            }
        } else {
            $p->rect($b, $look->color(Role::Input), $look->metric(Metric::CornerRadius),
                LookBlend::mix($look->color(Role::Border), $look->color(Role::Focus), $focus), $look->metric(Metric::BorderWidth));
        }

        // whole pixels: the text stays sharp while it scrolls
        $scale = max(1.0, $p->scale());
        $scrollY = floor($this->vbar->drawn() * $scale + 0.5) / $scale;
        $scrollX = $this->wrap ? 0.0 : floor($this->hbar->drawn() * $scale + 0.5) / $scale;
        $first = $scrollY > 0 ? (int) ($scrollY / $lh) : 0;
        $last = min($rows->totalRows, (int) max(0.0, ($scrollY + $area->h) / $lh) + 1);

        // margin of the line numbers
        if ($this->lineNumbers) {
            $p->pushClip(new Rect($b->x + 1, $b->y + 1, $gutter, $b->h - 2));
            $p->rect(new Rect($b->x + $gutter - 1, $b->y + 4, 1, $b->h - 8), $look->color(Role::Separator));
            for ($row = $first; $row < $last; $row++) {
                [$line, $lineRow] = $rows->locate($row, $this->lineCount());
                if ($lineRow !== 0) {
                    continue;
                }
                $y = $area->y + $row * $lh - $scrollY;
                $number = (string) ($line + 1);
                $p->text($number, $b->x + $gutter - 9 - $this->measurer()->measure($number, $font)->w, $y + 1 + $ascent,
                    $line === $doc->caret[0] ? $text : $dim, $font);
                // a dot in the margin of the lines that have a marker
                foreach ($doc->markers as $m) {
                    if ($m['line'] === $line) {
                        $p->rect(new Rect($b->x + 4, $y + $lh * 0.5 - 3, 6, 6), $this->markerColor($m['kind']), 3.0);
                        break;
                    }
                }
            }
            $p->popClip();
        }

        $p->pushClip(new Rect($b->x + $gutter + 1, $b->y + 1, $b->w - $gutter - 2, $b->h - 2));
        if ($this->placeholder !== null && $this->lineCount() === 1 && $doc->lines[0] === '') {
            $p->text($this->placeholder, $area->x, $area->y + 1 + $ascent, $dim, $font);
        }
        $match = $focused && ! $hasSelection && $this->language !== null ? $this->bracketMatch() : null;
        $spanLine = -1;
        $spans = [];
        for ($row = $first; $row < $last; $row++) {
            [$line, $lineRow] = $rows->locate($row, $this->lineCount());
            [$begin, $end] = $rows->rowRange($doc, $line, $lineRow);
            $l = $doc->lines[$line];
            $y = $area->y + $row * $lh - $scrollY;
            $x = $area->x - $scrollX + $this->shift($rows, $area->w, $line, $lineRow);

            if (isset($this->lineBackgrounds[$line])) {
                $p->rect(new Rect($b->x + $gutter + 1, $y, $b->w - $gutter - 2, $lh), $this->lineBackgrounds[$line]);
            }
            // the line of the caret, lightly marked
            if ($focused && ! $hasSelection && $line === $doc->caret[0] && ! $this->readOnly) {
                $p->rect(new Rect($b->x + $gutter + 1, $y, $b->w - $gutter - 2, $lh), self::fade($look->color(Role::RowHovered), 0.6));
            }
            // the part of the selection on this row
            if ($hasSelection && $line >= $selMin[0] && $line <= $selMax[0]) {
                $from = $line === $selMin[0] ? max($selMin[1], $begin) : $begin;
                $to = $line === $selMax[0] ? min($selMax[1], $end) : $end;
                if ($from <= $to && ! ($line === $selMax[0] && $selMax[1] < $begin) && ! ($line === $selMin[0] && $selMin[1] > $end)) {
                    $x0 = $rows->advance($l, $begin, $from);
                    $x1 = $rows->advance($l, $from, $to, $x0);
                    // a selected line break shows as a little more than the text
                    $line < $selMax[0] && $end === strlen($l) && $x1 += $rows->space;
                    $x1 > $x0 && $p->rect(new Rect($x + $x0, $y, $x1 - $x0, $lh), $selection, 3.0);
                }
            }
            foreach ($match ?? [] as $at) {
                if ($at[0] !== $line || $at[1] < $begin || $at[1] >= $end) {
                    continue;
                }
                $x0 = $rows->advance($l, $begin, $at[1]);
                $accent = $look->color(Role::Accent);
                $p->rect(new Rect($x + $x0 - 1, $y + 1, $rows->advance($l, $at[1], $at[1] + 1, $x0) - $x0 + 2, $lh - 2), self::fade($accent, 0.22), 3.0, self::fade($accent, 0.7), 1.0);
            }
            if ($end > $begin) {
                if ($this->language !== null && $spanLine !== $line) {
                    $spans = $this->spans($line);
                    $spanLine = $line;
                }
                $this->paintRow($p, $rows, $look, $line, $begin, $end, $x, $y, $this->language !== null ? $spans : [], $text, $ascent);
            }
            // markers: a wavy line under what they are about
            foreach ($doc->markers as $m) {
                if ($m['line'] !== $line) {
                    continue;
                }
                $from = max($begin, min($end, $m['from']));
                $to = $m['to'] < 0 ? $end : max($begin, min($end, $m['to']));
                if ($to < $from || ($m['from'] >= $end && $end > $begin && $lineRow + 1 < $rows->rowCount($line))) {
                    continue;
                }
                $x0 = $rows->advance($l, $begin, $from);
                $x1 = $rows->advance($l, $from, $to, $x0);
                // (something to see even for an empty range)
                $x1 - $x0 < $rows->space * 1.5 && $x1 = $x0 + $rows->space * 1.5;
                $color = $this->markerColor($m['kind']);
                for ($wave = $x0; $wave < $x1; $wave += 4.0) {
                    $next = min($wave + 4.0, $x1);
                    $up = ((int) (($wave - $x0) / 4.0) & 1) !== 0;
                    $p->line($x + $wave, $y + $lh - ($up ? 3.5 : 1.5), $x + $next, $y + $lh - ($up ? 1.5 : 3.5), $color, 1.2);
                }
            }
        }

        // the caret glides to its place and blinks once it rests there
        if ($focused && ! $this->readOnly) {
            [$cx, $cy] = $this->pointOf($doc->caret);
            if (! $this->caretPlaced) {
                $this->caretX->snap($cx);
                $this->caretY->snap($cy);
                $this->caretPlaced = true;
            }
            $this->caretX->target($cx);
            $this->caretY->target($cy);
            $moving = ! $this->caretX->atRest() || ! $this->caretY->atRest();
            if ($moving || $this->blink->visible()) {
                $p->rect(new Rect($area->x + $this->caretX->value() - $scrollX - 1, $area->y + $this->caretY->value() - $scrollY + 1, 2, $lh - 2),
                    $look->color(Role::AccentHovered), 1.0);
            }
        } else {
            $this->caretPlaced = false;
        }
        $p->popClip();

        [$vertical, $horizontal] = $this->tracks($gutter, $bar);
        $hovered = $this->pointer[0] >= 0;
        $this->vbar->paint($p, $look, $vertical, true, $hovered, $vertical->contains(...$this->pointer));
        $this->wrap || $this->hbar->paint($p, $look, $horizontal, false, $hovered, $horizontal->contains(...$this->pointer));
    }

    /**
     * Draws text[begin, end) of a line at ($x, $y), tabs expanded, each span in its colour (rvn_ed_paint_text,
     * text_editor.c:898-933), then the twin's range styles over it.
     */
    private function paintRow(Painter $p, EditorRows $rows, Look $look, int $line, int $begin, int $end, float $x, float $y,
        array $spans, Color $color, float $ascent): void
    {
        $text = $this->doc->lines[$line];
        $styles = $this->rangesOf($line);
        $cuts = [$begin, $end];
        foreach ($spans as [$s, $t]) {
            $cuts[] = $s;
            $cuts[] = $t;
        }
        foreach ($styles as [$s, $t]) {
            $cuts[] = $s;
            $cuts[] = $t;
        }
        $offset = 0;
        while (($tab = strpos($text, "\t", $offset)) !== false && $tab < $end) {
            $cuts[] = $tab;
            $cuts[] = $tab + 1;
            $offset = $tab + 1;
        }
        $cuts = array_values(array_unique(array_filter($cuts, fn ($c) => $c >= $begin && $c <= $end)));
        sort($cuts);
        $pen = 0.0;
        for ($k = 0; $k + 1 < count($cuts); $k++) {
            [$from, $to] = [$cuts[$k], $cuts[$k + 1]];
            $after = $rows->advance($text, $from, $to, $pen);
            if ($text[$from] === "\t") {
                $pen = $after;

                continue;
            }
            $token = null;
            foreach ($spans as [$s, $t, $kind]) {
                if ($from >= $s && $from < $t) {
                    $token = $kind;
                    break;
                }
            }
            $run = $token === null ? $color : ($look->syntax($token) ?? $color);
            $run = new Color($run->red, $run->green, $run->blue, $color->alpha);
            // formatted text: the styles keep the advances of the font, so the columns do not move
            $bold = $token === SyntaxToken::Strong || $token === SyntaxToken::Heading;
            $italic = $token === SyntaxToken::Emphasis;
            $style = null;
            foreach ($styles as [$s, $t, $st]) {
                if ($from >= $s && $from < $t) {
                    $style = $st;
                }
            }
            if ($style !== null) {
                $style->background !== null && $p->rect(new Rect($x + $pen, $y, $after - $pen, $rows->lineHeight), $style->background);
                $style->foreground !== null && $run = $style->foreground;
                $bold = $bold || $style->bold;
                $italic = $italic || $style->italic;
            }
            $f = $rows->font;
            $font = $bold || $italic ? new FontSpec($f->family, $f->size, $bold ? 700 : $f->weight, $italic || $f->italic) : $f;
            $p->text(substr($text, $from, $to - $from), $x + $pen, $y + 1 + $ascent, $run, $font);
            if ($style?->underline) {
                $p->rect(new Rect($x + $pen, $y + $rows->lineHeight - 3, $after - $pen, 1), $run);
            }
            if ($style?->strikeout) {
                $p->rect(new Rect($x + $pen, $y + floor($rows->lineHeight * 0.54), $after - $pen, 1), $run);
            }
            $pen = $after;
        }
    }

    /** @return list<array{int, int, TextRangeStyle}> the twin's range styles of a line, as byte ranges */
    private function rangesOf(int $line): array
    {
        $out = [];
        $text = $this->doc->lines[$line];
        foreach ($this->rangeStyles as $style) {
            if ($style->line !== $line || $style->length === 0) {
                continue;
            }
            // code points to bytes, clipped to the line
            $from = strlen(mb_substr($text, 0, $style->start, 'UTF-8'));
            $to = strlen(mb_substr($text, 0, $style->start + $style->length, 'UTF-8'));
            $to > $from && $out[] = [$from, $to, $style];
        }

        return $out;
    }

    private function markerColor(MarkerKind $kind): Color
    {
        return $this->look()->color(match ($kind) {
            MarkerKind::Error => Role::Danger,
            MarkerKind::Warning => Role::Warning,
            MarkerKind::Info => Role::Accent,
        });
    }

    private static function fade(Color $c, float $a): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $a);
    }

    /** @return list<array{int, int}>|null the bracket by the caret and its partner: the one after the caret, else before (text_editor.c:884-896) */
    private function bracketMatch(): ?array
    {
        $at = $this->doc->caret;
        $found = $this->matchBracket($at);
        if ($found === null && $at[1] > 0) {
            $at = [$at[0], $at[1] - 1];
            $found = $this->matchBracket($at);
        }

        return $found === null ? null : [$at, $found];
    }

    /** The bracket that closes (or opens) the one at $at, no further than 3,000 lines (rvn_ed_match_bracket, text_editor.c:832-882). */
    private function matchBracket(array $at): ?array
    {
        $l = $this->doc->lines[$at[0]];
        if ($at[1] < 0 || $at[1] >= strlen($l)) {
            return null;
        }
        $bracket = $l[$at[1]];
        if (($i = strpos('([{', $bracket)) !== false) {
            [$forward, $other] = [true, ')]}'[$i]];
        } elseif (($i = strpos(')]}', $bracket)) !== false) {
            [$forward, $other] = [false, '([{'[$i]];
        } else {
            return null;
        }
        if (! $this->isCode($at[0], $at[1])) {
            return null;
        }
        $depth = 1;
        $line = $at[0];
        $column = $at[1] + ($forward ? 1 : -1);
        $budget = 3000;
        while ($line >= 0 && $line < $this->lineCount() && $budget-- > 0) {
            $l = $this->doc->lines[$line];
            $spans = $this->language !== null ? $this->spans($line) : [];
            for (; $column >= 0 && $column < strlen($l); $column += $forward ? 1 : -1) {
                $c = $l[$column];
                if ($c !== $bracket && $c !== $other) {
                    continue;
                }
                foreach ($spans as [$s, $t, $token]) {
                    if ($column >= $s && $column < $t && ($token === SyntaxToken::Comment || $token === SyntaxToken::String)) {
                        continue 2;
                    }
                }
                $depth += $c === $bracket ? 1 : -1;
                if ($depth === 0) {
                    return [$line, $column];
                }
            }
            $line += $forward ? 1 : -1;
            if ($line >= 0 && $line < $this->lineCount()) {
                $column = $forward ? 0 : strlen($this->doc->lines[$line]) - 1;
            }
        }

        return null;
    }
}
