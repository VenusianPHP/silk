<?php

namespace Silk\Widgets\Concerns;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Text\CaretBlink;
use Silk\Text\CaretGeometry;
use Silk\Text\EditBuffer;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\Services\ClipboardText;
use Surface\Contracts\Windows\Services\Clipboard;
use Surface\NutsAndBolts\Color;

/**
 * One line of editing inside a widget: the buffer, caret blink and glide, selection, pointer selection, the
 * one-line EditCommands, clipboard, and the caret geometry (reshaped only when the text changes). The widget
 * gives the text area and says what an edit and Enter mean.
 */
trait EditsText
{
    use MeasuresText;

    /** A secret value shows one of these per grapheme. */
    public const string DOT = '•';

    private EditBuffer $buffer;

    private CaretBlink $blink;

    /** The caret's x gliding to its place (SNAPPY), in text units. */
    private Spring $caretX;

    private bool $caretPlaced = false;

    private float $scroll = 0.0;

    private bool $selecting = false;

    private bool $hasFocus = false;

    private ?CaretGeometry $geometry = null;

    private ?FontSpec $font = null;

    private ?Clipboard $clipboard = null;

    private ?string $pasteRequest = null;

    /** Where the text sits, in logical units. */
    abstract protected function textArea(): Rect;

    /** After a user edit changed the text. */
    abstract protected function textEdited(): void;

    protected function secretText(): bool
    {
        return false;
    }

    protected function initEditing(string $text): void
    {
        $this->buffer = new EditBuffer($text);
        $this->blink = new CaretBlink;
        $this->caretX = new Spring(0.0, SpringPreset::Snappy);
    }

    /** Replace the text as code does: no mail. */
    protected function replaceText(string $text): void
    {
        $this->buffer->setText($text);
        $this->geometry = null;
        $this->invalidatePaint();
    }

    public function selectedText(): string
    {
        return $this->buffer->selectedText();
    }

    public function setFont(?FontSpec $font): static
    {
        $this->font = $font;
        $this->geometry = null;

        return $this->invalidateLayout();
    }

    /** Copy and paste go through this clipboard; without one they do nothing. */
    public function setClipboard(?Clipboard $clipboard): static
    {
        $this->clipboard = $clipboard;

        return $this;
    }

    /** Takes the clipboard's answer to this field's paste request. */
    public function handles(object $mail): bool
    {
        if (! $mail instanceof ClipboardText || $mail->id !== $this->pasteRequest) {
            return false;
        }
        $this->pasteRequest = null;
        if ($mail->text !== null && $this->isEnabled()) {
            $this->edited($this->buffer->insert(self::oneLine($mail->text)));
        }

        return true;
    }

    /** @return list<Spring> */
    protected function editingSprings(): array
    {
        return [$this->caretX, $this->blink];
    }

    /** Focus, pointer selection, committed text and edit commands; null when the event is not about editing. */
    protected function editEvent(SilkEvent $e): ?bool
    {
        return match ($e->type) {
            EventType::FocusGained => $this->editFocus(true),
            EventType::FocusLost => $this->editFocus(false),
            EventType::MouseDown => $this->selectDown($e),
            EventType::MouseMove => $this->selecting && $this->selectDrag($e),
            EventType::MouseUp => $this->selecting && ! ($this->selecting = false),
            EventType::Text => $this->edited($this->buffer->insert(self::oneLine($e->text ?? ''), typing: true)) || true,
            EventType::Edit => $e->command !== null && $this->command($e->command),
            default => null,
        };
    }

