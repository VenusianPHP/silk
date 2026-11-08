<?php

namespace Silk\Widgets;

use Silk\Painter\Surfaces;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Widget;
use Silk\Painter\PartBox;
use Surface\Contracts\Windows\Input\EditCommand;
use Silk\Widgets\Concerns\EditsText;
use Silk\Widgets\Concerns\Hoverable;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\ValueChanged;
use Surface\NutsAndBolts\Color;

/**
 * `spinBox(name, value, minimum, maximum, step)` as TKSpinner: a number field. Its steppers are the style's named
 * variant (Steppers): 'drag' (Raven), 'stacked' (macOS), 'sideBySide' (GNOME). Raven's model in every variant
 * (spin_box.c:237-330): the value shows until a click without a drag, Enter, Tab or a typed digit starts typing;
 * a horizontal drag past 3 px scrubs a step per 4 px (Shift ten times finer); the wheel steps only while focused.
 * The arrow keys step and the buttons repeat while held, in every variant. Typing commits on Enter or focus loss
 * (an entry that is not a number reverts), Escape drops it. Posts ValueChanged for user changes.
 */
class SpinBox extends Widget
{
    use EditsText, Hoverable;

    /** Raven's natural width. */
    public const float WIDTH = 80.0;

    /** The drag that starts a scrub, and the drag per step (spin_box.c:9-10). */
    public const float DRAG = 3.0;

    public const float PER_STEP = 4.0;

    private HoldRepeat $repeat;

    private bool $editing = false;

    private bool $pressed = false;

    private bool $dragging = false;

    private float $pressX = 0.0;

    private float $pressValue = 0.0;

    /** The button under the pointer: +1, -1 or 0. */
    private int $hot = 0;

    /** What the wheel turned that has not made a step yet. */
    private float $wheelRest = 0.0;

    public function __construct(
        string $name,
        private float $value = 0.0,
        private float $minimum = 0.0,
        private float $maximum = 100.0,
        private float $step = 1.0,
        private int $decimals = 0,
    ) {
        parent::__construct($name);
        $this->focusable = true;
        $this->repeat = new HoldRepeat;
        $this->value = $this->clamp($value);
        $this->initEditing($this->format($this->value));
    }

    public function value(): float
    {
        return $this->value;
    }

    /** Posts nothing: the app changed it, it knows. */
    public function setValue(float $value): static
    {
        $this->set($value, false);

        return $this;
    }

    /** The field's text, as typed or as formatted. */
    public function text(): string
    {
        return $this->buffer->text();
    }

    /** Whether the value is being typed. */
    public function isEditing(): bool
    {
        return $this->editing;
    }

    public function setRange(float $minimum, float $maximum): static
    {
        [$this->minimum, $this->maximum] = [min($minimum, $maximum), max($minimum, $maximum)];
        $this->set($this->value, false);

        return $this;
    }

    public function setStep(float $step): static
    {
        $this->step = $step;

        return $this;
    }

    public function setDecimals(int $decimals): static
    {
        $this->decimals = max(0, min(9, $decimals));
        $this->replaceText($this->format($this->value));

        return $this;
    }

    public function springs(): array
    {
        return [$this->hover(), $this->repeat, ...$this->editingSprings()];
    }

    protected function measureContent(Size $available): Size
    {
        return new Size(self::WIDTH, $this->look()->metric(Metric::ControlHeight));
    }

    private function variant(): string
    {
        $v = $this->look()->part('SpinBox', 'Body', State::Normal)->extra['steppers'] ?? 'drag';

        return is_string($v) ? $v : 'drag';
    }

