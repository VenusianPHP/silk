<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Widget;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\ValueChanged;
use Surface\NutsAndBolts\Color;

/**
 * `pagination(name, count, page)`: Raven's < 1 ... 4 5 6 ... 20 > (pagination.c). Pages count from 0 and show
 * from 1; the first and the last always show, with `siblings` pages each side of the current one, the gaps
 * written "...". A release over the button pressed, or Left/Right, PageUp/PageDown, Home/End, goes to a page and
 * posts ValueChanged (the page, from 0); setPage() posts nothing.
 */
class Pagination extends Widget
{
    use MeasuresText;

    public const int PREVIOUS = -2;

    public const int NEXT = -3;

    public const int GAP = -4;

    public const int MAX_BUTTONS = 16;

    private int $hot = -1;

    private int $pressed = -1;

    private bool $hasFocus = false;

    public function __construct(string $name, private int $count = 1, private int $page = 0, private int $siblings = 1)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->count = max(1, $count);
        $this->page = max(0, min($this->count - 1, $page));
    }

    public function page(): int
    {
        return $this->page;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** Posts nothing. */
    public function setPage(int $page): static
    {
        $this->go($page, false);

        return $this;
    }

    /** At least 1; the page stays within. Posts nothing. */
    public function setCount(int $count): static
    {
        $this->count = max(1, $count);
        $this->page = min($this->page, $this->count - 1);

        return $this->invalidateLayout();
    }

    /** 0 to 4 pages each side of the current one. */
    public function setSiblings(int $siblings): static
    {
        $this->siblings = max(0, min(4, $siblings));

        return $this->invalidateLayout();
    }

    /** The row: previous, pages and gaps, next (pagination.c:31-48). @return list<int> */
    public function buttons(): array
    {
        $out = [self::PREVIOUS, 0];
        $first = max(1, $this->page - $this->siblings);
        $last = min($this->count - 2, $this->page + $this->siblings);
        // a gap of one page shows that page
        $first === 2 && $first = 1;
        $last === $this->count - 3 && $last = $this->count - 2;
        $first > 1 && $out[] = self::GAP;
        for ($i = $first; $i <= $last && count($out) < self::MAX_BUTTONS - 3; $i++) {
            $out[] = $i;
        }
        $last < $this->count - 2 && $out[] = self::GAP;
        $this->count > 1 && $out[] = $this->count - 1;
        $out[] = self::NEXT;

        return $out;
    }

    /** A page button is as wide as the widest page number + 14, at least square; the others square. */
    private function buttonWidth(int $button): float
    {
        $h = $this->look()->metric(Metric::ControlHeight);

        return $button < 0 ? $h : max($h, ceil($this->textSize((string) max($button + 1, $this->count))->w) + 14);
    }

    protected function measureContent(Size $available): Size
    {
        $w = 0.0;
        foreach ($this->buttons() as $i => $b) {
            $w += $this->buttonWidth($b) + ($i ? 4.0 : 0.0);
        }

        return new Size($w, $this->look()->metric(Metric::ControlHeight));
    }

    /** The rect of the button at $index in the row, 4 apart. */
    public function buttonRect(int $index): Rect
    {
        $b = $this->bounds();
        $buttons = $this->buttons();
        $x = $b->x;
        for ($i = 0; $i < $index; $i++) {
            $x += $this->buttonWidth($buttons[$i]) + 4;
        }

        return new Rect($x, $b->y, $this->buttonWidth($buttons[$index]), $b->h);
    }

    private function buttonAt(float $x, float $y): int
    {
        foreach (array_keys($this->buttons()) as $i) {
            if ($this->buttonRect($i)->contains($x, $y)) {
                return $i;
            }
        }

        return -1;
    }

    /** False for a gap, and for previous / next at the ends. */
    private function usable(int $button): bool
    {
        return match ($button) {
            self::GAP => false,
            self::PREVIOUS => $this->page > 0,
            self::NEXT => $this->page < $this->count - 1,
            default => true,
        };
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        switch ($e->type) {
            case EventType::MouseMove:
                $i = $this->buttonAt($e->x, $e->y);
                if ($i !== $this->hot) {
                    $this->hot = $i;
                    $this->invalidatePaint();
                }

                return false;
            case EventType::Leave:
                $this->hot = -1;
                $this->invalidatePaint();

                return false;
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                $this->pressed = $this->buttonAt($e->x, $e->y);
                $this->invalidatePaint();

                return true;
            case EventType::MouseUp:
                if ($e->button !== MouseButton::LEFT || $this->pressed < 0) {
                    return false;
                }
                $i = $this->pressed;
                $this->pressed = -1;
                $this->invalidatePaint();
                if ($this->buttonAt($e->x, $e->y) === $i) {
                    $button = $this->buttons()[$i];
                    if ($this->usable($button)) {
                        $this->go(match ($button) { self::PREVIOUS => $this->page - 1, self::NEXT => $this->page + 1, default => $button }, true);
                    }
                }

                return true;
            case EventType::FocusGained:
            case EventType::FocusLost:
                $this->hasFocus = $e->type === EventType::FocusGained;
                $this->invalidatePaint();

                return false;
            case EventType::KeyDown:
                $to = match ($e->key) {
                    Key::LEFT, Key::PAGE_UP => $this->page - 1,
                    Key::RIGHT, Key::PAGE_DOWN => $this->page + 1,
                    Key::HOME => 0,
                    Key::END => $this->count - 1,
                    default => null,
                };
                if ($to === null) {
                    return false;
                }
                $this->go($to, true);

                return true;
            default:
                return false;
        }
    }

    /** pagination.c:100-128 */
    public function paint(Painter $p): void
    {
        $look = $this->look();
        $enabled = $this->isEnabled();
        $text = $this->color($enabled ? Role::Text : Role::TextDisabled, $look);
        $style = $this->textStyle();
        foreach ($this->buttons() as $i => $button) {
            $usable = $this->usable($button) && $enabled;
            $r = $this->buttonRect($i);
            $radius = min($look->metric(Metric::CornerRadius) + 2, $r->h / 2);
            $color = $usable ? $text : new Color($text->red, $text->green, $text->blue, $text->alpha * 0.4);
            if ($button === $this->page) {
                $p->rect($r, $this->color(Role::Accent, $look), $radius);
                $color = $this->color(Role::AccentText, $look);
                $this->hasFocus && $p->rect($r->grow(2), new Color(0, 0, 0, 0), $radius + 2, $this->color(Role::Focus, $look), 1.5);
            } elseif ($usable && ($i === $this->hot || $i === $this->pressed)) {
                $p->rect($r, $this->color($i === $this->pressed ? Role::ControlPressed : Role::ControlHovered, $look), $radius);
            }
            [$cx, $cy] = [$r->x + $r->w / 2, $r->y + $r->h / 2];
            if ($button === self::PREVIOUS || $button === self::NEXT) {
                // RVN_ICON_CHEVRON_LEFT / _RIGHT, 7 units
                $d = $button === self::PREVIOUS ? -1 : 1;
                $s = 3.5;
                $w = max(1.0, $look->metric(Metric::BorderWidth) * 1.5);
                $p->line($cx - $d * $s / 2, $cy - $s, $cx + $d * $s / 2, $cy, $color, $w)->line($cx + $d * $s / 2, $cy, $cx - $d * $s / 2, $cy + $s, $color, $w);
            } else {
                $label = $button === self::GAP ? '...' : (string) ($button + 1);
                $p->text($label, $cx - $this->textSize($label, $style)->w / 2, $this->baselineIn($r, $style), $color, $style);
            }
        }
    }

    private function go(int $page, bool $notify): void
    {
        $page = max(0, min($this->count - 1, $page));
        if ($page === $this->page) {
            return;
        }
        [$this->page, $this->hot] = [$page, -1];
        // the row may have other buttons now
        $this->invalidateLayout();
        $notify && $this->post(new ValueChanged($this->window(), $this->path(), $this->uuid(), (float) $page));
    }
}