    /** The text, its selection and caret, clipped to the text area; the baseline centres the font box. */
    protected function paintEditing(Painter $p, Color $color, bool $center = false): void
    {
        $look = $this->look();
        $area = $this->textArea();
        $font = $this->font();
        $baseline = $this->baselineIn($area, $font);
        $geometry = $this->geometry();
        $selection = $this->buffer->selection();
        $caret = $geometry->xAt($this->displayByte($selection->caret));
        $width = $geometry->xAt(strlen($this->display()));
        if ($center && ! $this->hasFocus) {
            $this->scroll = min(0.0, -($area->w - $width) / 2);
        } else {
            $this->keepVisible($caret, $area->w);
        }
        $m = $this->measurer()->metrics($font);
        $top = $baseline - $m['ascent'];
        $height = $m['ascent'] + $m['descent'];
        $left = $area->x - $this->scroll;

        $p->pushClip($area);
        if (! $selection->isEmpty() && $this->hasFocus) {
            $x0 = $geometry->xAt($this->displayByte($selection->start()));
            $x1 = $geometry->xAt($this->displayByte($selection->end()));
            $p->rect(new Rect($left + $x0, $top, $x1 - $x0, $height), $look->color(Role::Selection));
        }
        $p->text($this->display(), $left, $baseline, $color, $font);
        if (! $this->caretPlaced) {
            $this->caretX->snap($caret);
            $this->caretPlaced = true;
        }
        $this->caretX->target($caret);
        if ($this->hasFocus && $this->blink->visible() && $selection->isEmpty()) {
            $p->rect(new Rect($left + $this->caretX->value(), $top, 1.0, $height), $this->color(Role::Text, $look));
        }
        $p->popClip();
    }

    protected function editFocus(bool $on): bool
    {
        $this->hasFocus = $on;
        if ($on) {
            $this->blink->start();
        } else {
            $this->blink->stop();
            $this->buffer->setCaret($this->buffer->selection()->caret, false);
            $this->scroll = 0.0;
            $this->selecting = false;
        }
        $this->invalidatePaint();

        return true;
    }

    protected function selectDown(SilkEvent $e): bool
    {
        if ($e->button !== MouseButton::LEFT) {
            return false;
        }
        $byte = $this->byteAt($e->x);
        match (true) {
            $e->clicks >= 3 => $this->buffer->selectAll(),
            $e->clicks === 2 => $this->buffer->selectWordAt($byte),
            default => $this->buffer->setCaret($byte, $e->modifiers->shift),
        };
        $this->selecting = $e->clicks < 2;
        $this->blink->restart();
        $this->invalidatePaint();

        return true;
    }

    protected function selectDrag(SilkEvent $e): bool
    {
        $this->buffer->setCaret($this->byteAt($e->x), true);
        $this->blink->restart();
        $this->invalidatePaint();

        return true;
    }

    /** Every EditCommand that applies to one line. */
    protected function command(EditCommand $c): bool
    {
        $b = $this->buffer;
        $moved = true;
        match ($c) {
            EditCommand::MoveLeft, EditCommand::SelectLeft => $b->moveLeft(false, $c === EditCommand::SelectLeft),
            EditCommand::MoveRight, EditCommand::SelectRight => $b->moveRight(false, $c === EditCommand::SelectRight),
            EditCommand::MoveWordLeft, EditCommand::SelectWordLeft => $b->moveLeft(true, $c === EditCommand::SelectWordLeft),
            EditCommand::MoveWordRight, EditCommand::SelectWordRight => $b->moveRight(true, $c === EditCommand::SelectWordRight),
            EditCommand::MoveToLineStart, EditCommand::MoveToDocumentStart, EditCommand::MoveUp, EditCommand::PageUp => $b->home(false),
            EditCommand::SelectToLineStart, EditCommand::SelectToDocumentStart, EditCommand::SelectUp, EditCommand::SelectPageUp => $b->home(true),
            EditCommand::MoveToLineEnd, EditCommand::MoveToDocumentEnd, EditCommand::MoveDown, EditCommand::PageDown => $b->end(false),
            EditCommand::SelectToLineEnd, EditCommand::SelectToDocumentEnd, EditCommand::SelectDown, EditCommand::SelectPageDown => $b->end(true),
            EditCommand::SelectAll => $b->selectAll(),
            default => $moved = false,
        };
        if ($moved) {
            $this->blink->restart();
            $this->invalidatePaint();

            return true;
        }

        return match ($c) {
            EditCommand::DeleteBackward => $this->edited($b->deleteBackward()) || true,
            EditCommand::DeleteForward => $this->edited($b->deleteForward()) || true,
            EditCommand::DeleteWordBackward => $this->edited($b->deleteWordBackward()) || true,
            EditCommand::DeleteWordForward => $this->edited($b->deleteWordForward()) || true,
            EditCommand::DeleteToLineEnd => $this->edited($b->deleteToEnd()) || true,
            EditCommand::Yank => $this->edited($b->yank()) || true,
            EditCommand::Undo => $this->edited($b->undo()) || true,
            EditCommand::Redo => $this->edited($b->redo()) || true,
            EditCommand::Copy => $this->copy() || true,
            EditCommand::Cut => ($this->copy() && $this->edited($b->deleteBackward())) || true,
            EditCommand::Paste => $this->paste() || true,
            // Enter acts from its KeyDown; a single line takes no newline or tab
            EditCommand::InsertNewline, EditCommand::InsertTab, EditCommand::InsertBacktab => true,
            default => false,   // Cancel bubbles (dialogs close on it)
        };
    }