    private function buttonAt(float $x, float $y): int
    {
        return Steppers::hit($this->variant(), $this->bounds(), $this->look()->metric(Metric::ControlHeight), $x, $y);
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $this->hoverEvent($e);

        switch ($e->type) {
            case EventType::FocusGained:
                $this->editEvent($e);
                // reaching the field with Tab starts typing right away
                $e->focusVisible && $this->beginEdit();

                return true;
            case EventType::FocusLost:
                $this->endEdit(true);
                $this->repeat->release();
                $this->editEvent($e);

                return true;
            case EventType::Leave:
                $this->setHot(0);

                return false;
            case EventType::KeyDown:
                return $this->key($e);
            case EventType::Text:
                if (! $this->editing) {
                    // typing a number starts an edit that replaces the value
                    if (! preg_match('/^[0-9.,-]/', $e->text ?? '')) {
                        return false;
                    }
                    $this->beginEdit();
                }

                return $this->editEvent($e) ?? true;
            case EventType::Edit:
                return $this->editing && ($this->editEvent($e) ?? false);
            case EventType::Wheel:
                // only a focused field steps: it must not take the scrolling of a panel
                if (! $this->hasFocus || $this->editing || $e->wheelY == 0.0) {
                    return false;
                }
                $this->wheelRest += $e->wheelY;
                $steps = (int) $this->wheelRest;
                $this->wheelRest -= $steps;
                $steps !== 0 && $this->set($this->value + $steps * $this->step, true);

                return true;
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                if (($button = $this->buttonAt($e->x, $e->y)) !== 0) {
                    $this->endEdit(true);
                    $this->stepBy($button);
                    $this->repeat->hold(fn () => $this->stepBy($button));

                    return true;
                }
                if ($this->editing) {
                    return $this->editEvent($e) ?? true;
                }
                [$this->pressed, $this->dragging, $this->pressX, $this->pressValue] = [true, false, $e->x, $this->value];
                $this->invalidatePaint();

                return true;
            case EventType::MouseMove:
                $this->setHot($this->buttonAt($e->x, $e->y));
                if ($this->editing) {
                    return $this->editEvent($e) ?? false;
                }
                if (! $this->pressed) {
                    return false;
                }
                $this->dragging = $this->dragging || abs($e->x - $this->pressX) > self::DRAG;
                if ($this->dragging) {
                    // Shift scrubs ten times finer
                    $steps = floor(($e->x - $this->pressX) / self::PER_STEP);
                    $this->set($this->pressValue + $steps * $this->step * ($e->modifiers->shift ? 0.1 : 1.0), true);
                }

                return true;
            case EventType::MouseUp:
                $this->repeat->release();
                if ($this->editing) {
                    return $this->editEvent($e) ?? true;
                }
                if (! $this->pressed) {
                    return false;
                }
                // a click without a drag switches to typing
                $this->dragging || $this->beginEdit();
                [$this->pressed, $this->dragging] = [false, false];
                $this->invalidatePaint();

                return true;
            default:
                return $this->editEvent($e) ?? false;
        }
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $variant = $this->variant();
        $height = $look->metric(Metric::ControlHeight);
        $field = Steppers::rects($variant, $b, $height)['field'];
        $body = $look->part('SpinBox', 'Body', $this->isEnabled() ? State::Normal : State::Disabled);
        $focused = $look->part('SpinBox', 'Body', State::Focused);
        $lit = $this->hasFocus ? 1.0 : 0.0;
        $width = $body->borderWidth + ($focused->borderWidth - $body->borderWidth) * $lit;
        if ($look->material()->isGlass()) {
            // spin_box.c:220-222: a field of glass, the focus a 1.5 ring of the accent
            Surfaces::paint($p, $look, $field, $field->h / 2, SurfaceKind::Input, 0.0, 0.0, $this->isEnabled());
            $this->hasFocus && $p->rect($field, new Color(0, 0, 0, 0), $field->h / 2, $this->color(Role::Focus, $look), 1.5);
        } else {
            PartBox::paint($p, $field, $body->background, $body->radius, self::mix($body->border, $focused->border, $lit), $width, $body->borderTop, $body->borderBottom);
        }
        // a ring around the field, as wide as its style says (Raven platform_looks.c:317-321)
        $ring = $look->part('SpinBox', 'Ring', State::Focused);
        if (! $look->material()->isGlass() && $this->hasFocus && $ring->borderWidth > 0 && $ring->border !== null) {
            $w = $ring->borderWidth;
            $p->rect(new Rect($field->x - $w, $field->y - $w, $field->w + 2 * $w, $field->h + 2 * $w), new Color(0, 0, 0, 0), $body->radius + $w, $ring->border, $w);
        }
        $text = $body->text ?? $this->color(Role::Text, $look);
        Steppers::paint($p, $look, $variant, $b, $height, $this->hot, $this->isEnabled(), $text);

        if ($this->editing) {
            $this->paintEditing($p, $text);

            return;
        }
        // the value: centred in the Raven field, at the start of the text area on the platforms (spin_box.c:214-235)
        $font = $this->font();
        $label = $this->format($this->value);
        $area = $variant === 'drag' ? $b : $this->textArea();
        $x = $variant === 'drag' ? $b->x + ($b->w - $this->measurer()->measure($label, $font)->w) / 2 : $area->x;
        $p->pushClip($area);
        $p->text($label, $x, $this->baselineIn($area, $font), $text, $font);
        $p->popClip();
    }

