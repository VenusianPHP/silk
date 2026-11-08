<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Mail\TimeChanged;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Widget;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\NutsAndBolts\Color;

/**
 * `timePicker(name, hour, minute, second)`: Raven's 24-hour time, HH:MM[:SS] (time_picker.c). A click on a part
 * selects it; Up and Down step it (PageUp and PageDown by 10), the wheel steps the part under the pointer (the
 * selected one when focused), and the two small arrows at the end step it with the mouse. Steps wrap within the
 * part and never carry. Two digits fill a part; a first digit that cannot start a two-digit value fills it at
 * once; a digit waiting for its second is committed when the part is left; ':' '.' 'h' move on; Backspace and
 * Delete clear the waiting digit, or zero the part. Each change posts TimeChanged; setTime() posts nothing.
 */
class TimePicker extends Widget
{
    use MeasuresText;

    /** time_picker.c:28 */
    private const array LIMITS = [24, 60, 60];

    /** @var array{int, int, int} */
    private array $values;

    private int $part = 0;

    /** The first digit typed in the part, waiting for the second, or null. */
    private ?int $typed = null;

    /** 1: the up arrow under the pointer, -1: the down one. */
    private int $hot = 0;

    private float $wheelRest = 0.0;

    private int $minuteStep = 1;

    private bool $hasFocus = false;

