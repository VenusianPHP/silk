<?php

namespace Silk\Widgets;

use InvalidArgumentException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Silk\Core\Tree\Container;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\SplitMoved;

/**
 * `split(name, horizontal)` as TKSplitView: panes in order with a 5-unit divider between each visible
 * pair; a hidden pane collapses. Positions are fractions of the length. Each pane keeps at least its
 * measured size. A drag posts SplitMoved; setPositions() posts nothing.
 */
class Splitter extends Container
{
    public const float DIVIDER = 5.0;

    /** The divider under the pointer or being dragged. */
    private ?int $hot = null;

    /** @var list<float> */
    private array $positions = [];

    private ?int $grab = null;

    public function __construct(string $name, private readonly bool $horizontal = true)
    {
        parent::__construct($name);
    }

    public function isHorizontal(): bool
    {
        return $this->horizontal;
    }

    /** @return list<float> */
    public function positions(): array
    {
        $n = count($this->visibleChildren());
        if (count($this->positions) !== max(0, $n - 1)) {
            $this->positions = $n < 2 ? [] : array_map(fn ($i) => ($i + 1) / $n, range(0, $n - 2));
        }

        return $this->positions;
    }

    /** One fraction per divider, ascending, each in 0..1. */
    public function setPositions(array $positions): static
    {
        $positions = array_map(floatval(...), array_values($positions));
        if (count($positions) !== max(0, count($this->visibleChildren()) - 1)) {
            throw new InvalidArgumentException("Splitter '{$this->name}' needs one position per divider.");
        }
        $sorted = $positions;
        sort($sorted);
        if ($sorted !== $positions || ($positions !== [] && ($positions[0] < 0 || end($positions) > 1))) {
            throw new InvalidArgumentException("Splitter '{$this->name}': positions ascend in 0..1.");
        }
        $this->positions = $positions;

        return $this->invalidateLayout();
    }

    private function length(): float
    {
        return $this->horizontal ? $this->bounds()->w : $this->bounds()->h;
    }

    private function minOf(WidgetContract $pane): float
    {
        $s = $pane->measure(new Size($this->bounds()->w, $this->bounds()->h));

        return $this->horizontal ? $s->w : $s->h;
    }

    /** [start, end] of pane $i along the axis, relative to the split. */
    private function span(int $i, int $n, float $len): array
    {
        $p = $this->positions();
        $half = self::DIVIDER / 2;

        return [$i === 0 ? 0.0 : $p[$i - 1] * $len + $half, $i === $n - 1 ? $len : $p[$i] * $len - $half];
    }

    public function dividerRect(int $k): Rect
    {
        $b = $this->bounds();
        $at = $this->positions()[$k] * $this->length() - self::DIVIDER / 2;

        return $this->horizontal ? new Rect($b->x + $at, $b->y, self::DIVIDER, $b->h) : new Rect($b->x, $b->y + $at, $b->w, self::DIVIDER);
    }

    protected function measureContent(Size $available): Size
    {
        $main = $cross = 0.0;
        $panes = $this->visibleChildren();
        foreach ($panes as $pane) {
            $s = $pane->measure($available);
            $main += $this->horizontal ? $s->w : $s->h;
            $cross = max($cross, $this->horizontal ? $s->h : $s->w);
        }
        $main += self::DIVIDER * max(0, count($panes) - 1);

        return $this->horizontal ? new Size($main, $cross) : new Size($cross, $main);
    }

    protected function arrangeContent(Rect $r): void
    {
        $panes = $this->visibleChildren();
        $n = count($panes);
        $len = $this->horizontal ? $r->w : $r->h;
        foreach ($panes as $i => $pane) {
            [$a, $z] = $this->span($i, $n, $len);
            $pane->arrange($this->horizontal
                ? new Rect($r->x + $a, $r->y, max(0.0, $z - $a), $r->h)
                : new Rect($r->x, $r->y + $a, $r->w, max(0.0, $z - $a)));
        }
    }

    public function handle(SilkEvent $e): bool
    {
        switch ($e->type) {
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                foreach (array_keys($this->positions()) as $k) {
                    if ($this->dividerRect($k)->contains($e->x, $e->y)) {
                        $this->grab = $k;

                        return true;
                    }
                }

                return false;
            case EventType::MouseMove:
                $hot = $this->grab;
                if ($hot === null) {
                    foreach (array_keys($this->positions()) as $k) {
                        $this->dividerRect($k)->contains($e->x, $e->y) && $hot = $k;
                    }
                }
                if ($hot !== $this->hot) {
                    $this->hot = $hot;
                    $this->invalidatePaint();
                }
                if ($this->grab === null) {
                    return false;
                }
                $this->moveDivider($this->grab, ($this->horizontal ? $e->x - $this->bounds()->x : $e->y - $this->bounds()->y));

                return true;
            case EventType::Leave:
                $this->hot = $this->grab;
                $this->invalidatePaint();

                return false;
            case EventType::MouseUp:
                $was = $this->grab !== null;
                $this->grab = null;

                return $was;
            default:
                return false;
        }
    }

    /** Puts divider $k at $at units along the split, held so both panes keep their measured sizes. */
    private function moveDivider(int $k, float $at): void
    {
        $len = $this->length();
        if ($len <= 0) {
            return;
        }
        $panes = $this->visibleChildren();
        $n = count($panes);
        $half = self::DIVIDER / 2;
        $p = $this->positions();
        $start = $k === 0 ? 0.0 : $p[$k - 1] * $len + $half;
        $end = $k + 1 === $n - 1 ? $len : $p[$k + 1] * $len - $half;
        $lo = ($start + $half + $this->minOf($panes[$k])) / $len;
        $hi = ($end - $half - $this->minOf($panes[$k + 1])) / $len;
        $to = min(max($at / $len, $lo), max($lo, $hi));
        if ($to === $p[$k]) {
            return;
        }
        $p[$k] = $to;
        $this->positions = $p;
        $this->invalidateLayout();
        $this->post(new SplitMoved($this->window(), $this->path(), $this->uuid(), $p));
    }

    protected function paintSelf(Painter $p): void
    {
        $c = $this->look()->color(Role::Separator);
        $glass = $this->look()->material()->isGlass();
        foreach (array_keys($this->positions()) as $k) {
            $d = $this->dividerRect($k);
            if ($glass) {
                // splitter.c:209-217: no line at rest; under the pointer, a short grip in the middle of the handle
                if ($k === $this->hot) {
                    $len = min($this->horizontal ? $d->h : $d->w, 44.0);
                    $p->rect($this->horizontal
                        ? new Rect($d->x + floor(($d->w - 4) / 2), $d->y + ($d->h - $len) / 2, 4.0, $len)
                        : new Rect($d->x + ($d->w - $len) / 2, $d->y + floor(($d->h - 4) / 2), $len, 4.0), $this->look()->color(Role::HandleHovered), 2.0);
                }

                continue;
            }
            $this->horizontal
                ? $p->line($d->x + $d->w / 2, $d->y, $d->x + $d->w / 2, $d->y + $d->h, $c, 1.0)
                : $p->line($d->x, $d->y + $d->h / 2, $d->x + $d->w, $d->y + $d->h / 2, $c, 1.0);
        }
    }
}