    protected function textArea(): Rect
    {
        $look = $this->look();

        return Steppers::textArea($this->variant(), $this->bounds(), $look->metric(Metric::ControlHeight), $look->metric(Metric::PaddingX));
    }

    /** Typing changes only the text; the value changes on commit. */
    protected function textEdited(): void {}

    private function setHot(int $hot): void
    {
        if ($hot !== $this->hot) {
            $this->hot = $hot;
            $this->invalidatePaint();
        }
    }

    private function beginEdit(): void
    {
        if ($this->editing) {
            return;
        }
        $this->editing = true;
        $this->replaceText($this->format($this->value));
        $this->command(EditCommand::SelectAll);
        $this->invalidatePaint();
    }

    /** Ends typing: $apply commits the typed value, else the field goes back to the value. */
    private function endEdit(bool $apply): void
    {
        if (! $this->editing) {
            return;
        }
        $this->editing = false;
        $apply ? $this->commit() : $this->revert();
        $this->invalidatePaint();
    }

    private function key(SilkEvent $e): bool
    {
        if ($this->editing) {
            return match ($e->key) {
                Key::ENTER => $this->endEdit(true) || true,
                Key::ESCAPE => $this->endEdit(false) || true,
                Key::UP => $this->commit() || $this->stepBy(1) || true,
                Key::DOWN => $this->commit() || $this->stepBy(-1) || true,
                default => false,
            };
        }

        return match ($e->key) {
            Key::UP, Key::RIGHT => $this->stepBy(1) || true,
            Key::DOWN, Key::LEFT => $this->stepBy(-1) || true,
            Key::ENTER => $this->beginEdit() || true,
            default => false,
        };
    }

    private function stepBy(int $direction): bool
    {
        $this->set($this->value + $direction * $this->step, true);

        return false;
    }

    /** The typed text as the value, or back to the value when it is not a number. */
    private function commit(): bool
    {
        $typed = trim(str_replace(',', '.', $this->buffer->text()));
        if (is_numeric($typed)) {
            $this->set((float) $typed, true);
        }
        $this->revert();

        return false;
    }

    private function revert(): bool
    {
        if ($this->buffer->text() !== $this->format($this->value)) {
            $this->replaceText($this->format($this->value));
        }

        return true;
    }

    private function set(float $value, bool $user): void
    {
        $value = round($this->clamp($value), $this->decimals);
        if ($value === $this->value) {
            if ($this->buffer->text() !== $this->format($value)) {
                $this->replaceText($this->format($value));
            }

            return;
        }
        $this->value = $value;
        $this->replaceText($this->format($value));
        if ($user) {
            $this->post(new ValueChanged($this->window(), $this->path(), $this->uuid(), $value));
        }
    }

    private function clamp(float $value): float
    {
        return is_nan($value) ? $this->minimum : max($this->minimum, min($this->maximum, $value));
    }

    private function format(float $value): string
    {
        return number_format($value, $this->decimals, '.', '');
    }
}
