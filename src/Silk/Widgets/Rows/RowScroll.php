<?php

namespace Silk\Widgets\Rows;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Look;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Widgets\ScrollBars;
use Surface\Contracts\HumanInput\MouseButton;

/**
 * Raven's scrollbar for a widget that scrolls itself (rvn_scrollbar_t, common.c:474-700): the offset in whole
 * units within 0..content-viewport; the wheel keeps what a small step leaves for the next one, and pushing past
 * an end kicks the drawn offset (delta x 9, at most 1400 u/s), which a SMOOTH spring brings back; the drawn
 * offset glides SMOOTH to the offset, and follows at once while the thumb is held or nothing scrolls. A click in
 * the track brings the thumb's middle under the pointer, then drags. Bars are drawn by ScrollBars, by variant.
 */
final class RowScroll
{
    public const float KICK = 9.0;

    public const float MAX_KICK = 1400.0;

    public float $offset = 0.0;

    public float $content = 0.0;

    public float $viewport = 0.0;

    public bool $dragging = false;

    private float $grab = 0.0;

    private float $wheelRest = 0.0;

    /** The drawn offset. */
    public readonly Spring $shown;

    /** Overlay bars: shown while something moves or the pointer is near, and widened under it. */
    public readonly Spring $fade;

    public readonly Spring $expand;

    public function __construct()
    {
        $this->shown = new Spring(0.0, SpringPreset::Smooth);
        $this->fade = new Spring(0.0, SpringPreset::Smooth);
        $this->expand = new Spring(0.0, SpringPreset::Smooth);
    }

    public function max(): float
    {
        return max(0.0, $this->content - $this->viewport);
    }

    public function needed(): bool
    {
        return $this->content > $this->viewport + 0.5;
    }

    /** Sets the offset, clamped, on whole units. @return bool whether it changed */
    public function set(float $offset): bool
    {
        $c = min(floor(max(0.0, min($this->max(), $offset)) + 0.5), $this->max());
        if ($c === $this->offset) {
            return false;
        }
        $this->offset = $c;
        $this->shown->target($c);

        return true;
    }

    public function clamp(): bool
    {
        return $this->set($this->offset);
    }

    /** Scrolls by $delta units. @return bool whether it moved, or kept a fraction; false at an end (bubble) */
    public function wheel(float $delta): bool
    {
        if ($delta == 0.0) {
            return false;
        }
        $max = $this->max();
        $atEnd = ($delta < 0 && $this->offset <= 0) || ($delta > 0 && $this->offset >= $max);
        if (! $atEnd) {
            $this->wheelRest * $delta < 0 && $this->wheelRest = 0.0;
            $wanted = $this->wheelRest + $delta;
            $whole = $wanted < 0 ? ceil($wanted) : floor($wanted);
            $this->wheelRest = $wanted - $whole;
            if ($whole == 0.0 || $this->set($this->offset + $whole)) {
                return true;
            }
        }
        $this->wheelRest = 0.0;
        // nowhere to go: the push becomes speed, and the spring brings the content back
        $this->needed() && $this->shown->kick(max(-self::MAX_KICK, min(self::MAX_KICK, $delta * self::KICK)));

        return false;
    }

    /** The offset to draw at. */
    public function drawn(): float
    {
        if ($this->dragging || ! $this->needed()) {
            $this->shown->snap($this->offset);
        }

        return $this->shown->value();
    }

    /** @return list<Spring> */
    public function springs(): array
    {
        return [$this->shown, $this->fade, $this->expand];
    }

    /** Thumb drag and track clicks on $track (rvn_scrollbar_event). @return bool whether the event was the bar's */
    public function event(SilkEvent $e, Rect $track, bool $vertical): bool
    {
        if (! $this->dragging && ! $this->needed()) {
            return false;
        }
        $length = $vertical ? $track->h : $track->w;
        $at = $vertical ? $e->y - $track->y : $e->x - $track->x;
        [$start, $thumb] = ScrollBars::thumb($length, $this->viewport, $this->content, $this->offset);
        $travel = $length - $thumb;
        switch ($e->type) {
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT || ! $track->contains($e->x, $e->y)) {
                    return false;
                }
                if ($at < $start || $at > $start + $thumb) {
                    // a click in the track brings the middle of the thumb under the pointer
                    $travel > 0 && $this->set(($at - $thumb / 2) / $travel * $this->max());
                    [$start] = ScrollBars::thumb($length, $this->viewport, $this->content, $this->offset);
                }
                $this->dragging = true;
                $this->grab = $at - $start;

                return true;
            case EventType::MouseMove:
                if (! $this->dragging) {
                    return false;
                }
                $travel > 0 && $this->set(($at - $this->grab) / $travel * $this->max());

                return true;
            case EventType::MouseUp:
                if (! $this->dragging) {
                    return false;
                }
                $this->dragging = false;

                return true;
            default:
                return false;
        }
    }

    /** The bar on $track; $hovered: the pointer is on the widget; $onTrack: on the track. */
    public function paint(Painter $p, Look $look, Rect $track, bool $vertical, bool $hovered, bool $onTrack): void
    {
        if (! $this->needed()) {
            return;
        }
        $hot = $this->dragging || $onTrack;
        $this->fade->target($hot || $hovered || ! $this->shown->atRest() ? 1.0 : 0.0);
        $this->expand->target($hot ? 1.0 : 0.0);
        [$start, $length] = ScrollBars::drawn($vertical ? $track->h : $track->w, $this->viewport, $this->content, $this->shown->value());
        ScrollBars::paint($p, $look, $track, $vertical, $start, $length, $hot,
            min(1.0, max(0.0, $this->fade->value())), min(1.0, max(0.0, $this->expand->value())));
    }
}
