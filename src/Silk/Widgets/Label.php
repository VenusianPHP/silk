<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\FontRole;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Mail\LinkActivated;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Widget;
use Silk\Text\Markdown\Markdown;
use Silk\Text\Markdown\MarkdownLayout;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Services\Clipboard;
use Surface\NutsAndBolts\Color;

/**
 * One line of text; `label(name, text)` as TKLabel. With setMarkdown() the text is Markdown, laid out and
 * painted as Raven's label.c does with widgets/markdown.c; a click on a link posts LinkActivated.
 */
class Label extends Widget
{
    use MeasuresText;

    private bool $markdown = false;

    private ?Markdown $md = null;

    private ?MarkdownLayout $laid = null;

    private string $laidKey = '';

    /** The link under the pointer, the link the button went down on; -1 = none (label.c:26-28). */
    private int $hotLink = -1;

    private int $pressedLink = -1;

    /* selection: byte offsets into the text shown (label.c:34-43) */
    private bool $selectable = false;

    private int $anchor = 0;

    private int $caret = 0;

    private bool $selecting = false;

    /** Selecting by characters (1), words (2) or paragraphs (3); the word or paragraph the click started on. */
    private int $unit = 1;

    private int $unitFrom = 0;

    private int $unitTo = 0;

    private bool $madeFocusable = false;

    private ?Clipboard $clipboard = null;

    public function __construct(string $name, private string $text)
    {
        parent::__construct($name);
    }

    private bool $wrap = false;

    /** Wrap to the width layout gives; the height follows the line count. */
    public function setWrap(bool $wrap = true): static
    {
        $this->wrap = $wrap;

        return $this->invalidateLayout();
    }

    public function wraps(): bool
    {
        return $this->wrap;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;
        $this->forget();

        return $this->invalidateLayout();
    }

    /** The text is Markdown (Raven Rlabel_set_markdown); it wraps when setWrap() is on. */
    public function setMarkdown(bool $on = true): static
    {
        $this->markdown = $on;
        $this->forget();

        return $this->invalidateLayout();
    }

    public function isMarkdown(): bool
    {
        return $this->markdown;
    }

    /** The text without its Markdown marks (Rlabel_get_plain_text); the text itself when Markdown is off. */
    public function plainText(): string
    {
        return $this->markdown ? $this->parsed()->plainText() : $this->text;
    }

    public function hotLink(): int
    {
        return $this->hotLink;
    }

    /** The Markdown laid out at the last measure; null before one or without Markdown. */
    public function markdownLayout(): ?MarkdownLayout
    {
        return $this->markdown ? $this->laid : null;
    }

    private function forget(): void
    {
        $this->md = null;
        $this->laid = null;
        $this->laidKey = '';
        $this->hotLink = -1;
        $this->pressedLink = -1;
        // (the offsets were in the old text)
        $this->anchor = $this->caret = 0;
        $this->selecting = false;
    }

    private function parsed(): Markdown
    {
        return $this->md ??= Markdown::parse($this->text);
    }

    private function layMarkdown(float $width): MarkdownLayout
    {
        $font = $this->textStyle();
        $mono = $this->textStyle(FontRole::Mono);
        $key = "{$width}\0{$font->family}\0{$font->size}\0{$font->weight}\0{$mono->family}";
        if ($this->laid === null || $this->laidKey !== $key) {
            $this->laid = new MarkdownLayout($this->parsed(), $this->measurer(), $font, $mono, $width,
                fn (string $url) => (new Image($this->name().'.image', $url))->framebuffer());
            $this->laidKey = $key;
        }

        return $this->laid;
    }

    /** A label lets the pointer through, unless its text can be selected or it has links to click (label.c:58-63). */
    public function hits(float $x, float $y): bool
    {
        return $this->selectable || ($this->markdown && $this->laid !== null && $this->laid->linkCount() > 0);
    }

    /**
     * The mouse selects the text, as on a web page: a drag by characters, a double click by words, a triple
     * click by paragraphs; Copy and Select All work once a click focused it (Raven Rlabel_set_selectable,
     * label.c:545-567). It is focused by a click, not by Tab.
     */
    public function setSelectable(bool $selectable = true): static
    {
        if ($this->selectable === $selectable) {
            return $this;
        }
        $this->selectable = $selectable;
        if ($selectable) {
            if (! $this->isFocusable()) {
                $this->setFocusable(true)->setTabStop(false);
                $this->madeFocusable = true;
            }
        } else {
            $this->anchor = $this->caret = 0;
            $this->selecting = false;
            if ($this->madeFocusable) {
                $this->setFocusable(false)->setTabStop(true);
                $this->madeFocusable = false;
            }
        }

        return $this->invalidatePaint();
    }

