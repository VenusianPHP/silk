<?php

namespace Silk\Widgets;

use InvalidArgumentException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Motion;
use Silk\Contracts\Look\PartLook;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Widget;
use Silk\Painter\Knob;
use Silk\Painter\PartBox;
use Silk\Widgets\Concerns\EasesParts;
use Silk\Widgets\Concerns\Hoverable;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\ValueChanged;
use Surface\NutsAndBolts\Color;

/**
 * `slider(name, min, max, value)` as TKSlider; Raven widgets/slider.c. A press sets the value where it lands and the
 * knob flies there on BOUNCY; a drag then follows the hand exactly; the arrow keys step, Home and End go to the ends.
 * The user's changes post ValueChanged. setStep() is Silk's: values snap to it (0, the default, is continuous).
 */
class Slider extends Widget
{
    use EasesParts, Hoverable;

    /** Raven's length (slider.c:62). */
    public const float WIDTH = 120.0;

    private float $step = 0.0;

    private bool $held = false;

    private bool $dragging = false;

    private bool $hovered = false;

    /** Raven's Orientation (slider.c:14): a vertical slider grows upwards. */
    private bool $vertical = false;

    /** Where the knob is drawn, in values: it follows the hand exactly, and flies to a value set otherwise (slider.c:17-18). */
    private Spring $drawn;

    private Spring $press;

    public function __construct(string $name, private float $min, private float $max, private float $value)
    {
        parent::__construct($name);
        if ($min >= $max) {
            throw new InvalidArgumentException("Slider '{$name}': min must be below max.");
        }
        $this->focusable = true;
        $this->value = $this->fit($value);
        // as Raven's spring, it starts at the low end and flies to the value
        $this->drawn = (new Spring($min, SpringPreset::Bouncy))->target($this->value);
        $this->press = new Spring(0.0, SpringPreset::Snappy);
    }

    public function value(): float
    {
        return $this->value;
    }

    /** The value the knob is drawn at. */
    public function drawn(): float
    {
        return $this->drawn->value();
    }

    public function min(): float
    {
        return $this->min;
    }

    public function max(): float
    {
        return $this->max;
    }

    /** Clamped into the range, snapped to the step; the knob flies there. Posts nothing: the app changed it, it knows. */
    public function setValue(float $value): static
    {
        $this->value = $this->fit($value);
        $this->drawn->target($this->value);

        return $this->invalidatePaint();
    }

    public function setRange(float $min, float $max): static
    {
        if ($min >= $max) {
            throw new InvalidArgumentException("Slider '{$this->name}': min must be below max.");
        }
        [$this->min, $this->max] = [$min, $max];

        return $this->setValue($this->value);
    }

    public function setStep(float $step): static
    {
        $this->step = max(0.0, $step);

        return $this->setValue($this->value);
    }

    /** Raven's Orientation="Vertical" (slider.c:14, 62, 223-235); TK's slider has none, so this is Silk's own. */
    public function setVertical(bool $vertical): static
    {
        $this->vertical = $vertical;

        return $this->invalidateLayout();
    }

    public function isVertical(): bool
    {
        return $this->vertical;
    }

    /** @return array{float, float} the length along the slider and across it */
    private function axes(): array
    {
        $b = $this->bounds();

        return $this->vertical ? [$b->h, $b->w] : [$b->w, $b->h];
    }

    /** A rect given along the slider (from its start) and across it (from its side). */
    private function along(float $a, float $c, float $length, float $thickness): Rect
    {
        $b = $this->bounds();

        return $this->vertical ? new Rect($b->x + $c, $b->y + $a, $thickness, $length) : new Rect($b->x + $a, $b->y + $c, $length, $thickness);
    }

    /** The knob's centre along the slider: up from the bottom when vertical (slider.c:143, 225). */
    private function centreAlong(): float
    {
        [$length] = $this->axes();
        $f = $this->fraction($this->drawn->value());

        return $this->handleSize() / 2 + max(0.0, $length - $this->handleSize()) * ($this->vertical ? 1 - $f : $f);
    }

    public function step(): float
    {
        return $this->step;
    }

    public function press(): Spring
    {
        return $this->press;
    }

    public function springs(): array
    {
        return [$this->hover(), $this->drawn, $this->press];
    }

    private function fit(float $v): float
    {
        if ($this->step > 0) {
            $v = $this->min + floor(($v - $this->min) / $this->step + 0.5) * $this->step;
        }

        return (float) min($this->max, max($this->min, $v));
    }