    public function __construct(string $name, int $hour = 0, int $minute = 0, private ?int $withSecond = null)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->values = [self::wrap($hour, 0), self::wrap($minute, 1), self::wrap($withSecond ?? 0, 2)];
    }

    public function hour(): int
    {
        return $this->values[0];
    }

    public function minute(): int
    {
        return $this->values[1];
    }

    /** Null when the seconds do not show. */
    public function second(): ?int
    {
        return $this->withSecond === null ? null : $this->values[2];
    }

    /** Posts nothing. */
    public function setTime(int $h, int $m, ?int $s = null): static
    {
        $this->set($h, $m, $s ?? $this->values[2], false);

        return $this;
    }

    /** Minutes step by $step (1 to 30); a time off the step lands on it first (time_picker.c:76-83). */
    public function setMinuteStep(int $step): static
    {
        $this->minuteStep = max(1, min(30, $step));

        return $this;
    }

    /** The selected part: 0 hours, 1 minutes, 2 seconds. */
    public function field(): int
    {
        return $this->part;
    }

    /** The rect of part $i (time_picker.c:49-53). */
    public function segmentRect(int $i): Rect
    {
        [$digits, $colon] = $this->metrics();
        $b = $this->bounds();

        return new Rect($b->x + $this->look()->metric(Metric::PaddingX) * 0.6 + ($digits + $colon) * $i, $b->y + 4, $digits, max(0.0, $b->h - 8));
    }

    /** @return array{Rect, Rect} the up and the down arrow's halves of the strip at the end */
    public function arrowRects(): array
    {
        $b = $this->bounds();
        $x = $b->x + $b->w - $this->arrows() - 2;

        return [new Rect($x, $b->y, $b->x + $b->w - $x, $b->h / 2), new Rect($x, $b->y + $b->h / 2, $b->x + $b->w - $x, $b->h / 2)];
    }

    protected function measureContent(Size $available): Size
    {
        $look = $this->look();
        [$digits, $colon] = $this->metrics();
        $parts = $this->parts();
        $h = $look->metric(Metric::ControlHeight);

        return new Size(ceil($look->metric(Metric::PaddingX) * 1.2 + $digits * $parts + $colon * ($parts - 1) + max(16.0, $h * 0.6) + 4), $h);
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        switch ($e->type) {
            case EventType::MouseMove:
                $this->setHot($this->arrowAt($e->x, $e->y));

                return false;
            case EventType::Leave:
                $this->setHot(0);

                return false;
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                $arrow = $this->arrowAt($e->x, $e->y);
                $this->commit();
                $arrow !== 0 ? $this->step($arrow) : $this->part = $this->partAt($e->x);
                $this->invalidatePaint();

                return true;
            case EventType::Wheel:
                $this->wheelRest += $e->wheelY;
                $steps = (int) $this->wheelRest;
                $this->wheelRest -= $steps;
                if ($steps === 0) {
                    return true;
                }
                $this->hasFocus || $this->part = $this->partAt($e->x);
                $this->step($steps);

                return true;
            case EventType::FocusGained:
            case EventType::FocusLost:
                $this->hasFocus = $e->type === EventType::FocusGained;
                $this->hasFocus || $this->commit();
                $this->invalidatePaint();

                return false;
            case EventType::Text:
                $t = $e->text ?? '';
                if (preg_match('/^[0-9]$/', $t)) {
                    $this->digit((int) $t);

                    return true;
                }
                if ($t === ':' || $t === '.' || $t === 'h') {
                    $this->commit();
                    $this->part + 1 < $this->parts() && $this->part++;
                    $this->invalidatePaint();

                    return true;
                }

                return false;
            case EventType::KeyDown:
                return $this->key($e->key);
            default:
                return false;
        }
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $enabled = $this->isEnabled();
        $text = $this->color($enabled ? Role::Text : Role::TextDisabled, $look);
        $dim = new Color($text->red, $text->green, $text->blue, $text->alpha * 0.55);
        $p->rect($b, $this->color(Role::Input, $look), $look->metric(Metric::CornerRadius),
            $this->color($this->hasFocus ? Role::Focus : Role::Border, $look), $look->metric(Metric::BorderWidth));
        $font = $this->textStyle();
        [, $colon] = $this->metrics();
        for ($i = 0; $i < $this->parts(); $i++) {
            $r = $this->segmentRect($i);
            if ($this->hasFocus && $i === $this->part && $enabled) {
                $p->rect($r, $this->color(Role::Selection, $look), 4.0);
            }
            $label = $this->hasFocus && $i === $this->part && $this->typed !== null ? $this->typed.'_' : sprintf('%02d', $this->values[$i]);
            $p->text($label, $r->x + ($r->w - $this->textSize($label, $font)->w) / 2, $this->baselineIn($r, $font), $text, $font);
            if ($i + 1 < $this->parts()) {
                $c = new Rect($r->x + $r->w, $b->y, $colon, $b->h);
                $p->text(':', $c->x + ($c->w - $this->textSize(':', $font)->w) / 2, $this->baselineIn($c, $font), $dim, $font);
            }
        }
        // the arrows that step it (time_picker.c:121-128)
        $x = $b->x + $b->w - $this->arrows() * 0.5 - 2;
        $cy = $b->y + $b->h * 0.5;
        $this->chevron($p, $x, $cy - 5, -1, $this->hot === 1 && $enabled ? $text : $dim);
        $this->chevron($p, $x, $cy + 5, 1, $this->hot === -1 && $enabled ? $text : $dim);
    }

    /** A 6-unit chevron at $x, $y, pointing up ($dir -1) or down (+1). */
    private function chevron(Painter $p, float $x, float $y, int $dir, Color $ink): void
    {
        $s = 3.0;
        $w = max(1.0, $this->look()->metric(Metric::BorderWidth) * 1.5);
        $p->line($x - $s, $y - $dir * $s / 2, $x, $y + $dir * $s / 2, $ink, $w);
        $p->line($x, $y + $dir * $s / 2, $x + $s, $y - $dir * $s / 2, $ink, $w);
    }

    private function key(?Key $key): bool
    {
        switch ($key) {
            case Key::UP:
            case Key::DOWN:
            case Key::PAGE_UP:
            case Key::PAGE_DOWN:
                $this->commit();
                $this->step(match ($key) { Key::UP => 1, Key::DOWN => -1, Key::PAGE_UP => 10, default => -10 });

                return true;
            case Key::LEFT:
            case Key::RIGHT:
                $next = $this->part + ($key === Key::LEFT ? -1 : 1);
                if ($next < 0 || $next >= $this->parts()) {
                    return false;
                }
                $this->commit();
                $this->part = $next;
                $this->invalidatePaint();

                return true;
            case Key::BACKSPACE:
            case Key::DELETE:
                if ($this->typed !== null) {
                    $this->typed = null;
                } else {
                    $v = $this->values;
                    $v[$this->part] = 0;
                    $this->set(...$v, notify: true);
                }
                $this->invalidatePaint();

                return true;
            default:
                return false;
        }
    }

    /** time_picker.c:151-173 */
    private function digit(int $d): void
    {
        $v = $this->values;
        $limit = self::LIMITS[$this->part];
        if ($this->typed === null) {
            if ($d * 10 >= $limit) {
                $v[$this->part] = $d;
                $this->set(...$v, notify: true);
                $this->part + 1 < $this->parts() && $this->part++;
            } else {
                $this->typed = $d;
            }
            $this->invalidatePaint();

            return;
        }
        $v[$this->part] = min($this->typed * 10 + $d, $limit - 1);
        $this->typed = null;
        $this->set(...$v, notify: true);
        $this->part + 1 < $this->parts() && $this->part++;
        $this->invalidatePaint();
    }

    /** A digit waiting for the second one becomes the value (time_picker.c:176-185). */
    private function commit(): void
    {
        if ($this->typed === null) {
            return;
        }
        $v = $this->values;
        $v[$this->part] = $this->typed;
        $this->typed = null;
        $this->set(...$v, notify: true);
        $this->invalidatePaint();
    }

    /** time_picker.c:72-87 */
    private function step(int $steps): void
    {
        $part = max(0, min($this->parts() - 1, $this->part));
        $step = $part === 1 ? $this->minuteStep : 1;
        $v = $this->values;
        if ($part === 1 && $step > 1) {
            // from a time off the step, the first step lands on it
            $rest = $v[1] % $step;
            if ($rest && $steps > 0) {
                $v[1] += $step - $rest;
                $steps--;
            } elseif ($rest && $steps < 0) {
                $v[1] -= $rest;
                $steps++;
            }
        }
        $v[$part] += $steps * $step;
        $this->typed = null;
        $this->set(...$v, notify: true);
    }

    private function set(int $h, int $m, int $s, bool $notify): void
    {
        $v = [self::wrap($h, 0), self::wrap($m, 1), self::wrap($s, 2)];
        if ($v === $this->values) {
            return;
        }
        $this->values = $v;
        $this->invalidatePaint();
        $notify && $this->post(new TimeChanged($this->window(), $this->path(), $this->uuid(), $v[0], $v[1], $this->second()));
    }

    private function parts(): int
    {
        return $this->withSecond === null ? 2 : 3;
    }

    /** @return array{float, float} two digits' width and a colon's (time_picker.c:44-47) */
    private function metrics(): array
    {
        return [ceil($this->textSize('00')->w) + 6, ceil($this->textSize(':')->w) + 2];
    }

    private function arrows(): float
    {
        return max(16.0, $this->bounds()->h * 0.6);
    }

    /** The colon after a part belongs to it (time_picker.c:145-157). */
    private function partAt(float $x): int
    {
        [, $colon] = $this->metrics();
        for ($i = 0; $i < $this->parts(); $i++) {
            $r = $this->segmentRect($i);
            if ($x < $r->x + $r->w + $colon * 0.5) {
                return $i;
            }
        }

        return $this->parts() - 1;
    }

    private function arrowAt(float $x, float $y): int
    {
        $b = $this->bounds();
        if ($x < $b->x + $b->w - $this->arrows() - 2) {
            return 0;
        }

        return $y < $b->y + $b->h * 0.5 ? 1 : -1;
    }

    private function setHot(int $hot): void
    {
        if ($hot !== $this->hot) {
            $this->hot = $hot;
            $this->invalidatePaint();
        }
    }

    private static function wrap(int $v, int $part): int
    {
        $n = self::LIMITS[$part];

        return (($v % $n) + $n) % $n;
    }
}
