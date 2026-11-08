<?php

namespace Silk\Widgets;

use LogicException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Widget\Slot;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Container;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\ScrollPhase;
use Surface\Contracts\Windows\Mail\View\ViewScrolled;
use Surface\Contracts\Windows\Styling\ScrollPosition;
use Surface\NutsAndBolts\Color;

/**
 * `scrollView(name)` as TKScrollView: scrolls exactly one child (spec 7.3). A wheel line is 48 units,
 * Shift swaps axes; the target offset is in whole units and the drawn offset chases it on SMOOTH.
 * Past an end the wheel kicks the drawn spring (delta x 9, at most 1400 u/s) and bubbles to an outer
 * scroll area. Its bars are Raven's (ScrollBars, scroll_box.c:57-115): a visible bar takes its thickness from
 * the other axis; the Raven look shows a track, the overlay looks fade on SMOOTH when nothing moves and the
 * pointer is away, and widen under it. Dragging a thumb is instant. User scrolls post ViewScrolled;
 * scrollTo() posts nothing.
 */
class ScrollBox extends Container
{
    public const float LINE = 48.0;

    public const float WHEEL_KICK = 9.0;

    public const float MAX_KICK = 1400.0;

    /** Content is measured this long on a scrolled axis (unbounded). */
    private const float UNBOUNDED = 1e9;

    private bool $horizontalBar = true;

    private bool $verticalBar = true;

    /** @var array{float, float} */
    private array $target = [0.0, 0.0];

    private Spring $x;

    private Spring $y;

    private Spring $bars;

    /** The overlay bars widen under the pointer (Raven bar->expand). */
    private Spring $expand;

    /** The axis (0 x, 1 y) whose track the pointer is on, or null. */
    private ?int $onBar = null;

    private bool $hovered = false;

    /** This momentum run has bounced at an end already. */
    private bool $bounced = false;

    /** @var array{float, float} the offset last painted */
    private array $painted = [0.0, 0.0];