    /** The knob's length along the track: a disc, or an elongated capsule in a glass look and on macOS 26 (slider.c:53-56). */
    private function handleSize(): float
    {
        $size = $this->look()->metric(Metric::SliderHandleSize);

        return $this->look()->material()->isGlass() || $this->look()->liquid() ? floor($size * 1.5) : $size;
    }

    /** The knob across the track (slider.c:57-58). */
    private function knobSize(): float
    {
        return $this->look()->metric(Metric::SliderHandleSize);
    }

    private function fraction(float $v): float
    {
        return min(1.0, max(0.0, ($v - $this->min) / ($this->max - $this->min)));
    }

    /** The value under the pointer: the knob's centre runs from handle/2 to the length less handle/2 (slider.c:244-255). */
    private function valueAt(float $x, float $y): float
    {
        $b = $this->bounds();
        [$length] = $this->axes();
        $travel = $length - $this->handleSize();
        $f = $travel > 0 ? (($this->vertical ? $y - $b->y : $x - $b->x) - $this->handleSize() / 2) / $travel : 0.0;
        $f = $this->vertical && $travel > 0 ? 1 - $f : $f;

        return $this->min + ($this->max - $this->min) * min(1.0, max(0.0, $f));
    }

    /** @return array{float, float} */
    public function knobCentre(): array
    {
        $b = $this->bounds();

        return $this->vertical ? [$b->x + $b->w / 2, $b->y + $this->centreAlong()] : [$b->x + $this->centreAlong(), $b->y + $b->h / 2];
    }

    /** A user's change: posts ValueChanged when it changed (slider.c:40-50). */
    private function userSet(float $v): void
    {
        $v = $this->fit($v);
        // a drag puts the knob under the hand; otherwise it flies (slider.c:204-205)
        $this->dragging ? $this->drawn->snap($v) : $this->drawn->target($v);
        $this->invalidatePaint();
        if ($v === $this->value) {
            return;
        }
        $this->value = $v;
        $this->post(new ValueChanged($this->window(), $this->path(), $this->uuid(), $v));
    }

    /** 120 long, the handle plus 4 or a row thick (slider.c:58-63). */
    protected function measureContent(Size $available): Size
    {
        $thickness = max($this->knobSize() + 4, $this->look()->metric(Metric::RowHeight));

        return $this->vertical ? new Size($thickness, self::WIDTH) : new Size(self::WIDTH, $thickness);
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        if ($e->type === EventType::Enter || $e->type === EventType::Leave) {
            $this->hovered = $e->type === EventType::Enter;
        }
        $this->hoverEvent($e);
        $nudge = $this->step > 0 ? $this->step : ($this->max - $this->min) / 100;
        switch ($e->type) {
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                $this->held = true;
                $this->press->preset = SpringPreset::Snappy;
                $this->press->target(1.0);
                $this->userSet($this->valueAt($e->x, $e->y));

                return true;
            case EventType::MouseMove:
                if (! $this->held) {
                    return false;
                }
                $this->dragging = true;
                $this->userSet($this->valueAt($e->x, $e->y));

                return true;
            case EventType::MouseUp:
                if (! $this->held) {
                    return false;
                }
                [$this->held, $this->dragging] = [false, false];
                // let go, the knob bounces back on springy looks (common.c:192-195)
                $this->press->preset = $this->look()->motion() === Motion::Springy ? SpringPreset::Bouncy : SpringPreset::Snappy;
                $this->press->target(0.0);
                $this->invalidatePaint();

                return true;
            case EventType::KeyDown:
                $to = match ($e->key) {
                    Key::RIGHT, Key::UP => $this->value + $nudge,
                    Key::LEFT, Key::DOWN => $this->value - $nudge,
                    Key::HOME => $this->min,
                    Key::END => $this->max,
                    default => null,
                };
                $to === null || $this->userSet($to);

                return $to !== null;
            default:
                return false;
        }
    }

    public function paint(Painter $p): void
    {
        ($this->look()->part('Slider', 'Body', State::Normal)->extra['paint'] ?? 'raven') === 'parts' ? $this->paintParts($p) : $this->paintRaven($p);
    }