    private function copy(): bool
    {
        if ($this->clipboard === null || $this->secretText() || $this->buffer->selection()->isEmpty()) {
            return false;
        }
        $this->clipboard->setText($this->buffer->selectedText());

        return true;
    }

    private function paste(): bool
    {
        if ($this->clipboard === null) {
            return false;
        }
        $this->pasteRequest = $this->clipboard->requestText();

        return true;
    }

    /** After a user edit: the run is stale, the caret shows, and the widget hears of it. */
    private function edited(bool $changed): bool
    {
        if (! $changed) {
            return false;
        }
        $this->geometry = null;
        $this->blink->restart();
        $this->invalidatePaint();
        $this->textEdited();

        return true;
    }

    protected function font(): FontSpec
    {
        return $this->font ?? $this->textStyle();
    }

    /** What is drawn: the text, or a dot per grapheme when secret. */
    private function display(): string
    {
        return $this->secretText() ? str_repeat(self::DOT, count($this->buffer->boundaries()) - 1) : $this->buffer->text();
    }

    private function displayByte(int $byte): int
    {
        if (! $this->secretText()) {
            return $byte;
        }

        return (int) array_search($byte, $this->buffer->boundaries(), true) * strlen(self::DOT);
    }

    /** The text's byte under a pointer x. */
    private function byteAt(float $x): int
    {
        $display = $this->geometry()->byteAt($x - $this->textArea()->x + $this->scroll);
        if (! $this->secretText()) {
            return $display;
        }

        return $this->buffer->boundaries()[intdiv($display, strlen(self::DOT))] ?? strlen($this->buffer->text());
    }

    /** Built once per text: the reshape happens on the next paint or pointer use, not per key. */
    private function geometry(): CaretGeometry
    {
        if ($this->geometry === null) {
            $text = $this->display();
            $font = $this->font();
            $run = $this->measurer()->run($text, $font);
            $this->geometry = $run !== null
                ? new CaretGeometry($run)
                : CaretGeometry::measured($text, fn (string $s) => $this->measurer()->measure($s, $font)->w);
        }

        return $this->geometry;
    }

    private function keepVisible(float $caret, float $width): void
    {
        if ($caret - $this->scroll > $width) {
            $this->scroll = $caret - $width;
        } elseif ($caret < $this->scroll) {
            $this->scroll = $caret;
        }
        $this->scroll = max(0.0, min($this->scroll, max(0.0, $this->geometry()->xAt(strlen($this->display())) - $width + 1)));
    }

    private static function oneLine(string $text): string
    {
        return str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
    }
}
