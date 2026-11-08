<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Widget;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\ValueChanged;
use Surface\NutsAndBolts\Color;

/**
 * `rating(name, value, max)`: Raven's score in stars (rating.c). The pointer shows the score a click would give
 * (whole stars, or halves with setAllowHalf); a click on the score already given takes it back to 0. The wheel,
 * Left/Down and Right/Up step it, Home and End go to 0 and the most. Each change posts ValueChanged; setValue()
 * posts nothing; a read-only rating takes no input. Stars are filled in Warning (mixed 0.25 to Accent while
 * the pointer shows a score), the empty ones drawn in the text colour at 0.35.
 */
class Rating extends Widget
{
    public const int MAX_STARS = 20;

    public const float GAP = 4.0;

    private bool $allowHalf = false;

    private bool $readOnly = false;

    private ?float $star = null;

    /** The score under the pointer, or -1. */
    private float $hover = -1.0;

    private float $wheelRest = 0.0;

    private bool $hasFocus = false;

    private bool $focusVisible = false;

    public function __construct(string $name, private float $value = 0.0, private int $max = 5)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->max = max(1, min(self::MAX_STARS, $max));
        $this->value = $this->snap($value);
    }

    public function value(): float
    {
        return $this->value;
    }

    /** Posts nothing. */
    public function setValue(float $value): static
    {
        $this->set($value, false);

        return $this;
    }

    public function setMax(int $max): static
    {
        $this->max = max(1, min(self::MAX_STARS, $max));
        $this->value = $this->snap($this->value);

        return $this->invalidateLayout();
    }

    public function setAllowHalf(bool $half): static
    {
        $this->allowHalf = $half;
        $this->value = $this->snap($this->value);

        return $this->invalidatePaint();
    }

    public function setReadOnly(bool $readOnly): static
    {
        $this->readOnly = $readOnly;
        $this->hover = -1.0;

        return $this->invalidatePaint();
    }

    public function setStarSize(?float $size): static
    {
        $this->star = $size === null ? null : max(4.0, $size);

        return $this->invalidateLayout();
    }

    /** The score the pointer shows, or -1. */
    public function hoverValue(): float
    {
        return $this->hover;
    }

    private function starSize(): float
    {
        return $this->star ?? (float) ($this->look()->part('Rating', 'Body', State::Normal)->extra['starSize'] ?? 18.0);
    }

    private function snap(float $v): float
    {
        $v = max(0.0, min((float) $this->max, $v));

        return $this->allowHalf ? round($v * 2) / 2 : round($v);
    }

    /** rating.c:44-48 */
    protected function measureContent(Size $available): Size
    {
        $s = $this->starSize();

        return new Size($s * $this->max + self::GAP * ($this->max - 1) + 4, max($s + 4, $this->look()->metric(Metric::ControlHeight) * 0.8));
    }

    /** The score a click at $x gives: the star under it, whole or half (rating.c:56-67). */
    public function valueAt(float $x): float
    {
        $s = $this->starSize();
        $pitch = $s + self::GAP;
        $at = $x - $this->bounds()->x - 2;
        if ($at < 0) {
            return 0.0;
        }
        $i = min((int) floor($at / $pitch), $this->max - 1);
        $within = ($at - $i * $pitch) / $s;

        return $this->allowHalf && $within < 0.5 ? $i + 0.5 : (float) ($i + 1);
    }

    /** The centre x of star $i. */
    public function starX(int $i): float
    {
        return $this->bounds()->x + 2 + ($this->starSize() + self::GAP) * $i + $this->starSize() / 2;
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        if ($e->type === EventType::FocusGained || $e->type === EventType::FocusLost) {
            $this->hasFocus = $e->type === EventType::FocusGained;
            $this->focusVisible = $this->hasFocus && ($e->focusVisible ?? false);
            $this->invalidatePaint();

            return false;
        }
        if ($this->readOnly) {
            return false;
        }
        $step = $this->allowHalf ? 0.5 : 1.0;
        switch ($e->type) {
            case EventType::MouseMove:
                $v = $this->valueAt($e->x);
                if ($v !== $this->hover) {
                    $this->hover = $v;
                    $this->invalidatePaint();
                }

                return false;
            case EventType::Leave:
                $this->hover = -1.0;
                $this->invalidatePaint();

                return false;
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                $v = $this->valueAt($e->x);
                // the score given again: taken back
                $this->set($v === $this->value ? 0.0 : $v, true);

                return true;
            case EventType::Wheel:
                $this->wheelRest += $e->wheelY;
                $n = (int) $this->wheelRest;
                $this->wheelRest -= $n;
                $n !== 0 && $this->set($this->value + $step * $n, true);

                return true;
            case EventType::KeyDown:
                $to = match ($e->key) {
                    Key::LEFT, Key::DOWN => $this->value - $step,
                    Key::RIGHT, Key::UP => $this->value + $step,
                    Key::HOME => 0.0,
                    Key::END => (float) $this->max,
                    default => null,
                };
                if ($to === null) {
                    return false;
                }
                $this->set($to, true);

                return true;
            default:
                return false;
        }
    }

    /** rating.c:83-107 */
    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $enabled = $this->isEnabled();
        $shown = $this->hover >= 0 && ! $this->readOnly && $enabled ? $this->hover : $this->value;
        $s = $this->starSize();
        $pitch = $s + self::GAP;
        $radius = $s / 2;
        $cy = $b->y + $b->h / 2;
        $on = $this->color(Role::Warning, $look);
        $text = $this->color($enabled ? Role::Text : Role::TextDisabled, $look);
        $off = self::fade($text, 0.35);
        $enabled || $on = self::fade($on, 0.45);
        if ($this->hover >= 0 && ! $this->readOnly) {
            $a = $this->color(Role::Accent, $look);
            $on = new Color($on->red + ($a->red - $on->red) * 0.25, $on->green + ($a->green - $on->green) * 0.25,
                $on->blue + ($a->blue - $on->blue) * 0.25, $on->alpha + ($a->alpha - $on->alpha) * 0.25);
        }
        for ($i = 0; $i < $this->max; $i++) {
            $left = $b->x + 2 + $pitch * $i;
            $cx = $left + $radius;
            $part = max(0.0, min(1.0, $shown - $i));
            $part < 1.0 && $this->star($p, $cx, $cy, $radius, false, $off);
            if ($part > 0.0) {
                // a half star: the part of it on the side the stars start from
                $part < 1.0 && $p->pushClip(new Rect($left, $b->y, $s * $part, $b->h));
                $this->star($p, $cx, $cy, $radius, true, $on);
                $part < 1.0 && $p->popClip();
            }
        }
        if ($this->focusVisible) {
            $p->rect($b->grow(2), new Color(0, 0, 0, 0), 6.0, $this->color(Role::Focus, $look), 2.0);
        }
    }

    /** A star of five points: ten triangles from the centre, or ten lines (rating.c:69-81). */
    private function star(Painter $p, float $cx, float $cy, float $r, bool $fill, Color $c): void
    {
        $pts = [];
        for ($i = 0; $i < 10; $i++) {
            $angle = -M_PI_2 + $i * M_PI / 5;
            $rr = $i % 2 ? $r * 0.42 : $r;
            $pts[] = [$cx + cos($angle) * $rr, $cy + sin($angle) * $rr];
        }
        for ($i = 0; $i < 10; $i++) {
            [$x1, $y1] = $pts[$i];
            [$x2, $y2] = $pts[($i + 1) % 10];
            $fill ? $p->triangle($cx, $cy, $x1, $y1, $x2, $y2, $c) : $p->line($x1, $y1, $x2, $y2, $c, 1.2);
        }
    }

    private function set(float $value, bool $notify): void
    {
        $value = $this->snap($value);
        if ($value === $this->value) {
            return;
        }
        $this->value = $value;
        $this->invalidatePaint();
        $notify && $this->post(new ValueChanged($this->window(), $this->path(), $this->uuid(), $value));
    }

    private static function fade(Color $c, float $a): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $a);
    }
}