    public function isSelectable(): bool
    {
        return $this->selectable;
    }

    /** Copy goes through this clipboard; without one it does nothing. */
    public function setClipboard(?Clipboard $clipboard): static
    {
        $this->clipboard = $clipboard;

        return $this;
    }

    /** @return array{int, int} the selection, as byte offsets into the text shown */
    public function selection(): array
    {
        return [min($this->anchor, $this->caret), max($this->anchor, $this->caret)];
    }

    /** The selection as it is copied, the marks of Markdown left out (Rlabel_get_selected_text). */
    public function selectedText(): string
    {
        [$from, $to] = $this->selection();
        if ($from >= $to) {
            return '';
        }

        return $this->markdown ? ($this->laid?->selectedText($from, $to) ?? '') : substr($this->text, $from, $to - $from);
    }

    public function selectAll(): static
    {
        if ($this->selectable) {
            $this->anchor = 0;
            $this->caret = strlen($this->shown());
            $this->invalidatePaint();
        }

        return $this;
    }

    public function clearSelection(): static
    {
        if ($this->anchor !== $this->caret || $this->selecting) {
            $this->anchor = $this->caret = 0;
            $this->selecting = false;
            $this->invalidatePaint();
        }

        return $this;
    }

    /** label.c:497-534: the link under the pointer is hot; a press and release on the same link follows it. */
    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        if ($this->markdown && $this->laid !== null) {
            $b = $this->bounds();
            $link = fn () => $this->laid->linkAt($e->x - $b->x, $e->y - $b->y);
            switch ($e->type) {
                case EventType::MouseMove:
                    $this->setHot($this->selecting ? -1 : $link());
                    break;
                case EventType::Leave:
                    $this->setHot(-1);
                    break;
                case EventType::MouseDown:
                    if ($e->button !== MouseButton::LEFT) {
                        break;
                    }
                    $this->pressedLink = $link();
                    // a link is clicked, not selected; text that is not a link lets the click go to what holds the label
                    if ($this->pressedLink >= 0) {
                        $this->selectable && $this->clearSelection();

                        return true;
                    }
                    break;
                case EventType::MouseUp:
                    if ($e->button !== MouseButton::LEFT) {
                        break;
                    }
                    $at = $link();
                    $pressed = $this->pressedLink;
                    $this->pressedLink = -1;
                    if ($at >= 0 && $at === $pressed) {
                        // shortcut: Raven opens http(s)/mailto addresses and fires "action:" ones when no callback is set;
                        // opening an address is blocked (roadmap Blocked list #8), so the mail is the only follow-up
                        $url = $this->laid->link($at);
                        $url !== null && $this->post(new LinkActivated($this->window(), $this->path(), $this->uuid(), $url));

                        return true;
                    }
                    break;
                default:
                    break;
            }
        }