    /** The axis (0 x, 1 y) whose thumb is held, and where on it. @var array{int, float}|null */
    private ?array $grab = null;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->x = new Spring(0.0, SpringPreset::Smooth);
        $this->y = new Spring(0.0, SpringPreset::Smooth);
        $this->bars = new Spring(0.0, SpringPreset::Smooth);
        $this->expand = new Spring(0.0, SpringPreset::Smooth);
    }

    /** Exactly one child, as TKScrollView. */
    public function add(WidgetContract $child, ?Slot $slot = null): static
    {
        if ($this->children() !== []) {
            throw new LogicException("ScrollBox '{$this->name}' scrolls exactly one child.");
        }

        return parent::add($child, $slot);
    }

    public function content(): ?WidgetContract
    {
        return $this->children()[0] ?? null;
    }

    public function setScrollbars(bool $horizontal, bool $vertical): static
    {
        [$this->horizontalBar, $this->verticalBar] = [$horizontal, $vertical];

        return $this->invalidatePaint();
    }

    /** The content is drawn shifted by the drawn offset; hit testing and events map back through it. */
    public function childShift(): array
    {
        return [-$this->x->value(), -$this->y->value()];
    }

    /** @return array{float, float} where it is heading */
    public function target(): array
    {
        return $this->target;
    }

    /** @return array{float, float} the drawn offset */
    public function offset(): array
    {
        return [$this->x->value(), $this->y->value()];
    }

    /** @return array{bool, bool} whether each axis has anything to scroll */
    public function scrollable(): array
    {
        [$mx, $my] = $this->maxOffset();

        return [$mx > 0, $my > 0];
    }

    /** Programmatic: posts nothing. */
    public function scrollTo(float $x, float $y, bool $animate = true): static
    {
        [$mx, $my] = $this->maxOffset();
        $this->target = [round(min(max($x, 0), $mx)), round(min(max($y, 0), $my))];
        if ($animate) {
            $this->x->target($this->target[0]);
            $this->y->target($this->target[1]);
        } else {
            $this->x->snap($this->target[0]);
            $this->y->snap($this->target[1]);
        }

        return $this->invalidatePaint();
    }

    public function springs(): array
    {
        return [$this->x, $this->y, $this->bars, $this->expand];
    }

    /** Repaints when the drawn offset moved (a translate, no relayout); fades the bars once still and the pointer is away. */
    public function settleMotion(): void
    {
        parent::settleMotion();
        if ($this->offset() !== $this->painted) {
            $this->painted = $this->offset();
            $this->invalidatePaint();
        }
        if ($this->x->atRest() && $this->y->atRest() && ! $this->hovered && $this->grab === null) {
            $this->bars->target(0.0);
        }
    }

    /** @return array{float, float} */
    private function contentSize(Size $viewport): Size
    {
        $c = $this->content();
        if ($c === null) {
            return new Size(0, 0);
        }
        $s = $c->measure(new Size(
            $this->horizontalBar ? self::UNBOUNDED : $viewport->w,
            $this->verticalBar ? self::UNBOUNDED : $viewport->h,
        ));

        return new Size(max($s->w, $viewport->w), max($s->h, $viewport->h));
    }

    /**
     * The view of a box this size: a visible bar takes its thickness from the other axis (scroll_box.c:66-72).
     * @return array{Size, Size, bool, bool} viewport, content, horizontal bar shown, vertical bar shown
     */
    private function view(Size $box): array
    {
        $bar = $this->look()->metric(Metric::ScrollbarSize);
        [$w, $h] = [$box->w, $box->h];
        $c = $this->contentSize(new Size($w, $h));
        $vertical = $this->verticalBar && $c->h > $h + 0.5;
        $vertical && $w -= $bar;
        $c = $this->contentSize(new Size($w, $h));
        $horizontal = $this->horizontalBar && $c->w > $w + 0.5;
        if ($horizontal) {
            $h -= $bar;
            if (! $vertical && $this->verticalBar && $c->h > $h + 0.5) {
                [$vertical, $w] = [true, $w - $bar];
            }
        }
        [$w, $h] = [max(0.0, $w), max(0.0, $h)];

        return [new Size($w, $h), $this->contentSize(new Size($w, $h)), $horizontal, $vertical];
    }

    /** @return array{float, float} */
    private function maxOffset(): array
    {
        $b = $this->bounds();
        [$v, $c] = $this->view(new Size($b->w, $b->h));

        return [max(0.0, $c->w - $v->w), max(0.0, $c->h - $v->h)];
    }

    protected function measureContent(Size $available): Size
    {
        $c = $this->content()?->measure(new Size($available->w, $available->h)) ?? new Size(0, 0);

        return new Size(min($c->w, $available->w), min($c->h, $available->h));
    }

    protected function arrangeContent(Rect $r): void
    {
        $c = $this->content();
        if ($c === null) {
            return;
        }
        [$v, $size] = $this->view(new Size($r->w, $r->h));
        // a smaller viewport keeps the target in range
        [$mx, $my] = [max(0.0, $size->w - $v->w), max(0.0, $size->h - $v->h)];
        $this->target = [min($this->target[0], $mx), min($this->target[1], $my)];
        // laid out once at the top-left; the offset is a translate at paint time (childShift)
        $c->arrange(new Rect($r->x, $r->y, $size->w, $size->h));
    }

    public function handle(SilkEvent $e): bool
    {
        switch ($e->type) {
            case EventType::Enter:
            case EventType::Leave:
                $this->hovered = $e->type === EventType::Enter;
                $this->hovered && $this->bars->target(1.0);
                $this->hovered || $this->hotAxis(-INF, -INF);

                return false;
            case EventType::Wheel:
                [$dx, $dy] = $e->modifiers->shift ? [$e->wheelY, $e->wheelX] : [$e->wheelX, $e->wheelY];
                if ($e->phase === ScrollPhase::BEGAN || $e->phase === ScrollPhase::MOMENTUM_ENDED) {
                    $this->bounced = false;
                }

                // CanvasWheeled: positive scrolls toward the content's right/bottom, the system's direction already applied.
                // macOS sends momentum as more wheel events (measured): they scroll like any other. Flick is for sources without it.
                return $this->scrollBy($dx * self::LINE, $dy * self::LINE, $e->phase ?? ScrollPhase::NONE);
            case EventType::MouseDown:
                return $e->button === MouseButton::LEFT && $this->grabThumb($e->x, $e->y);
            case EventType::MouseMove:
                $this->hotAxis($e->x, $e->y);

                return $this->grab !== null && $this->dragThumb($e->x, $e->y);
            case EventType::MouseUp:
                $was = $this->grab !== null;
                $this->grab = null;
                $this->hotAxis($e->x, $e->y);

                return $was;
            default:
                return false;
        }
    }

    /**
     * @return bool whether the wheel was this box's: it scrolled, or it is momentum spent at an end (kept from
     *              the parent). False (bubble) at an end otherwise, or with nothing to scroll.
     */
    private function scrollBy(float $dx, float $dy, ScrollPhase $phase = ScrollPhase::NONE): bool
    {
        [$mx, $my] = $this->maxOffset();
        $moved = $spent = false;
        foreach ([[0, $dx, $mx, $this->x], [1, $dy, $my, $this->y]] as [$axis, $d, $max, $spring]) {
            if ($d == 0.0 || $max <= 0) {
                continue;
            }
            $to = round(min(max($this->target[$axis] + $d, 0), $max));
            if ($to !== $this->target[$axis]) {
                $this->target[$axis] = $to;
                $spring->target($to);
                $moved = true;
            } elseif ($phase === ScrollPhase::MOMENTUM) {
                // one bounce per momentum run, however many events still arrive
                $this->bounced || $spring->kick(max(-self::MAX_KICK, min(self::MAX_KICK, $d * self::WHEEL_KICK)));
                $this->bounced = true;
                $spent = true;
            } elseif ($phase !== ScrollPhase::BEGAN && $phase !== ScrollPhase::CHANGED) {
                // a wheel notch past an end; no rubber band while the fingers drag (spec 7.3)
                $spring->kick(max(-self::MAX_KICK, min(self::MAX_KICK, $d * self::WHEEL_KICK)));
            }
        }
        $this->bars->target(1.0);
        $this->invalidatePaint();
        if ($moved) {
            $this->scrolled();
        }

        return $moved || $spent;
    }

    private function scrolled(): void
    {
        $b = $this->bounds();
        $c = $this->contentSize(new Size($b->w, $b->h));
        $this->post(new ViewScrolled($this->window(), $this->path(), $this->uuid(),
            ScrollPosition::of($this->target[0], $b->w, $c->w, $this->target[1], $b->h, $c->h)));
    }

    /** [axis, rect of its track, thumb length, thumb start] for each shown bar (scroll_box.c:105-116, common.c:560-575). @return list<array{int, Rect, float, float}> */
    private function barGeometry(): array
    {
        $b = $this->bounds();
        $bar = $this->look()->metric(Metric::ScrollbarSize);
        [$v, $c, $horizontal, $vertical] = $this->view(new Size($b->w, $b->h));
        $out = [];
        if ($vertical) {
            $track = new Rect($b->x + $b->w - $bar, $b->y, $bar, $b->h - ($horizontal ? $bar : 0.0));
            [$start, $len] = ScrollBars::drawn($track->h, $v->h, $c->h, $this->y->value());
            $out[] = [1, $track, $len, $track->y + $start];
        }
        if ($horizontal) {
            $track = new Rect($b->x, $b->y + $b->h - $bar, $b->w - ($vertical ? $bar : 0.0), $bar);
            [$start, $len] = ScrollBars::drawn($track->w, $v->w, $c->w, $this->x->value());
            $out[] = [0, $track, $len, $track->x + $start];
        }

        return $out;
    }

    /** Which bar's track the pointer is on; the overlay bars show and widen under it. */
    private function hotAxis(float $x, float $y): void
    {
        $on = null;
        foreach ($this->barGeometry() as [$axis, $track]) {
            $track->contains($x, $y) && $on = $axis;
        }
        if ($on !== $this->onBar) {
            $this->onBar = $on;
            $this->invalidatePaint();
        }
        $this->expand->target($on !== null || $this->grab !== null ? 1.0 : 0.0);
    }

    private function grabThumb(float $x, float $y): bool
    {
        foreach ($this->barGeometry() as [$axis, $track, $len, $start]) {
            if ($track->contains($x, $y)) {
                $at = $axis === 1 ? $y : $x;
                $this->grab = [$axis, $at >= $start && $at <= $start + $len ? $at - $start : $len / 2];

                return $this->dragThumb($x, $y);
            }
        }

        return false;
    }

    /** Dragging a thumb is instant: no spring. */
    private function dragThumb(float $x, float $y): bool
    {
        [$axis, $hold] = $this->grab;
        foreach ($this->barGeometry() as [$a, $track, $len]) {
            if ($a !== $axis) {
                continue;
            }
            [$start, $span] = $axis === 1 ? [$track->y, $track->h] : [$track->x, $track->w];
            $fraction = (($axis === 1 ? $y : $x) - $hold - $start) / max(1.0, $span - $len);
            $max = $this->maxOffset()[$axis];
            $to = round(min(max($fraction, 0), 1) * $max);
            if ($to !== $this->target[$axis]) {
                $this->target[$axis] = $to;
                ($axis === 1 ? $this->y : $this->x)->snap($to);
                $this->invalidatePaint();
                $this->scrolled();
            }
        }
        $this->bars->target(1.0);

        return true;
    }

    public function paint(Painter $p): void
    {
        [$dx, $dy] = $this->childShift();
        $p->pushClip($this->bounds())->pushTransform(1.0, $dx, $dy);
        $paint = function () use ($p) {
            foreach ($this->children() as $child) {
                $child->isVisible() && $child->render($p);
            }
        };
        ($tree = $this->tree()) === null ? $paint() : $tree->shifted($dx, $dy, $paint);
        $p->popTransform()->popClip();

        $look = $this->look();
        $visible = min(1.0, max(0.0, $this->bars->value()));
        $wide = min(1.0, max(0.0, $this->expand->value()));
        foreach ($this->barGeometry() as [$axis, $track, $len, $start]) {
            $hot = ($this->grab !== null && $this->grab[0] === $axis) || $this->onBar === $axis;
            ScrollBars::paint($p, $look, $track, $axis === 1, $start - ($axis === 1 ? $track->y : $track->x), $len, $hot, $visible, $wide);
        }
    }
}
