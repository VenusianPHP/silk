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
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\NutsAndBolts\Color;

/**
 * `breadcrumb(name, items)`: Raven's path to the current place, Home > Documents > Report (breadcrumb.c). The
 * items before the last are links, the last is where the user is; when they do not fit, the first ones give
 * their place to "...", which stands for the last of them. A release over the link pressed, or Enter/Space on
 * the one the keyboard is on (Left and Right move it), posts SelectionChanged (its index and text).
 */
class Breadcrumb extends Widget
{
    use MeasuresText;

    public const float GAP = 22.0;

    /** @var list<string> */
    private array $items;

    /** The first item shown; those before it fold into "...". */
    private int $first = 0;

    private int $hot = -1;

    private int $pressed = -1;

    private int $cursor = -1;

    private bool $hasFocus = false;

    /** @param list<string> $items */
    public function __construct(string $name, array $items = [])
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->items = array_values($items);
    }

    /** @return list<string> */
    public function items(): array
    {
        return $this->items;
    }

    /** @param list<string> $items */
    public function setItems(array $items): static
    {
        $this->items = array_values($items);
        [$this->hot, $this->pressed, $this->cursor] = [-1, -1, -1];

        return $this->invalidateLayout();
    }

    public function addItem(string $text): int
    {
        $this->items[] = $text;
        $this->invalidateLayout();

        return count($this->items) - 1;
    }

    /** Keeps the first $count items (Rbreadcrumb_truncate). */
    public function truncate(int $count): static
    {
        $this->items = array_slice($this->items, 0, max(0, $count));
        [$this->hot, $this->pressed] = [-1, -1];
        $this->cursor >= count($this->items) - 1 && $this->cursor = count($this->items) - 2;

        return $this->invalidateLayout();
    }

    /** The first item shown (those before it are folded into "..."). */
    public function firstShown(): int
    {
        return $this->first;
    }

    /** breadcrumb.c:66-69 */
    private function itemWidth(string $text): float
    {
        return ceil($this->textSize($text)->w) + 12;
    }

    protected function measureContent(Size $available): Size
    {
        $w = 0.0;
        foreach ($this->items as $i => $t) {
            $w += $this->itemWidth($t) + ($i ? self::GAP : 0.0);
        }

        return new Size(min($available->w, $w), $this->look()->metric(Metric::ControlHeight));
    }

    /** The first item that fits with all those after it; the last always shows (breadcrumb.c:77-92). */
    protected function arrangeContent(Rect $r): void
    {
        $ellipsis = $this->itemWidth('...') + self::GAP;
        [$this->first, $width] = [0, 0.0];
        for ($i = count($this->items) - 1; $i >= 0; $i--) {
            $item = $this->itemWidth($this->items[$i]) + ($i ? self::GAP : 0.0);
            // a cut needs room for the "..."
            if ($i < count($this->items) - 1 && $width + $item + ($i > 0 ? $ellipsis : 0.0) > $r->w + 0.5) {
                $this->first = $i + 1;
                break;
            }
            $width += $item;
        }
    }

    /** Where item $i is drawn; the folded ones have the rect of the "..." (breadcrumb.c:94-111). */
    public function itemRect(int $i): Rect
    {
        $b = $this->bounds();
        $x = $b->x;
        if ($this->first > 0) {
            $w = $this->itemWidth('...');
            if ($i < $this->first) {
                return new Rect($b->x, $b->y, $w, $b->h);
            }
            $x += $w + self::GAP;
        }
        for ($k = $this->first; $k < count($this->items); $k++) {
            $w = $this->itemWidth($this->items[$k]);
            $k === count($this->items) - 1 && $w = min($w, max(0.0, $b->x + $b->w - $x));
            if ($k === $i) {
                return new Rect($x, $b->y, $w, $b->h);
            }
            $x += $w + self::GAP;
        }

        return new Rect($b->x, $b->y, 0, 0);
    }

    /** The item under a point: the "..." stands for the last folded item. */
    public function itemAt(float $x, float $y): int
    {
        if (! $this->bounds()->contains($x, $y)) {
            return -1;
        }
        if ($this->first > 0 && $this->itemRect(0)->contains($x, $y)) {
            return $this->first - 1;
        }
        for ($k = $this->first; $k < count($this->items); $k++) {
            if ($this->itemRect($k)->contains($x, $y)) {
                return $k;
            }
        }

        return -1;
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $links = count($this->items) - 1;
        switch ($e->type) {
            case EventType::MouseMove:
                $i = $this->itemAt($e->x, $e->y);
                $i >= $links && $i = -1;
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
                $i = $this->itemAt($e->x, $e->y);
                if ($e->button !== MouseButton::LEFT || $i < 0 || $i >= $links) {
                    return false;
                }
                $this->pressed = $i;
                $this->invalidatePaint();

                return true;
            case EventType::MouseUp:
                if ($e->button !== MouseButton::LEFT || $this->pressed < 0) {
                    return false;
                }
                $i = $this->pressed;
                $this->pressed = -1;
                $this->invalidatePaint();
                $this->itemAt($e->x, $e->y) === $i && $this->click($i);

                return true;
            case EventType::FocusGained:
                $this->hasFocus = true;
                ($this->cursor < 0 || $this->cursor >= $links) && $this->cursor = $links - 1;
                $this->invalidatePaint();

                return false;
            case EventType::FocusLost:
                $this->hasFocus = false;
                $this->invalidatePaint();

                return false;
            case EventType::KeyDown:
                if ($links <= 0) {
                    return false;
                }
                if ($e->key === Key::LEFT || $e->key === Key::RIGHT) {
                    $this->cursor = max(0, min($links - 1, $this->cursor + ($e->key === Key::LEFT ? -1 : 1)));
                    $this->invalidatePaint();

                    return true;
                }
                if ($e->key === Key::ENTER || $e->key === Key::NUMPAD_ENTER || $e->key === Key::SPACE) {
                    $this->click($this->cursor);

                    return true;
                }

                return false;
            default:
                return false;
        }
    }

    /** breadcrumb.c:124-149 */
    public function paint(Painter $p): void
    {
        $look = $this->look();
        $enabled = $this->isEnabled();
        $text = $this->color($enabled ? Role::Text : Role::TextDisabled, $look);
        $link = $enabled ? $this->color(Role::Link, $look) : $text;
        $dim = new Color($text->red, $text->green, $text->blue, $text->alpha * 0.55);
        $style = $this->textStyle();
        $count = count($this->items);
        for ($i = $this->first > 0 ? -1 : 0; $i < $count; $i++) {
            $index = $i < 0 ? $this->first - 1 : $i;
            $r = $this->itemRect($i < 0 ? 0 : $i);
            $last = $i === $count - 1;
            if (! $last && $enabled && ($this->hot === $index || $this->pressed === $index || ($this->hasFocus && $this->cursor === $index))) {
                $p->rect(new Rect($r->x, $r->y + 3, $r->w, $r->h - 6), $this->color($this->pressed === $index ? Role::ControlPressed : Role::RowHovered, $look), 6.0);
            }
            $p->pushClip($r);
            $label = $i < 0 ? '...' : $this->items[$i];
            $p->text($label, $r->x + 6, $this->baselineIn($r, $style), $last ? $text : $link, $style);
            $p->popClip();
            if (! $last) {
                // a 5-unit chevron in the middle of the gap
                [$cx, $cy, $s] = [$r->x + $r->w + self::GAP / 2, $r->y + $r->h / 2, 2.5];
                $w = max(1.0, $look->metric(Metric::BorderWidth) * 1.5);
                $p->line($cx - $s / 2, $cy - $s, $cx + $s / 2, $cy, $dim, $w)->line($cx + $s / 2, $cy, $cx - $s / 2, $cy + $s, $dim, $w);
            }
        }
    }

    private function click(int $i): void
    {
        if ($i < 0 || $i >= count($this->items) - 1) {
            return;
        }
        $this->post(new SelectionChanged($this->window(), $this->path(), $this->uuid(), $i, $this->items[$i]));
    }
}