        return $this->selectable && $this->selectEvent($e);
    }

    /** label.c:436-494. */
    private function selectEvent(SilkEvent $e): bool
    {
        switch ($e->type) {
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                $offset = $this->offsetAt($e->x, $e->y);
                $this->unit = $e->clicks >= 3 ? 3 : ($e->clicks === 2 ? 2 : 1);
                if ($this->unit === 1) {
                    if (! $e->modifiers->shift) {
                        $this->anchor = $offset;
                    }
                    $this->caret = $offset;
                } else {
                    [$this->unitFrom, $this->unitTo] = $this->unit === 2 ? $this->wordAt($offset) : $this->paragraphAt($offset);
                    $this->anchor = $this->unitFrom;
                    $this->caret = $this->unitTo;
                }
                $this->selecting = true;
                $this->invalidatePaint();

                return true;
            case EventType::MouseMove:
                if (! $this->selecting) {
                    return false;
                }
                $this->selectTo($this->offsetAt($e->x, $e->y));

                return true;
            case EventType::MouseUp:
                if ($e->button !== MouseButton::LEFT || ! $this->selecting) {
                    return false;
                }
                $this->selecting = false;

                return true;
            case EventType::KeyDown:
                if ($e->key === Key::ESCAPE && $this->anchor !== $this->caret) {
                    $this->clearSelection();

                    return true;
                }

                return false;
            case EventType::Edit:
                if ($e->command === EditCommand::Copy && $this->anchor !== $this->caret) {
                    $text = $this->selectedText();
                    $text !== '' && $this->clipboard?->setText($text);

                    return true;
                }
                if ($e->command === EditCommand::SelectAll) {
                    $this->selectAll();

                    return true;
                }

                return false;
            case EventType::FocusLost:
                // a click elsewhere: as on a web page, the selection goes away
                $this->clearSelection();

                return false;
            default:
                return false;
        }
    }

    /** A drag: from the word or the paragraph a double or triple click started on (label.c:412-426). */
    private function selectTo(int $offset): void
    {
        [$from, $to] = match ($this->unit) {
            2 => $this->wordAt($offset),
            3 => $this->paragraphAt($offset),
            default => [$offset, $offset],
        };
        if ($this->unit <= 1) {
            $this->caret = $offset;
        } elseif ($from < $this->unitFrom) {
            $this->anchor = $this->unitTo;
            $this->caret = $from;
        } else {
            $this->anchor = $this->unitFrom;
            $this->caret = max($to, $this->unitTo);
        }
        $this->invalidatePaint();
    }

    /** The text the selection's offsets count in: with Markdown, the text shown. */
    private function shown(): string
    {
        return $this->markdown ? substr($this->laid?->text ?? '', 0, $this->laid?->parsedLength ?? 0) : $this->text;
    }

    private static function isWordByte(string $c): bool
    {
        return ctype_alnum($c) || $c === '_' || ord($c) >= 0x80;
    }

    /** @return array{int, int} the word around an offset; a space or a sign alone when on one (label.c:271-300) */
    private function wordAt(int $offset): array
    {
        $text = $this->shown();
        $length = strlen($text);
        $start = max(0, min($offset, $length));
        if ($start < $length && ! self::isWordByte($text[$start]) && $start > 0 && self::isWordByte($text[$start - 1])) {
            $start--;
        }
        if ($start >= $length) {
            if ($length === 0 || ! self::isWordByte($text[$length - 1])) {
                return [$length, $length];
            }
            $start = $length - 1;
        }
        if (! self::isWordByte($text[$start])) {
            $next = $start + 1;
            while ($next < $length && (ord($text[$next]) & 0xC0) === 0x80) {
                $next++;
            }

            return [$start, $next];
        }
        $end = $start;
        while ($start > 0 && self::isWordByte($text[$start - 1])) {
            $start--;
        }
        while ($end < $length && self::isWordByte($text[$end])) {
            $end++;
        }
        if ($this->markdown && $this->laid !== null) {
            // (the paragraphs of Markdown follow each other in the text with nothing between them)
            [$pFrom, $pTo] = $this->laid->paragraphAt($offset);
            $start = max($start, $pFrom);
            $end = min($end, $pTo);
        }

        return [$start, $end];
    }

    /** @return array{int, int} the paragraph around an offset: between two line breaks (label.c:303-316) */
    private function paragraphAt(int $offset): array
    {
        if ($this->markdown) {
            return $this->laid?->paragraphAt($offset) ?? [$offset, $offset];
        }
        $text = $this->text;
        $from = $to = max(0, min($offset, strlen($text)));
        while ($from > 0 && $text[$from - 1] !== "\n") {
            $from--;
        }
        while ($to < strlen($text) && $text[$to] !== "\n") {
            $to++;
        }

        return [$from, $to];
    }

    /**
     * The lines of a plain label with where each starts in the text (rvn_label_lines, label.c:105-130): cut at
     * each "\n", and wrapped on $width when the label wraps; a text ending with "\n" has one more, empty, line.
     * The spaces a line was cut on belong to neither line.
     *
     * @return list<array{int, string}>
     */
    private function lines(float $width): array
    {
        $out = [];
        $at = 0;
        foreach (explode("\n", $this->text) as $paragraph) {
            $pieces = $this->wrap ? $this->measurer()->wrap($paragraph, $this->textStyle(), $width) : [$paragraph];
            $cursor = 0;
            foreach ($pieces ?: [''] as $line) {
                $found = $line === '' ? false : strpos($paragraph, $line, $cursor);
                $cursor = $found === false ? $cursor : $found;
                $out[] = [$at + $cursor, $line];
                $cursor += strlen($line);
            }
            $at += strlen($paragraph) + 1;
        }

        return $out;
    }

    /** One line drawn in one call: the text has no "\n" and does not wrap. */
    private function oneLine(): bool
    {
        return ! $this->wrap && ! str_contains($this->text, "\n");
    }

    /** The top of the first line: a one-line label centres its font box, a wrapped one starts at its top. */
    private function linesTop(): float
    {
        $b = $this->bounds();

        return $this->oneLine() ? $this->baselineIn($b) - $this->measurer()->metrics($this->textStyle())['ascent'] : $b->y;
    }

    /** The offset of the text nearest a point (label.c:228-257); $x, $y in the tree's coordinates. */
    public function offsetAt(float $x, float $y): int
    {
        $b = $this->bounds();
        if ($this->markdown) {
            return $this->laid?->offsetAt($x - $b->x, $y - $b->y) ?? 0;
        }
        $style = $this->textStyle();
        $height = $this->measurer()->metrics($style)['lineHeight'];
        $lines = $this->lines($b->w);
        $y -= $this->linesTop();
        if ($y < 0) {
            return 0;
        }
        if ($y >= $height * count($lines)) {
            return strlen($this->text);
        }
        [$start, $line] = $lines[max(0, min(count($lines) - 1, (int) floor($y / $height)))];
        $x -= $b->x;

        return $x <= 0 ? $start : $start + min(strlen($line), $this->measurer()->offsetAt($line, $style, $x));
    }

    private function setHot(int $link): void
    {
        if ($this->hotLink !== $link) {
            // shortcut: Raven shows the hand cursor over a link; no pointer-cursor API yet (roadmap Blocked list #7)
            $this->hotLink = $link;
            $this->invalidatePaint();
        }
    }

    protected function measureContent(Size $available): Size
    {
        if ($this->markdown) {
            return $this->layMarkdown($this->wrap ? $available->w : 0.0)->size;
        }
        if ($this->oneLine()) {
            return $this->textSize($this->text);
        }
        $style = $this->textStyle();
        $widest = 0.0;
        $lines = $this->lines($available->w);
        foreach ($lines as [, $line]) {
            $widest = max($widest, $this->textSize($line, $style)->w);
        }
        $count = $this->text === '' ? 0 : count($lines);

        return new Size($widest, $count * $this->measurer()->metrics($style)['lineHeight']);
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $role = $this->isEnabled() ? Role::Text : Role::TextDisabled;
        $style = $this->textStyle();
        $color = $this->color($role, $look);
        if ($this->markdown) {
            $this->paintMarkdown($p, $color);

            return;
        }
        $m = $this->measurer()->metrics($style);
        [$from, $to] = $this->selection();
        $y = $this->linesTop();
        foreach ($this->lines($b->w) as [$start, $line]) {
            if ($from < $to) {
                $this->paintSelection($p, $start, $line, $from, $to, $y, $m['lineHeight'], $style, $look->color(Role::Selection));
            }
            $line !== '' && $p->text($line, $b->x, $y + $m['ascent'], $color, $style);
            $y += $m['lineHeight'];
        }
    }

    /** The selection behind a line; past its end it goes on to the next, so a little of its end shows (label.c:191-205). */
    private function paintSelection(Painter $p, int $start, string $line, int $from, int $to, float $y, float $height, FontSpec $style, Color $color): void
    {
        $length = strlen($line);
        $a = max($from, $start);
        $z = min($to, $start + $length);
        $beyond = $to > $start + $length && $start + $length < strlen($this->text);
        if ($a >= $z && ! ($beyond && $from <= $start + $length)) {
            return;
        }
        $x = $this->bounds()->x;
        $x0 = $x + $this->textSize(substr($line, 0, $a - $start), $style)->w;
        $x1 = $a < $z ? $x + $this->textSize(substr($line, 0, $z - $start), $style)->w : $x0;
        $beyond && $x1 += floor($style->size * 0.3);
        $p->rect(new Rect($x0, $y, $x1 - $x0, $height), $color);
    }

    /** label.c:352-367, markdown.c:1532-1604; text larger than the label is cut at its bounds. */
    private function paintMarkdown(Painter $p, Color $color): void
    {
        $laid = $this->laid ?? $this->layMarkdown($this->wrap ? $this->bounds()->w : 0.0);
        $look = $this->look();
        $b = $this->bounds();
        $clipped = $laid->size->w > $b->w + 0.5 || $laid->size->h > $b->h + 0.5;
        $clipped && $p->pushClip($b);
        [$from, $to] = $this->selection();
        $from < $to && $laid->paintSelection($p, $b->x, $b->y, $from, $to, $look->color(Role::Selection));
        $colors = [
            'text' => $color,
            'link' => $this->isEnabled() ? $this->color(Role::Link, $look) : $color,
            'separator' => $this->color(Role::Separator, $look),
            'code' => $this->color(Role::Code, $look),
            'accent' => $this->color(Role::Accent, $look),
            'accentText' => $this->color(Role::AccentText, $look),
        ];
        $laid->paint($p, $b->x, $b->y, $colors, fn (\Silk\Contracts\Look\SyntaxToken $t) => $look->syntax($t), $this->isEnabled() ? $this->hotLink : -1);
        $clipped && $p->popClip();
    }
}