    /** Raven's own: a track 4 thick, the accent up to the value, a disc ringed in AccentText that swells (slider.c:186-222). */
    private function paintRaven(Painter $p): void
    {
        $look = $this->look();
        $handle = $this->handleSize();
        $hot = ($this->hovered || $this->held) && $this->isEnabled();
        $fill = $look->color(Role::Accent);
        $knob = $look->color($hot ? Role::AccentHovered : Role::Accent);
        if (! $this->isEnabled()) {
            $fill = new Color($fill->red, $fill->green, $fill->blue, $fill->alpha * 0.4);
            $knob = $look->color(Role::Handle);
        }
        [$length, $cross] = $this->axes();
        $travel = max(0.0, $length - $handle);
        $centre = $this->centreAlong();
        $glass = $look->material()->isGlass();
        // a glass look's track is 6 thick (slider.c:190)
        $thick = $glass ? 6.0 : 4.0;
        $at = floor(($cross - $thick) / 2);
        $p->rect($this->along($handle / 2, $at, $travel, $thick), $look->color(Role::Track), $thick / 2);
        // a vertical slider fills from the knob down (slider.c:228)
        $p->rect($this->vertical ? $this->along($centre, $at, $handle / 2 + $travel - $centre, $thick) : $this->along($handle / 2, $at, $travel * $this->fraction($this->drawn->value()), $thick), $fill, $thick / 2);
        if ($glass) {
            // a capsule, a lens when held (common.c:209-226)
            $knob = $this->knobSize();
            Knob::paint($p, $look, $this->along($centre - $handle / 2, ($cross - $knob) / 2, $handle, $knob), $this->press->value(), $this->isEnabled());

            return;
        }
        // the knob swells when picked up and bounces when let go
        $d = $handle + 2.5 * $this->press->value() + max(0.0, min(1.0, $this->hover()->value()));
        $p->rect($this->along($centre - $d / 2, ($cross - $d) / 2, $d, $d), $knob, $d / 2, $look->color(Role::AccentText), 2.0);
    }

    /** The platforms': Track, Fill and Thumb parts, eased (slider.c:103-134, 136-184). */
    private function paintParts(Painter $p): void
    {
        $look = $this->look();
        [$hover, $press] = [$this->hover()->value(), $this->press->value()];
        [$length, $cross] = $this->axes();
        $centre = $this->centreAlong();
        $middle = floor($cross / 2);
        $track = $this->easedPart('Slider', 'Track', [], $hover, $press);
        $fill = $this->easedPart('Slider', 'Fill', [], $hover, $press);
        $thumb = $this->easedPart('Slider', 'Thumb', [], $hover, $press);
        [$t, $f] = [(float) ($track->extra['size'] ?? 4.0), (float) ($fill->extra['size'] ?? 4.0)];
        $size = $this->handleSize() - (float) ($thumb->extra['inset'] ?? 0.0);
        $this->part($p, $this->along(0.0, $middle - $t / 2, $length, $t), $track);
        $this->part($p, $this->vertical ? $this->along($centre, $middle - $f / 2, $length - $centre, $f) : $this->along(0.0, $middle - $f / 2, $centre, $f), $fill);
        if ($look->liquid()) {
            // macOS 26: the knob is a white capsule along the track; held, it grows into a lens of clear glass (slider.c:151-168)
            $knob = $this->knobSize();
            $p->pushOpacity((float) ($thumb->extra['opacity'] ?? 1.0));
            Knob::paint($p, $look, $this->along($centre - $this->handleSize() / 2, $middle - $knob / 2, $this->handleSize(), $knob), $press, $this->isEnabled());
            $p->popOpacity();

            return;
        }
        $this->part($p, $this->along($centre - $size / 2, $middle - $size / 2, $size, $size), $thumb);
    }

    /** rvn_paint_part: opacity, a shadow (the engine offsets it blur/4), the box, round as the rect allows (look.c:363-379). */
    private function part(Painter $p, Rect $r, PartLook $l): void
    {
        $radius = min($l->radius, min($r->w, $r->h) / 2);
        $p->pushOpacity((float) ($l->extra['opacity'] ?? 1.0));
        if ($l->shadow > 0 && $l->shadowColor !== null && $l->shadowColor->alpha > 0) {
            $p->shadow($r, $radius, $l->shadow, $l->shadowColor);
        }
        PartBox::paint($p, $r, $l->background, $radius, $l->border, $l->borderWidth, $l->borderTop, $l->borderBottom);
        $p->popOpacity();
    }
}
